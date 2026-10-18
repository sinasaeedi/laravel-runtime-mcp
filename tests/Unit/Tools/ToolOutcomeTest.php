<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Tools\Status;
use SinaSaeedi\RuntimeMcp\Tools\ToolOutcome;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

it('keeps a success as allowed and not an error, also for an unavailable result', function () {
    $outcome = ToolOutcome::success(ToolResult::unavailable('No file.'));

    expect($outcome->allowed)->toBeTrue()
        ->and($outcome->isError)->toBeFalse()
        ->and($outcome->reason)->toBeNull()
        ->and($outcome->errorClass)->toBeNull()
        ->and($outcome->envelope)->toBeNull();
});

it('makes a denial an error with the reason and an unavailable result', function () {
    $outcome = ToolOutcome::denied(Reason::NoPrincipal, 'No authenticated principal.');

    expect($outcome->allowed)->toBeFalse()
        ->and($outcome->isError)->toBeTrue()
        ->and($outcome->reason)->toBe(Reason::NoPrincipal)
        ->and($outcome->errorClass)->toBeNull()
        ->and($outcome->result->status)->toBe(Status::Unavailable)
        ->and($outcome->result->note)->toBe('No authenticated principal.');
});

it('makes a failure an allowed error with the exception class', function () {
    $outcome = ToolOutcome::failed(new RuntimeException('x'), 'The tool failed.');

    expect($outcome->allowed)->toBeTrue()
        ->and($outcome->isError)->toBeTrue()
        ->and($outcome->reason)->toBeNull()
        ->and($outcome->errorClass)->toBe(RuntimeException::class)
        ->and($outcome->result->status)->toBe(Status::Unavailable)
        ->and($outcome->result->note)->toBe('The tool failed.');
});

it('keeps the decision when the result or the envelope changes', function () {
    $outcome = ToolOutcome::denied(Reason::NoPrincipal, 'No.')
        ->withResult(ToolResult::unavailable('Redacted no.'))
        ->withEnvelope(['status' => 'unavailable']);

    expect($outcome->allowed)->toBeFalse()
        ->and($outcome->isError)->toBeTrue()
        ->and($outcome->reason)->toBe(Reason::NoPrincipal)
        ->and($outcome->result->note)->toBe('Redacted no.')
        ->and($outcome->envelope)->toBe(['status' => 'unavailable']);

    $failed = ToolOutcome::failed(new LogicException('x'), 'Failed.')->withResult(ToolResult::unavailable('Failed again.'));

    expect($failed->errorClass)->toBe(LogicException::class)
        ->and($failed->allowed)->toBeTrue();
});
