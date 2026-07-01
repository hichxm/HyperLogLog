<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hichxm\HyperLogLog\HyperLogLog;

$minCounterBits = 4;
$maxCounterBits = 16;
$maxNumberOfElements = 1_000_000;

//foreach (hash_algos() as $hash_algo) {
foreach (['md5'] as $hash_algo) {
    for ($counterBits = $minCounterBits; $counterBits <= $maxCounterBits; ++$counterBits) {
        unlink($file = __DIR__ . '/data/' . $hash_algo . '_' . $counterBits . '.csv');

        file_put_contents($file, <<<CSV
        hash_algo,counter_bits,iteration,estimate,theoretical_error,measured_error,time
        CSV. PHP_EOL);

        for ($i = 1; $i <= $maxNumberOfElements; ++$i) {
            $iteration = $i;

            $start = microtime(true);
            $hyperLogLog = new HyperLogLog($counterBits, $hash_algo);

            for ($j = 1; $j < $iteration; ++$j) {
                $hyperLogLog->add('test ' . $j);
            }

            $estimate = $hyperLogLog->count();

            $end = microtime(true);

            $theoreticalError = $hyperLogLog->theoreticalErrorRate();
            $measuredError = $hyperLogLog->measureError($estimate, $iteration);
            $time = $end - $start;

            printf("Algo              : %s\n", $hash_algo);
            printf("Counter bits      : %d\n", $counterBits);
            printf("Actual            : %d\n", $iteration);
            printf("Estimate          : %.0f\n", $estimate);
            printf("Theoretical error : %.3f %% (+- %.0f)\n", $theoreticalError, $iteration * $theoreticalError);
            printf("Measured error    : %.3f %% (%.0f)\n", abs($measuredError) * 100 / $iteration, $measuredError);
            printf("Duration          : %.3fs\n\n", $time);

            file_put_contents($file, <<<CSV
            {$hash_algo},{$counterBits},{$iteration},{$estimate},{$theoreticalError},{$measuredError},{$time}
            CSV. PHP_EOL, FILE_APPEND);
        }
    }
}