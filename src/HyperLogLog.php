<?php

declare(strict_types=1);

namespace Hichxm\HyperLogLog;

/**
 * HyperLogLog implementation for approximate cardinality estimation.
 *
 * This probabilistic data structure estimates the number of distinct elements
 * in a multiset using limited memory. It is based on stochastic averaging
 * and uses hashing to distribute values across counters.
 *
 * @see https://en.wikipedia.org/wiki/HyperLogLog
 */
class HyperLogLog
{
    /** @var int */
    public const TWO_POW_32 = 4294967296;

    /** @var int */
    private $counterBits;

    /** @var string */
    private $hashAlgorithm;

    /** @var int */
    private $m;

    /** @var array<int, int> Counters storing maximum rho values */
    private $counters;

    /** @var bool Indicates if the selected hash algorithm produces less than 64 bits */
    private $needsPadding;

    /**
     * HyperLogLog constructor.
     *
     * @param int    $counterBits   Number of bits used to define the number of counters (m = 2^counterBits).
     *                              Higher values improve accuracy but increase memory usage.
     * @param string $hashAlgorithm Hash algorithm used for input hashing (e.g. xxh3, murmur3f, crc32, sha256).
     */
    public function __construct(int $counterBits = 5, string $hashAlgorithm = 'xxh3')
    {
        if (!in_array($hashAlgorithm, hash_algos())) {
            throw new \InvalidArgumentException('Invalid hash algorithm');
        }

        $this->counterBits = $counterBits;
        $this->hashAlgorithm = $hashAlgorithm;

        $this->m = 1 << $this->counterBits;

        $this->counters = array_fill(0, $this->m, 0);

        $testHash = hash($this->hashAlgorithm, 'test', true);
        $this->needsPadding = strlen($testHash) < 8;
    }

    public function getCounterBits(): int
    {
        return $this->counterBits;
    }

    public function getHashAlgorithm(): string
    {
        return $this->hashAlgorithm;
    }

    public function getM(): int
    {
        return $this->m;
    }

    /**
     * @return array<int, int>
     */
    public function getCounters(): array
    {
        return $this->counters;
    }

    /**
     * @param array<int, int> $counters
     *
     * @return $this
     */
    public function setCounters(array $counters): self
    {
        $this->counters = $counters;

        return $this;
    }

    /**
     * Add an element to the HyperLogLog structure.
     *
     * The value is hashed, converted to a 64-bit integer, split into register index
     * and leading zero count, and the register is updated with the maximum observed rho value.
     *
     * @param string $value element to insert
     *
     * @return void
     */
    public function add(string $value)
    {
        $hashString = $this->hash($value, $this->hashAlgorithm);

        // Comblement avec des octets nuls si le hachage fait moins de 64 bits (ex: crc32)
        if ($this->needsPadding) {
            $hashString = str_pad($hashString, 8, "\x00", STR_PAD_RIGHT);
        }

        // Extraction des 8 premiers octets (64 bits) sous forme d'entier (Big Endian)
        // unpack('J') ignore nativement tout ce qui dépasse 8 octets (ex: sha256, md5)
        $hashIntUnpacked = unpack('J', $hashString);

        if (false === $hashIntUnpacked) {
            throw new \InvalidArgumentException('Invalid hash value');
        }

        /** @var int $hashInt */
        $hashInt = $hashIntUnpacked[1];

        $counter = $this->counter($hashInt, $this->counterBits);

        $rho = $this->rho($hashInt, $this->counterBits);

        if ($rho > $this->counters[$counter]) {
            $this->counters[$counter] = $rho;
        }
    }

    /**
     * Estimates the number of distinct elements added.
     *
     * Uses HyperLogLog bias correction rules:
     * - Small range correction (linear counting)
     * - Raw estimate
     * - Large range correction
     *
     * @return float estimated cardinality
     */
    public function count(): float
    {
        $Z = 0.0;
        $V = 0;

        foreach ($this->counters as $counter) {
            $Z += 2 ** (-$counter);

            if (0 === $counter) {
                ++$V;
            }
        }

        $E = $this->estimate($this->m, $Z);

        // Small range correction
        if ($V > 0 && $E <= (2.5 * $this->m)) {
            $E = $this->estimateUsingSmallCardinalitiesApproach($V, $this->m);
        }

        // Large range correction
        if ($E > $this::TWO_POW_32 / 30) {
            $E = $this->estimateUsingLargeCardinalitiesApproach($E);
        }

        return $E;
    }

    /**
     * Merges another HyperLogLog instance into the current one.
     *
     * @param self $other The other HyperLogLog instance to merge
     *
     * @return $this
     *
     * @throws \InvalidArgumentException if the configurations do not match
     */
    public function merge(self $other): self
    {
        if ($this->m !== $other->getM() || $this->hashAlgorithm !== $other->getHashAlgorithm()) {
            throw new \InvalidArgumentException('Cannot merge HyperLogLog instances with different counter bits or hash algorithms.');
        }

        $this->counters = array_map(
            static function (int $counter, int $otherCounter): int {
                return max($counter, $otherCounter);
            },
            $this->counters,
            $other->counters
        );

        return $this;
    }

    /**
     * Calculate theoretical error rate.
     *
     * @param int $m number of counters
     *
     * @return float theoretical error rate
     */
    public function theoreticalErrorRate(int $m): float
    {
        if ($m < 1) {
            throw new \InvalidArgumentException('Invalid number of counters, $m must be greater than 0');
        }

        return 1.04 / sqrt($m);
    }

    public function measureError(int $estimate, int $real): int
    {
        return $estimate - $real;
    }

    /**
     * Returns the bias correction constant alpha(m).
     *
     * @param int $m number of counters
     *
     * @return float correction constant
     */
    public function alpha(int $m): float
    {
        if ($m < 1) {
            throw new \InvalidArgumentException('Invalid number of counters, $m must be greater than 0');
        }

        switch ($m) {
            case 2:
                return 0.46852874309841;

            case 4:
                return 0.56806457964166;

            case 8:
                return 0.63557660535301;

            case 16:
                return 0.673;

            case 32:
                return 0.697;

            case 64:
                return 0.709;

            case 128:
                return 0.71527049326382;

            case 256:
                return 0.71827259324955;

            default:
                return 0.7213 / (1 + 1.079 / $m);
        }
    }

    /**
     * Computes the raw HyperLogLog cardinality estimate.
     *
     * @param int   $m number of counters
     * @param float $Z harmonic mean of register values
     *
     * @return float raw estimate
     */
    public function estimate(int $m, float $Z): float
    {
        if ($Z <= 0) {
            throw new \InvalidArgumentException('Invalid harmonic mean, $Z must be positive');
        }

        return $this->alpha($m) * $m * $m / $Z;
    }

    /**
     * Linear counting estimator used for small cardinalities.
     *
     * @param int $V number of empty counters
     * @param int $m number of counters
     *
     * @return float estimated cardinality
     */
    public function estimateUsingLinearCounting(int $V, int $m): float
    {
        return $m * log($m / $V);
    }

    /**
     * Small cardinality correction.
     *
     * Delegates to linear counting.
     *
     * @param int $V number of empty counters
     * @param int $m number of counters
     *
     * @return float corrected estimate
     */
    public function estimateUsingSmallCardinalitiesApproach(int $V, int $m): float
    {
        return $this->estimateUsingLinearCounting($V, $m);
    }

    /**
     * Large cardinality correction.
     *
     * Applies log-based correction to avoid saturation bias.
     *
     * @param float $E raw estimate
     *
     * @return float corrected estimate
     */
    public function estimateUsingLargeCardinalitiesApproach(float $E): float
    {
        return -$this::TWO_POW_32 * log(1 - $E / $this::TWO_POW_32);
    }

    /**
     * Hashes a value using the selected hashing algorithm.
     *
     * @param string $value         input value
     * @param string $hashAlgorithm hash algorithm name
     *
     * @return string binary hash output
     */
    private function hash(string $value, string $hashAlgorithm): string
    {
        return hash($hashAlgorithm, $value, true);
    }

    /**
     * Extracts the register index from the 64-bit integer hash using bitwise shift.
     *
     * @param int $hash        64-bit integer hash
     * @param int $counterBits number of bits used for indexing
     *
     * @return int register index
     */
    private function counter(int $hash, int $counterBits): int
    {
        $shift = 64 - $counterBits;
        $mask = (1 << $counterBits) - 1;

        return ($hash >> $shift) & $mask;
    }

    /**
     * Computes the position of the first set bit (rho function) using bitwise shifts.
     *
     * Counts leading zeros starting after the register bits.
     *
     * @param int $hash        64-bit integer hash
     * @param int $counterBits number of bits reserved for register selection
     *
     * @return int position of first 1-bit (rho value)
     */
    private function rho(int $hash, int $counterBits): int
    {
        $rho = 1;
        $maxBitsToCheck = 64 - $counterBits;

        for ($i = 0; $i < $maxBitsToCheck; ++$i) {
            $bitPos = 63 - $counterBits - $i;

            if (($hash >> $bitPos) & 1) {
                return $rho;
            }

            ++$rho;
        }

        return $rho;
    }
}
