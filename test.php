<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hichxm\HyperLogLog\HyperLogLog;

//foreach (hash_algos() as $hash_algo) {
foreach (['md5'] as $hash_algo) {
    for ($i = 1; $i < 1_000; ++$i) {
        $iteration = $i;

        $start = microtime(true);
        $hyperLogLog = new HyperLogLog(12, $hash_algo);

        for ($j = 1; $j < $iteration; ++$j) {
            $hyperLogLog->add('test ' . $j);
        }

        $estimate = $hyperLogLog->count();

        $end = microtime(true);

        $theoreticalError = $hyperLogLog->theoreticalErrorRate();
        $measuredError = $hyperLogLog->measureError($estimate, $iteration);

        printf("Algo              : %s\n", $hash_algo);
        printf("Actual            : %d\n", $iteration);
        printf("Estimate          : %.0f\n", $estimate);
        printf("Theoretical error : %.3f %% (+- %.0f)\n", $theoreticalError, $iteration * $theoreticalError);
        printf("Measured error    : %.3f %% (%.0f)\n", abs($measuredError) * 100 / $iteration, $measuredError);
        printf("Duration          : %.3fs\n\n", $end - $start);
    }
}