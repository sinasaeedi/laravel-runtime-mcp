<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

// Spec 11.2 "Environments": every audit entry shows the environment, also an unknown (strict) one.
it('names the environment in every entry', function () {
    currentTest()->bootWith([], 'prod-eu');

    $environment = app(Environment::class)->name();
    $logger = app(AuditLogger::class);

    $logger->toolCall(AuditEntry::toolCall(environment: $environment, tool: 'app_info', params: [], allowed: true));
    $logger->toolCall(AuditEntry::toolCall(environment: $environment, tool: 'db_query', params: [], allowed: false, reason: Reason::NoGate));
    $logger->rejectedRequest(AuditEntry::rejectedRequest($environment, Reason::MissingCredentials, 'api_key', '10.0.0.1', ['scheme' => 'none']));

    expect(app(Environment::class)->isStrict())->toBeTrue()
        ->and(array_column(AuditLog::entries(), 'environment'))->toBe(['prod-eu', 'prod-eu', 'prod-eu']);
});
