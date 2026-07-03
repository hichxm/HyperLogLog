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
    private int $_precalculatedTwoPow32 = 4_294_967_296;
    private int $counterBits;

    private string $hashAlgorithm;

    private int $m;

    /** @var array<int, int> Counters storing maximum rho values */
    private array $counters;

    /**
     * HyperLogLog constructor.
     *
     * @param int    $counterBits   Number of bits used to define the number of counters (m = 2^counterBits).
     *                              Higher values improve accuracy but increase memory usage.
     * @param string $hashAlgorithm Hash algorithm used for input hashing (e.g. xxh3, murmur3f, sha256).
     */
    public function __construct(int $counterBits = 5, string $hashAlgorithm = 'xxh3')
    {
        $this->counterBits = $counterBits;
        $this->hashAlgorithm = $hashAlgorithm;

        $this->m = 1 << $this->counterBits;

        $this->counters = array_fill(0, $this->m, 0);
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
    public function setCounters(array $counters): HyperLogLog
    {
        $this->counters = $counters;

        return $this;
    }

    /**
     * Add an element to the HyperLogLog structure.
     *
     * The value is hashed, split into register index and leading zero count,
     * and the register is updated with the maximum observed rho value.
     *
     * @param string $value element to insert
     */
    public function add(string $value): void
    {
        $hash = $this->hash($value, $this->hashAlgorithm);

        $counter = $this->counter($hash, $this->counterBits);

        $rho = $this->rho($hash, $this->counterBits);

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
        if ($E > $this->_precalculatedTwoPow32 / 30) {
            $E = $this->estimateUsingLargeCardinalitiesApproach($E);
        }

        return $E;
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

        return match ($m) {
            2 => 0.46852874309841,
            4 => 0.56806457964166,
            8 => 0.63557660535301,
            16 => 0.673,
            32 => 0.697,
            64 => 0.709,
            128 => 0.71527049326382,
            256 => 0.71827259324955,
            default => 0.7213 / (1 + 1.079 / $m),
        };
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
        return -$this->_precalculatedTwoPow32 * log(1 - $E / $this->_precalculatedTwoPow32);
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
     * Extracts the register index from the hash.
     *
     * The first `counterBits` bits of the hash determine the register.
     *
     * @param string $hash        binary hash
     * @param int    $counterBits number of bits used for indexing
     *
     * @return int register index
     */
    private function counter(string $hash, int $counterBits): int
    {
        $counter = 0;

        for ($bit = 0; $bit < $counterBits; ++$bit) {
            $byte = ord($hash[intdiv($bit, 8)]);

            $counter = ($counter << 1)
                | (($byte >> (7 - ($bit % 8))) & 1);
        }

        return $counter;
    }

    /**
     * Computes the position of the first set bit (rho function).
     *
     * Counts leading zeros starting after the register bits.
     *
     * @param string $hash        binary hash
     * @param int    $counterBits number of bits reserved for register selection
     *
     * @return int position of first 1-bit (rho value)
     */
    private function rho(string $hash, int $counterBits): int
    {
        $bitLength = strlen($hash) * 8;

        $rho = 1;

        for ($bit = $counterBits; $bit < $bitLength; ++$bit) {
            $byte = ord($hash[intdiv($bit, 8)]);

            if (($byte >> (7 - ($bit % 8))) & 1) {
                return $rho;
            }

            ++$rho;
        }

        return $rho;
    }
}
