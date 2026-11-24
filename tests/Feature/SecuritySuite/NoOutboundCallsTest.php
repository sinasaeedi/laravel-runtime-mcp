<?php

declare(strict_types=1);

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Mcp\Client\ClientManager;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\CatalogCalls;
use SinaSaeedi\RuntimeMcp\Tests\Support\OutboundCallAttempted;
use SinaSaeedi\RuntimeMcp\Tests\Support\Prompts;
use SinaSaeedi\RuntimeMcp\Tests\Support\SecuritySuite;

/*
 * 01-30, spec rule 4 ("No outbound calls"), rule 20 ("No use of the laravel/mcp client"), spec 11.2 ("No outbound
 * calls"), spec 14: one call of every tool and every prompt in one process, with 01-01's runtime guards on
 * (OutboundGuard for the http/https/ftp stream wrappers, Http::preventStrayRequests()). RunTool catches and reports every
 * Throwable, so a blocked call would end as a reported exception and a failed call, never as a test failure by itself:
 * the test fakes the exception handler and asserts that nothing of the guards was reported. tests/Arch/
 * NoOutboundCallsTest.php and NoMcpClientTest.php are the static halves.
 */

beforeEach(fn () => SecuritySuite::clear());
afterEach(fn () => SecuritySuite::clear());

it('NO1 makes no outbound call and never resolves the laravel/mcp client while every tool and prompt runs', function () {
    SecuritySuite::serve();
    Exceptions::fake();

    foreach (array_keys(SecuritySuite::TOOLS) as $tool) {
        SecuritySuite::call($tool);
    }

    foreach (SecuritySuite::PROMPTS as $prompt => $arguments) {
        Prompts::get($prompt, $arguments)->assertOk();
    }

    $tools = array_filter(AuditLog::entries(), fn (array $entry): bool => $entry['kind'] === AuditEntry::KIND_TOOL_CALL);

    Exceptions::assertNotReported(OutboundCallAttempted::class);
    Exceptions::assertNotReported(StrayRequestException::class);
    Exceptions::assertNothingReported();
    expect(array_column($tools, 'tool'))->toBe(array_keys(SecuritySuite::TOOLS))
        ->and(array_column($tools, 'outcome'))->toBe(array_fill(0, count(SecuritySuite::TOOLS), AuditEntry::OUTCOME_SUCCESS))
        ->and(app()->resolved(ClientManager::class))->toBeFalse();
});

it('NO2 makes no outbound call and never resolves the client when every tool runs behind the catalog', function () {
    // laravel/mcp allows 10 inner calls by default; the app's own mcp.tool_search config may allow more (spec 5.17).
    SecuritySuite::serve(['runtime-mcp.catalog.enabled' => true, 'mcp.tool_search.max_tool_calls' => count(SecuritySuite::TOOLS)]);
    Exceptions::fake();

    CatalogCalls::messages(CatalogCalls::call('search_tools', ['query' => 'queue']));
    $batch = CatalogCalls::batch(CatalogCalls::messages(CatalogCalls::execute(CatalogCalls::calls(
        array_map(fn (string $tool): array => [$tool, SecuritySuite::TOOLS[$tool][0]], array_keys(SecuritySuite::TOOLS)),
    ))));

    Exceptions::assertNothingReported();
    expect($batch['ok'] ?? null)->toBeTrue()
        ->and(app()->resolved(ClientManager::class))->toBeFalse();
});
