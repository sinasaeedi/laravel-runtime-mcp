<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\Reason;

/*
 * 01-36 Part D: the prompt_get entry of spec 5.7. The same 18 fields as every entry; tool is null and the
 * prompt name is details.prompt.
 */

beforeEach(fn () => Carbon::setTestNow('2026-11-12 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('PE1 records an allowed prompts/get', function () {
    $entry = AuditEntry::promptGet(
        environment: 'prod-eu', prompt: 'health_check', params: ['since' => '7d'], allowed: true, durationMs: 3,
        principal: 'sina', userId: 7, authMethod: 'api_key', ip: '10.0.0.1', client: ['name' => 'claude-code', 'version' => '2.1'],
    );

    expect($entry->toArray())->toBe([
        'kind' => 'prompt_get',
        'timestamp' => Carbon::now()->toIso8601String(),
        'environment' => 'prod-eu',
        'principal' => 'sina',
        'user_id' => 7,
        'auth_method' => 'api_key',
        'ip' => '10.0.0.1',
        'client' => ['name' => 'claude-code', 'version' => '2.1'],
        'tool' => null,
        'params' => ['since' => '7d'],
        'decision' => 'allowed',
        'reason' => null,
        'outcome' => 'success',
        'error_class' => null,
        'duration_ms' => 3,
        'row_count' => null,
        'output_bytes' => null,
        'details' => ['prompt' => 'health_check'],
    ])->and(AuditEntry::KIND_PROMPT_GET)->toBe('prompt_get');
});

it('PE2 records a denial with its reason and the denied tool, and no outcome', function () {
    $entry = AuditEntry::promptGet('testing', 'investigate_failed_jobs', [], false, Reason::PromptToolDenied, failed: true, errorClass: 'X', tool: 'queue_failed_job_show');

    expect($entry->decision)->toBe('denied')
        ->and($entry->reason)->toBe('prompt_tool_denied')
        ->and($entry->outcome)->toBeNull()
        ->and($entry->errorClass)->toBeNull()
        ->and($entry->tool)->toBeNull()
        ->and($entry->details)->toBe(['prompt' => 'investigate_failed_jobs', 'tool' => 'queue_failed_job_show']);
});

it('PE3 records refused input as an allowed call that failed, and drops a reason', function () {
    $entry = AuditEntry::promptGet('testing', 'explain_record', ['model' => 'user'], true, Reason::GateDenied, failed: true, errorClass: 'Illuminate\Validation\ValidationException');

    expect($entry->decision)->toBe('allowed')
        ->and($entry->reason)->toBeNull()
        ->and($entry->outcome)->toBe('error')
        ->and($entry->errorClass)->toBe('Illuminate\Validation\ValidationException')
        ->and($entry->details)->toBe(['prompt' => 'explain_record']);
});

it('PE4 cuts the prompt name to 64 characters and adds no tool key without a tool', function () {
    $entry = AuditEntry::promptGet('testing', str_repeat('é', 100), [], false, Reason::UnknownPrompt);

    expect($entry->details)->toBe(['prompt' => str_repeat('é', 64)])
        ->and(AuditEntry::MAX_PROMPT)->toBe(64);
});
