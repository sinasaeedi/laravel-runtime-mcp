<?php

declare(strict_types=1);

use Pest\TestSuite;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Integration');
pest()->group('pgsql')->in('Integration/Pgsql');
pest()->group('mysql')->in('Integration/Mysql');

/**
 * The running test case, typed. Pest types $this in a test closure as TestCall, so PHPStan (level
 * max) cannot see TestCase methods through $this. Call currentTest()->bootWith(...) instead.
 */
function currentTest(): TestCase
{
    $test = TestSuite::getInstance()->test;
    assert($test instanceof TestCase);

    return $test;
}
