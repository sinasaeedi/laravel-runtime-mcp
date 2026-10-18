<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tools\Status;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/*
 * Rule 11 and principles 1.4.7, 1.4.8: the named constructors make a silent empty result, an
 * invented count and a note on an ok result impossible. Each broken rule is a LogicException.
 */

/** @param  Closure(): mixed  $build */
function toolResultError(Closure $build): ?string
{
    try {
        $build();
    } catch (LogicException $e) {
        return $e->getMessage();
    }

    return null;
}

it('builds an ok result with no note and no truncation', function () {
    $result = ToolResult::ok(['rows' => [[1]]], 1);

    expect($result->status)->toBe(Status::Ok)
        ->and($result->data)->toBe(['rows' => [[1]]])
        ->and($result->note)->toBeNull()
        ->and($result->truncated)->toBeFalse()
        ->and($result->rowCount)->toBe(1)
        ->and(ToolResult::ok([])->rowCount)->toBeNull();
});

it('builds an empty result with its note, its data and a row count of 0', function () {
    $result = ToolResult::empty('No match in laravel.log.', ['columns' => ['id']]);

    expect($result->status)->toBe(Status::Empty)
        ->and($result->note)->toBe('No match in laravel.log.')
        ->and($result->data)->toBe(['columns' => ['id']])
        ->and($result->rowCount)->toBe(0)
        ->and(ToolResult::empty('Nothing.')->data)->toBe([]);
});

it('builds a partial result with a count, or with false when the amount is not known', function () {
    $counted = ToolResult::partial(['rows' => []], 'The last 3 rows were omitted.', 3, 7);
    $unknown = ToolResult::partial(['rows' => []], 'More than 500 rows match.');

    expect($counted->status)->toBe(Status::Partial)
        ->and($counted->truncated)->toBe(3)
        ->and($counted->rowCount)->toBe(7)
        ->and($unknown->truncated)->toBeFalse()
        ->and($unknown->rowCount)->toBeNull();
});

it('builds an unavailable result with null data', function () {
    $result = ToolResult::unavailable('Channel `stderr` has no file.');

    expect($result->status)->toBe(Status::Unavailable)
        ->and($result->data)->toBeNull()
        ->and($result->note)->toBe('Channel `stderr` has no file.')
        ->and($result->rowCount)->toBeNull();
});

it('refuses a result that is not ok without a note', function (string $case) {
    $build = match ($case) {
        'empty, no note' => fn () => ToolResult::empty(''),
        'empty, blank note' => fn () => ToolResult::empty(" \n "),
        'partial, no note' => fn () => ToolResult::partial([], ''),
        'unavailable, no note' => fn () => ToolResult::unavailable(''),
        default => fn () => ToolResult::unavailable('   '),
    };

    expect(toolResultError($build))->toContain('needs a note');
})->with(['empty, no note', 'empty, blank note', 'partial, no note', 'unavailable, no note', 'unavailable, blank note']);

it('refuses a truncated count below 1', function (int $count) {
    expect(toolResultError(fn () => ToolResult::partial([], 'Cut.', $count)))->toContain('Truncated');
})->with([0, -1]);

it('refuses a negative row count', function () {
    expect(toolResultError(fn () => ToolResult::ok([], -1)))->toContain('row count')
        ->and(toolResultError(fn () => ToolResult::partial([], 'Cut.', false, -1)))->toContain('row count')
        ->and(toolResultError(fn () => ToolResult::ok([], 0)))->toBeNull();
});

it('keeps the rules when the content changes', function () {
    $partial = ToolResult::partial(['a' => 1], 'Cut.', 2, 5)->withContent(['a' => 2], 'Cut again.');

    expect($partial->status)->toBe(Status::Partial)
        ->and($partial->data)->toBe(['a' => 2])
        ->and($partial->note)->toBe('Cut again.')
        ->and($partial->truncated)->toBe(2)
        ->and($partial->rowCount)->toBe(5)
        ->and(toolResultError(fn () => ToolResult::unavailable('Why.')->withContent(null, '')))->toContain('needs a note')
        ->and(toolResultError(fn () => ToolResult::ok([])->withContent([], 'A note.')))->toBe('An ok result has no note.');
});
