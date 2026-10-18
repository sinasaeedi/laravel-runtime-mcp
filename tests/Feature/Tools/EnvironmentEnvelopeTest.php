<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;

/*
 * Spec 4.6, 11.2 "Environments": every envelope and every audit entry name the environment, also
 * an unknown one, and also on a denial or a failure.
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

it('names an unknown environment in the label, the envelope and the audit entry', function (string $tool, array $arguments) {
    FixtureTools::serve(environment: 'prod-eu');
    Exceptions::fake(); // the failure row reports its exception

    $response = FixtureTools::call($tool, $arguments);

    expect(FixtureTools::text($response, 0))->toStartWith('Result from Runtime Test (prod-eu).')
        ->and(FixtureTools::envelope($response)['environment'])->toBe('prod-eu')
        ->and(AuditLog::entries()[0]['environment'] ?? null)->toBe('prod-eu');
})->with([
    'a success' => ['echo', ['text' => 'a']],
    'a failure' => ['throwing', []],
    'invalid input' => ['echo', []],
]);
