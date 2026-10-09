#!/usr/bin/env php
<?php

declare(strict_types=1);

// Composer bin proxies define this when installed as a dependency.
$autoload = $_composer_autoload_path ?? dirname(__DIR__).'/vendor/autoload.php';
require $autoload;

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $result = (new Survos\PeriodicalGrouping\GroupingEngine())->prepare($input);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
