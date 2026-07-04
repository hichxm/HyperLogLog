# HyperLogLog for PHP

![Latest Stable Version](https://img.shields.io/packagist/v/hichxm/hyperloglog?label=version)
![Packagist Downloads](https://img.shields.io/packagist/dt/hichxm/hyperloglog?label=packagist+downloads&color=brightgreen)

A lightweight and dependency-free PHP implementation of the **HyperLogLog** probabilistic data structure for
**approximate cardinality estimation**.

HyperLogLog allows you to estimate the number of **distinct elements** in very large datasets while using only a small,
fixed amount of memory.

View on Packagist: [hichxm/hyperloglog](https://packagist.org/packages/hichxm/hyperloglog)

## Features

* 🚀 Fast approximate distinct counting
* 📦 Zero dependencies
* 🔧 Configurable number of registers (`counterBits`)
* 🔐 Configurable hashing algorithm (`xxh3`, `sha256`, `md5`, etc.)
* 📊 Theoretical error rate calculation
* 🧮 Small and large cardinality bias corrections
* ✅ Strict types and fully documented source code

## When to Use HyperLogLog

HyperLogLog is well suited for:

* Counting unique visitors
* Counting unique IP addresses
* Analytics pipelines
* Large log processing
* Stream processing
* Database statistics
* Big data applications

It is **not** appropriate when an exact distinct count is required.

## Requirements

- **PHP 7.0, 7.1, 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5**
- No external dependencies

> **Note:** The `xxh3` and `xxh128` hash algorithms are available only when supported by your PHP version and build. If
> unavailable, you can use any other algorithm returned by `hash_algos()`, such as `sha256`, `sha512`, or `md5`.

## Installation

Install via Composer:

```bash
composer require hichxm/hyperloglog
```

## Basic Usage

```php
<?php

use Hichxm\HyperLogLog\HyperLogLog;

$hll = new HyperLogLog();

$hll->add('apple');
$hll->add('banana');
$hll->add('orange');
$hll->add('apple'); // duplicate

echo $hll->count();
```

The returned value is an **approximation** of the number of unique elements.

## Constructor

```php
new HyperLogLog(
    int $counterBits = 5,
    string $hashAlgorithm = 'xxh3'
);
```

### Parameters

| Parameter       | Description                                                                          |
|-----------------|--------------------------------------------------------------------------------------|
| `counterBits`   | Number of bits used to select registers. The number of registers is `2^counterBits`. |
| `hashAlgorithm` | Any hashing algorithm supported by PHP's `hash()` function.                          |

> Increasing `counterBits` improves accuracy while increasing memory usage.

Example:

```php
$hll = new HyperLogLog(
    counterBits: 10,
    hashAlgorithm: 'sha256'
);
```

## Accuracy

The theoretical standard error is:

```
1.04 / √m
```

where:

```
m = 2^counterBits
```

Example:

```php
$error = $hll->theoreticalErrorRate($hll->getM());
```

## Supported Hash Algorithms

Any algorithm supported by PHP can be used.

Examples include:

* `xxh3` *(recommended when available)*
* `xxh128`
* `sha256`
* `sha512`
* `md5`
* `sha1`

You can list available algorithms using:

```php
print_r(hash_algos());
```

## Public API

### Add an element

```php
$hll->add('user-123');
```

### Estimate the number of distinct values

```php
$count = $hll->count();
```

### Calculate the theoretical error

```php
$error = $hll->theoreticalErrorRate($hll->getM());
```

### Measure estimation error

```php
$error = $hll->measureError($estimated, $actual);
```

## References

* https://en.wikipedia.org/wiki/HyperLogLog

## License

MIT
