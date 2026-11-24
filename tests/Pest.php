<?php

use Keepsuit\RedisPrefixFlush\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

dataset('redis', [
    'phpredis single' => ['phpredis', 'single'],
    'phpredis cluster' => ['phpredis', 'cluster'],
    'predis single' => ['predis', 'single'],
    'predis cluster' => ['predis', 'cluster'],
]);
