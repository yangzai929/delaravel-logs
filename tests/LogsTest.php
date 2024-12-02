<?php

namespace tests;
require_once __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;

class LogsTest extends TestCase
{

    public function testLog()
    {
        \DelaravelLog\Dlog\Logs::logInfo('test', 'test');
    }
}