<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Plugins\BigResultPlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\BigResultTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\Gates;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\AuditStage;

/*
 * Spec 4.4 step 8, 5.8, 01-17 Part C: the cap in the pipeline, over HTTP. It measures the redacted result,
 * and the audit records the final size.
 */

/** @param  array<string, mixed>  $config */
function outputCapServe(array $config = []): void
{
    AuditLog::clear();
    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.output.max_bytes' => 1000, ...$config]);
    app()->scoped(PluginRegistry::class, fn (Application $app): PluginRegistry => new PluginRegistry(
        $app, $app->make(Repository::class), [BigResultPlugin::class],
    ));
    Gates::allow('big_result');
    Exceptions::fake();
}

afterEach(fn () => AuditLog::clear());

it('OS1 trims rows over HTTP, and the audit records the final size and the rows sent', function () {
    outputCapServe();

    $response = FixtureTools::call('big_result', ['kind' => 'rows', 'count' => 100]);
    $envelope = FixtureTools::envelope($response);
    $rows = is_array($envelope['data']) && is_array($envelope['data']['rows']) ? $envelope['data']['rows'] : [];
    $entry = AuditLog::entries()[0];

    expect($envelope['status'])->toBe('partial')
        ->and($envelope['truncated'])->toBe(100 - count($rows))
        ->and(strlen(FixtureTools::text($response, 1)))->toBeLessThanOrEqual(1000)
        ->and($entry['output_bytes'])->toBe(strlen(FixtureTools::text($response, 1)))
        ->and($entry['row_count'])->toBe(count($rows));
});

it('OS2 cuts after redaction, so no part of a secret is sent', function () {
    outputCapServe();

    $response = FixtureTools::call('big_result', ['kind' => 'secrets', 'count' => 200]);
    $envelope = FixtureTools::envelope($response);
    $text = is_array($envelope['data']) && is_string($envelope['data']['text']) ? $envelope['data']['text'] : '';

    expect(str_contains((string) $response->getContent(), 'ghp_'))->toBeFalse()
        ->and($text)->toStartWith('[redacted:github_token] ')
        ->and($text)->toContain('…[cut ')
        ->and($envelope['status'])->toBe('partial')
        ->and(strlen(BigResultTool::TOKEN))->toBe(40);
});

it('OS3 leaves a result that cannot be encoded to the audit stage', function () {
    outputCapServe();

    FixtureTools::call('big_result', ['kind' => 'infinite']);

    expect(AuditLog::entries()[0]['error_class'])->toBe(JsonException::class);
});

it('OS4 cuts the long note of a failed call and keeps it unavailable', function () {
    AuditLog::clear();
    FixtureTools::serve(['runtime-mcp.output.max_bytes' => 1000]);
    Exceptions::fake();

    $response = FixtureTools::call('throwing', ['message' => str_repeat('😀', 400)]);
    $envelope = FixtureTools::envelope($response);

    expect($envelope['status'])->toBe('unavailable')
        ->and($envelope['truncated'])->toBeFalse()
        ->and($envelope['note'])->toStartWith('The tool failed: RuntimeException: 😀')
        ->and($envelope['note'])->toMatch('/…\[cut \d+ bytes\]$/u')
        ->and(FixtureTools::result($response)['isError'] ?? null)->toBeTrue()
        ->and(strlen(FixtureTools::text($response, 1)))->toBeLessThanOrEqual(1000)
        ->and(AuditLog::entries()[0]['output_bytes'])->toBe(strlen(FixtureTools::text($response, 1)));
});

it('OS5 sends a result under the default cap whole', function () {
    outputCapServe(['runtime-mcp.output.max_bytes' => 100_000]);

    $envelope = FixtureTools::envelope(FixtureTools::call('big_result', ['kind' => 'rows', 'count' => 100]));

    expect($envelope['status'])->toBe('ok')
        ->and($envelope['truncated'])->toBeFalse()
        ->and(AuditStage::NOT_ENCODABLE)->not->toBe($envelope['note']);
});
