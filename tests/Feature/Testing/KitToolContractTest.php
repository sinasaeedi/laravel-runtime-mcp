<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use SinaSaeedi\RuntimeMcp\Testing\RuntimeMcp;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\OrderLookupTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing\DecimalMaximumTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing\IntegerMaximumTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing\MismatchTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing\NumericMaximumTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing\StrictIntegerMaximumTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing\TextMaximumTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom\ErpOrderTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;

/*
 * 01-39 Part A, 01-18's contract made public: RuntimeMcp::assertToolContract() builds a team's tool by class name and
 * runs the checks of src/Testing/ToolContract.php with no plugin (a custom tool has no prefix, 01-29).
 */

it('TKC1 passes for custom tools that keep the contract', function (string $class) {
    // No expect(): the kit's own counted assertion keeps this test from being "risky".
    RuntimeMcp::assertToolContract($class);
})->with([EchoTool::class, ErpOrderTool::class, IntegerMaximumTool::class, NumericMaximumTool::class, DecimalMaximumTool::class, StrictIntegerMaximumTool::class]);

it('TKC2 fails for a tool whose schema and rules disagree', function () {
    expect(fn () => RuntimeMcp::assertToolContract(MismatchTool::class))
        ->toThrow(AssertionFailedError::class, 'Tool [mismatch_fixture] breaks the tool contract: the schema limits [text] to 10, but its rules say max:20.');
});

it('TKC3 fails for a schema maximum whose key has no integer, numeric or decimal rule', function () {
    expect(fn () => RuntimeMcp::assertToolContract(TextMaximumTool::class))
        ->toThrow(AssertionFailedError::class, 'Tool [maximum_fixture] breaks the tool contract: the schema limits [limit] with maximum 100, but its rules have no integer, numeric or decimal rule, so max: compares the length of the text.');
});

it('TKC4 refuses a class that is not a RuntimeTool: a wrapped tool, a missing class, any other class', function (string $class) {
    expect(fn () => RuntimeMcp::assertToolContract($class))
        ->toThrow(AssertionFailedError::class, "[{$class}] is not a RuntimeTool. A wrapped tool keeps its own validation; test it with call().");
})->with([OrderLookupTool::class, 'App\RuntimeMcp\Tools\Missing', stdClass::class]);
