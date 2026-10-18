<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Audit\AuditUnavailable;
use SinaSaeedi\RuntimeMcp\Mcp\McpToolAdapter;
use SinaSaeedi\RuntimeMcp\Security\Redaction\InvalidRedactionPattern;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\ThrowingTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tools\GuardPipeline;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\AuditStage;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\ResolvePrincipalStage;

/*
 * Fail closed (principle 1.4.3): no audit, no result; an inner failure gives the class only;
 * nothing of ours reaches laravel/mcp, which rethrows in debug mode and shows raw messages (spec 3.2).
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

/**
 * An app-defined audit channel whose directory cannot be created: its parent is a regular file.
 * A chmod would not stop root (a Docker CI job); this does (01-05).
 *
 * @return array<string, mixed>
 */
function failClosedBrokenAudit(): array
{
    return ['logging.channels.runtime-mcp' => [
        'driver' => 'daily',
        'path' => __FILE__.'/no-such-dir/audit.log',
        'formatter' => Monolog\Formatter\JsonFormatter::class,
    ]];
}

it('withholds the result when the audit cannot be written, and reports why', function () {
    FixtureTools::serve(failClosedBrokenAudit());
    Exceptions::fake();

    $response = FixtureTools::call('result', ['kind' => 'text']);

    expect(FixtureTools::result($response)['isError'])->toBeTrue()
        ->and(FixtureTools::envelope($response))->toBe([
            'environment' => 'testing',
            'status' => 'unavailable',
            'note' => AuditStage::WITHHELD,
            'truncated' => false,
            'data' => null,
        ])
        ->and(str_contains((string) $response->getContent(), 'Zo'))->toBeFalse();

    Exceptions::assertReported(AuditUnavailable::class);
});

it('withholds the result when an extra redaction pattern is broken, also in debug mode', function (bool $debug) {
    FixtureTools::serve(['app.debug' => $debug, 'runtime-mcp.redaction.extra_patterns' => ['(']]);
    Exceptions::fake();

    $response = FixtureTools::call('echo', ['text' => 'hello']);

    // RedactStage cannot be built, so the audit stage fails the call; AuditLogger cannot redact
    // the params either, so the audit is unavailable and the result is withheld (01-04, 01-05).
    expect(FixtureTools::result($response)['isError'])->toBeTrue()
        ->and(FixtureTools::envelope($response)['note'])->toBe(AuditStage::WITHHELD)
        ->and(str_contains((string) $response->getContent(), 'hello'))->toBeFalse();

    Exceptions::assertReported(InvalidRedactionPattern::class);
    Exceptions::assertReported(AuditUnavailable::class);
})->with(['debug off' => false, 'debug on' => true]);

it('gives the same redacted error in debug mode', function () {
    FixtureTools::serve(['app.debug' => true]);
    Exceptions::fake();

    $response = FixtureTools::call('throwing');
    $body = (string) $response->getContent();

    expect(FixtureTools::envelope($response)['note'])->toBe('The tool failed: RuntimeException: db password is hunter2 at postgres://u:[redacted:password]@h')
        ->and(str_contains($body, 'postgres://u:p@h'))->toBeFalse()
        ->and(str_contains($body, 'postgres:\/\/u:p@h'))->toBeFalse();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === ThrowingTool::MESSAGE);
});

it('gives the class only, and audits an error, when an inner stage throws', function (bool $debug) {
    FixtureTools::serve(['app.debug' => $debug]);
    Exceptions::fake();
    app()->bind(ResolvePrincipalStage::class, fn () => throw new RuntimeException('inner secret'));

    $response = FixtureTools::call('echo', ['text' => 'a']);
    $entries = AuditLog::entries();

    expect(FixtureTools::envelope($response)['note'])->toBe('The tool failed: RuntimeException.')
        ->and(str_contains((string) $response->getContent(), 'inner secret'))->toBeFalse()
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]['outcome'])->toBe('error')
        ->and($entries[0]['error_class'])->toBe(RuntimeException::class);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'inner secret');
})->with(['debug off' => false, 'debug on' => true]);

it('lets nothing escape to laravel/mcp, even when the pipeline cannot be built', function (bool $debug) {
    FixtureTools::serve(['app.debug' => $debug]);
    Exceptions::fake();
    app()->bind(GuardPipeline::class, fn () => throw new RuntimeException('pipeline secret'));

    $response = FixtureTools::call('echo', ['text' => 'a']);
    $result = FixtureTools::result($response);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'])->toBe([['type' => 'text', 'text' => McpToolAdapter::FAILURE]])
        ->and(str_contains((string) $response->getContent(), 'pipeline secret'))->toBeFalse();

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'pipeline secret');
})->with(['debug off' => false, 'debug on' => true]);
