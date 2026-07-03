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
        $hashAlgorithm = PHP_VERSION >= 8100 ? 'xxh3' : 'sha256';

        $hll = new HyperLogLog(hashAlgorithm: $hashAlgorithm);

        // Check if the default counter bits and hash algorithms are set
        $this->assertSame(5, $hll->getCounterBits());
        $this->assertSame($hashAlgorithm, $hll->getHashAlgorithm());

        // m should be 2^5 = 32
        $this->assertSame(32, $hll->getM());

        // The counters array should be initialized with 32 elements set to 0
        $this->assertCount(32, $hll->getCounters());
    }

    public function testConstructorSetsCustomValues(): void
    {
        $hll = new HyperLogLog(10, 'sha256');

        $this->assertSame(10, $hll->getCounterBits());
        $this->assertSame('sha256', $hll->getHashAlgorithm());

        // m should be 2^10 = 1024
        $this->assertSame(1024, $hll->getM());
        $this->assertCount(1024, $hll->getCounters());
    }

    public function testConstructorFailsWithInvalidHashAlgorithm(): void
    {
        // In PHP 8+, the hash() function throws a ValueError if the provided algorithm does not exist.
        $this->expectException(\InvalidArgumentException::class);
        new HyperLogLog(10, 'invalid_algo_123');
    }

    public function testGettersAndSetters(): void
    {
        $hashAlgorithm = PHP_VERSION >= 8100 ? 'xxh3' : 'sha256';

        $hll = new HyperLogLog(hashAlgorithm: $hashAlgorithm);

        // Create a mock state to inject into the instance
        $mockCounters = array_fill(0, 32, 1);
        $hll->setCounters($mockCounters);

        // Verify that the state was correctly updated
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
        // We assert it falls within a reasonable probabilistic window (~5% error margin).
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

        // Adding the exact same string 1000 times should result in a total count of ~1
        $this->assertEqualsWithDelta(1.0, $estimate, 1.0);
    }

    public function testAddEmptyString(): void
    {
        $hll = new HyperLogLog(10, 'sha256');
        $hll->add('');

        // An empty string is a valid element that should be processed and count as 1.
        $this->assertEqualsWithDelta(1.0, $hll->count(), 1.0);
    }

    public function testAddWithShortHashAlgorithmTriggersPaddingCorrectly(): void
    {
        // crc32 produces a 32-bit (4 bytes) hash.
        // This must trigger the internal right-padding with null bytes so unpack('J') doesn't fail.
        $hll = new HyperLogLog(10, 'crc32');

        $hll->add('short_hash_test_1');
        $hll->add('short_hash_test_2');

        $estimate = $hll->count();
        $this->assertGreaterThan(1.0, $estimate);
    }

    public function testCountTriggersSmallRangeCorrectionInternalBranch(): void
    {
        $hll = new HyperLogLog(12, 'sha256'); // m = 4096

        // By inserting only a few elements (e.g., 10), we ensure there are many empty registers ($V > 0)
        // and that the raw estimate $E is <= (2.5 * 4096).
        for ($i = 0; $i < 10; ++$i) {
            $hll->add('small_range_item_'.$i);
        }

        $estimate = $hll->count();

        // Verify that the estimate remains consistent thanks to the linear counting correction.
        $this->assertGreaterThan(5.0, $estimate);
        $this->assertLessThan(15.0, $estimate);
    }

    public function testCountTriggersLargeRangeCorrectionInternalBranch(): void
    {
        $hll = new HyperLogLog(10, 'sha256'); // m = 1024

        // We use a simulated rho of 20 across all registers.
        // The raw estimate $E will be approximately 773 million.
        // This is well above the 143 million threshold (2^32 / 30) for large range correction,
        // while remaining safely below the absolute 4.29 billion limit (2^32) to avoid logarithmic NAN errors.
        $mockCounters = array_fill(0, 1024, 20);
        $hll->setCounters($mockCounters);

        $estimate = $hll->count();
        $twoPow32 = 4_294_967_296;

        // Verify that the value is a valid float and not a mathematically undefined result (NAN).
        $this->assertFalse(is_nan($estimate), 'The estimate must not be NAN.');

        // Assert that the large range correction activated successfully and scaled the result appropriately.
        $this->assertGreaterThan($twoPow32 / 30, $estimate);
    }

    public function testTheoreticalErrorRateCalculation(): void
    {
        $hashAlgorithm = PHP_VERSION >= 8100 ? 'xxh3' : 'sha256';

        $hll = new HyperLogLog(hashAlgorithm: $hashAlgorithm);

        // Theoretical error rate formula: 1.04 / sqrt(m)
        // 1.04 / sqrt(16) = 1.04 / 4 = 0.26
        $this->assertEqualsWithDelta(0.26, $hll->theoreticalErrorRate(16), 0.001);
    }

    public function testTheoreticalErrorRateThrowsExceptionForInvalidM(): void
    {
        $hashAlgorithm = PHP_VERSION >= 8100 ? 'xxh3' : 'sha256';

        $hll = new HyperLogLog(hashAlgorithm: $hashAlgorithm);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid number of counters, $m must be greater than 0');

        $hll->theoreticalErrorRate(0);
    }

    public function testMeasureError(): void
    {
        $hashAlgorithm = PHP_VERSION >= 8100 ? 'xxh3' : 'sha256';

        $hll = new HyperLogLog(hashAlgorithm: $hashAlgorithm);

        // measureError simply returns the difference: estimate - real
        $this->assertSame(5, $hll->measureError(15, 10));
        $this->assertSame(-2, $hll->measureError(8, 10));
    }

    public function testAlphaCalculationValues(): void
    {
        $hashAlgorithm = PHP_VERSION >= 8100 ? 'xxh3' : 'sha256';

        $hll = new HyperLogLog(hashAlgorithm: $hashAlgorithm);

        // Test exact predefined constants for m=2 and m=16
        $this->assertEqualsWithDelta(0.46852874309841, $hll->alpha(2), 0.0000001);
        $this->assertEqualsWithDelta(0.673, $hll->alpha(16), 0.001);

        // Test the default fallback calculation for m >= 128 (e.g., 512)
        $expectedLargeAlpha = 0.7213 / (1 + 1.079 / 512);
        $this->assertEqualsWithDelta($expectedLargeAlpha, $hll->alpha(512), 0.001);
    }

    public function testAlphaThrowsExceptionForInvalidM(): void
    {
        $hashAlgorithm = PHP_VERSION >= 8100 ? 'xxh3' : 'sha256';

        $hll = new HyperLogLog(hashAlgorithm: $hashAlgorithm);

        $this->expectException(\InvalidArgumentException::class);
        $hll->alpha(0);
    }

    public function testEstimateThrowsExceptionForInvalidZ(): void
    {
        $hashAlgorithm = PHP_VERSION >= 8100 ? 'xxh3' : 'sha256';

        $hll = new HyperLogLog(hashAlgorithm: $hashAlgorithm);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid harmonic mean, $Z must be positive');

        // $Z (Harmonic mean) cannot be zero or negative
        $hll->estimate(16, 0.0);
    }

    public function testEstimateUsingLinearCounting(): void
    {
        $hashAlgorithm = PHP_VERSION >= 8100 ? 'xxh3' : 'sha256';

        $hll = new HyperLogLog(hashAlgorithm: $hashAlgorithm);
        $m = 64;
        $v = 16; // 16 empty counters out of 64

        // Linear Counting formula for small cardinalities: m * log(m / V)
        $expected = 64 * log(64 / 16); // 64 * log(4) ≈ 88.72

        $this->assertEqualsWithDelta($expected, $hll->estimateUsingLinearCounting($v, $m), 0.001);
        $this->assertEqualsWithDelta($expected, $hll->estimateUsingSmallCardinalitiesApproach($v, $m), 0.001);
    }

    public function testMergeThrowsExceptionForMismatchedCounterBits(): void
    {
        $hll1 = new HyperLogLog(10, 'sha256');
        $hll2 = new HyperLogLog(12, 'sha256');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot merge HyperLogLog instances with different counter bits or hash algorithms.');

        $hll1->merge($hll2);
    }

    public function testMergeThrowsExceptionForMismatchedHashAlgorithms(): void
    {
        $hll1 = new HyperLogLog(10, 'sha256');
        $hll2 = new HyperLogLog(10, 'xxh3');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot merge HyperLogLog instances with different counter bits or hash algorithms.');

        $hll1->merge($hll2);
    }

    public function testMergeUpdatesCountersWithMaxValues(): void
    {
        $hll1 = new HyperLogLog(5, 'sha256'); // m = 32
        $hll2 = new HyperLogLog(5, 'sha256'); // m = 32

        // Create initial states
        $counters1 = array_fill(0, 32, 1);
        $counters1[0] = 5;
        $counters1[1] = 2;
        $counters1[2] = 0;

        $counters2 = array_fill(0, 32, 0);
        $counters2[0] = 3;
        $counters2[1] = 8;
        $counters2[2] = 4;

        $hll1->setCounters($counters1);
        $hll2->setCounters($counters2);

        // Perform merge
        $result = $hll1->merge($hll2);

        // Expected result should be max(counters1[i], counters2[i])
        $expected = array_fill(0, 32, 1);
        $expected[0] = 5; // max(5, 3)
        $expected[1] = 8; // max(2, 8)
        $expected[2] = 4; // max(0, 4)

        $this->assertSame($expected, $hll1->getCounters());

        // Assert it returns itself to allow method chaining
        $this->assertSame($hll1, $result);
    }

    public function testMergeEstimatesUnionOfTwoSetsCorrectly(): void
    {
        $hll1 = new HyperLogLog(12, 'sha256');
        $hll2 = new HyperLogLog(12, 'sha256');

        // Add 2000 unique items to HLL1
        for ($i = 0; $i < 2000; ++$i) {
            $hll1->add('set_a_'.$i);
        }

        // Add 3000 unique items to HLL2
        for ($i = 0; $i < 3000; ++$i) {
            $hll2->add('set_b_'.$i);
        }

        // Merge HLL2 into HLL1
        $hll1->merge($hll2);

        $estimate = $hll1->count();

        // The merged HyperLogLog should estimate a cardinality of roughly 5000
        $this->assertGreaterThan(4500, $estimate);
        $this->assertLessThan(5500, $estimate);
    }

    public function testEstimateUsingLargeCardinalitiesApproach(): void
    {
        $hashAlgorithm = PHP_VERSION >= 8100 ? 'xxh3' : 'sha256';

        $hll = new HyperLogLog(hashAlgorithm: $hashAlgorithm);

        $rawEstimate = 3_000_000_000;
        $twoPow32 = 4_294_967_296;

        // Large Cardinality formula to correct hash collision bias near 2^32:
        // -2^32 * log(1 - E / 2^32)
        $expected = -$twoPow32 * log(1 - $rawEstimate / $twoPow32);

        $this->assertEqualsWithDelta(
            $expected,
            $hll->estimateUsingLargeCardinalitiesApproach($rawEstimate),
            0.001
        );
    }
}
