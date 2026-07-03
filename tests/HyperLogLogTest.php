<?php

declare(strict_types=1);

namespace Hichxm\HyperLogLog\Tests;

use Hichxm\HyperLogLog\HyperLogLog;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Hichxm\HyperLogLog\HyperLogLog
 *
 * @internal
 */
class HyperLogLogTest extends TestCase
{
    public function testConstructorSetsDefaultValues(): void
    {
        $hll = new HyperLogLog();

        $this->assertSame(5, $hll->getCounterBits());
        $this->assertSame('xxh3', $hll->getHashAlgorithm());
        $this->assertSame(32, $hll->getM()); // 1 << 5 = 32
        $this->assertCount(32, $hll->getCounters());
    }

    public function testConstructorSetsCustomValues(): void
    {
        $hll = new HyperLogLog(10, 'sha256');

        $this->assertSame(10, $hll->getCounterBits());
        $this->assertSame('sha256', $hll->getHashAlgorithm());
        $this->assertSame(1024, $hll->getM()); // 1 << 10 = 1024
        $this->assertCount(1024, $hll->getCounters());
    }

    public function testGettersAndSetters(): void
    {
        $hll = new HyperLogLog();

        $hll->setCounterBits(8);
        $this->assertSame(8, $hll->getCounterBits());

        $hll->setHashAlgorithm('md5');
        $this->assertSame('md5', $hll->getHashAlgorithm());

        $hll->setM(256);
        $this->assertSame(256, $hll->getM());

        $mockCounters = array_fill(0, 256, 1);
        $hll->setCounters($mockCounters);
        $this->assertSame($mockCounters, $hll->getCounters());
    }

    public function testAddAndCountUniqueElements(): void
    {
        // Use sha256 to ensure universal test execution across different PHP environments
        $hll = new HyperLogLog(12, 'sha256');

        for ($i = 0; $i < 5000; ++$i) {
            $hll->add('unique_item_'.$i);
        }

        $estimate = $hll->count();

        // With m=4096 (12 bits), the estimate should be highly accurate.
        // We assert it falls within a reasonable probabilistic window (~5% error).
        $this->assertGreaterThan(4500, $estimate);
        $this->assertLessThan(5500, $estimate);
    }

    public function testAddDuplicateElementsMaintainsCardinality(): void
    {
        $hll = new HyperLogLog(10, 'sha256');

        for ($i = 0; $i < 1000; ++$i) {
            $hll->add('duplicate_string');
        }

        $estimate = $hll->count();

        // Adding the exact same string 1000 times should result in a count of ~1
        $this->assertEqualsWithDelta(1.0, $estimate, 1.0);
    }

    public function testTheoreticalErrorRateCalculation(): void
    {
        $hll = new HyperLogLog();

        // 1.04 / sqrt(16) = 1.04 / 4 = 0.26
        $this->assertEqualsWithDelta(0.26, $hll->theoreticalErrorRate(16), 0.001);
    }

    public function testTheoreticalErrorRateThrowsExceptionForInvalidM(): void
    {
        $hll = new HyperLogLog();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid number of counters, $m must be greater than 0');

        $hll->theoreticalErrorRate(0);
    }

    public function testMeasureError(): void
    {
        $hll = new HyperLogLog();

        $this->assertSame(5, $hll->measureError(15, 10));
        $this->assertSame(-2, $hll->measureError(8, 10));
    }

    public function testAlphaCalculationValues(): void
    {
        $hll = new HyperLogLog();

        $this->assertEqualsWithDelta(0.46852874309841, $hll->alpha(2), 0.0000001);
        $this->assertEqualsWithDelta(0.673, $hll->alpha(16), 0.001);

        // Test default case calculation
        $expectedLargeAlpha = 0.7213 / (1 + 1.079 / 512);
        $this->assertEqualsWithDelta($expectedLargeAlpha, $hll->alpha(512), 0.001);
    }

    public function testAlphaThrowsExceptionForInvalidM(): void
    {
        $hll = new HyperLogLog();

        $this->expectException(\InvalidArgumentException::class);
        $hll->alpha(0);
    }

    public function testEstimateThrowsExceptionForInvalidZ(): void
    {
        $hll = new HyperLogLog();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid harmonic mean, $Z must be positive');

        $hll->estimate(16, 0.0);
    }

    public function testEstimateUsingLinearCounting(): void
    {
        $hll = new HyperLogLog();
        $m = 64;
        $v = 16; // 16 empty counters

        // Linear Counting formula: m * log(m / V)
        $expected = 64 * log(64 / 16); // 64 * log(4) ≈ 88.72

        $this->assertEqualsWithDelta($expected, $hll->estimateUsingLinearCounting($v, $m), 0.001);
        $this->assertEqualsWithDelta($expected, $hll->estimateUsingSmallCardinalitiesApproach($v, $m), 0.001);
    }

    public function testEstimateUsingLargeCardinalitiesApproach(): void
    {
        $hll = new HyperLogLog();
        $rawEstimate = 3_000_000_000;
        $twoPow32 = 4_294_967_296;

        // Large Cardinality formula: -2^32 * log(1 - E / 2^32)
        $expected = -$twoPow32 * log(1 - $rawEstimate / $twoPow32);

        $this->assertEqualsWithDelta(
            $expected,
            $hll->estimateUsingLargeCardinalitiesApproach($rawEstimate),
            0.001
        );
    }
}
