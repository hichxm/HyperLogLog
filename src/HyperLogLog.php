<?php

namespace Hichxm\HyperLogLog;

class HyperLogLog
{
    private int $_precalculatedTwoPow32 = 4_294_967_296;
    private int $counterBits;

    private string $hashAlgorithm;

    private int $m;

    private array $counters;

    public function __construct(int $counterBits = 5, string $hashAlgorithm = 'xxh3')
    {
        $this->counterBits = $counterBits;
        $this->hashAlgorithm = $hashAlgorithm;

        $this->m = 1 << $this->counterBits;

        $this->counters = array_fill(0, $this->m, 0);
    }

    public function add(string $value): void
    {
        $hashAlgorithm = $this->hashAlgorithm;
        $counterBits = $this->counterBits;

        $hash = $this->hash($value, $hashAlgorithm);

        $counter = $this->counter($hash, $counterBits);

        $rho = $this->rho($hash, $counterBits);

        if ($rho > $this->counters[$counter]) {
            $this->counters[$counter] = $rho;
        }
    }

    public function count(): int
    {
        $Z = 0;
        $V = 0;

        foreach ($this->counters as $counter) {
            $Z += 2 ** (-$counter);

            if ($counter === 0) {
                ++$V;
            }
        }

        $E = $this->estimate($this->m, $Z);

        if ($V > 0 && $E <= (2.5 * $this->m)) {
            $E = $this->estimateUsingSmallCardinalitiesApproach($V, $this->m);
        }

        if ($E > $this->_precalculatedTwoPow32 / 30) {
            $E = $this->estimateUsingLargeCardinalitiesApproach($E);
        }

        return $E;
    }

    private function hash(string $value, string $hash): string
    {
        return hash($hash, $value, true);
    }

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

    public function alpha(int $m): float
    {
        return match ($m) {
            2 => 0.46852874309841,
            4 => 0.56806457964166,
            8 => 0.63557660535301,
            16 => 0.67573042918204,
            32 => 0.69777200036277,
            64 => 0.70934095483950,
            128 => 0.71527049326382,
            256 => 0.71827259324955,
            default => 0.7213 / (1 + 1.079 / $m),
        };
    }

    public function estimate(int $m, float $Z): int
    {
        return $this->alpha($m) * $m * $m / $Z;
    }

    public function estimateUsingLinearCounting(int $V, int $m): float
    {
        return $m * log($m / $V);
    }

    public function estimateUsingSmallCardinalitiesApproach(int $V, int $m): float
    {
        return $this->estimateUsingLinearCounting($V, $m);
    }

    public function estimateUsingLargeCardinalitiesApproach(int $E): float
    {
        return -$this->_precalculatedTwoPow32 * log(1 - $E / $this->_precalculatedTwoPow32);
    }
}