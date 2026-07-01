<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hichxm\HyperLogLog\HyperLogLog;

foreach (hash_algos() as $hash_algo) {
    $start = microtime(true);
    $hyperLogLog = new HyperLogLog(12, $hash_algo);

    for ($i = 1; $i < 100_000; ++$i) {
        $hyperLogLog->add('test ' . $i);
    }

    echo 'ALGO : ' . $hash_algo . PHP_EOL;
    echo $hyperLogLog->count() . PHP_EOL;
    $end = microtime(true);
    echo 'TIME : ' . ($end - $start) . PHP_EOL;
    echo PHP_EOL;
}