<?php

declare(strict_types=1);

use Pest\TestSuite;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Tests\Support\ToolContract;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;

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

/**
 * The tool contract (01-18), for every tool story. $plugin is null for a custom tool (01-29). Here and
 * not in tests/Support/: autoload-dev is PSR-4 only, so a free function there never loads.
 */
function assertToolContract(RuntimeTool $tool, ?Plugin $plugin = null): void
{
    ToolContract::assert($tool, $plugin);
}
