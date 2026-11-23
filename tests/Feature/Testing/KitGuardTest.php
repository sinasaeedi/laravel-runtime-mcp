<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use SinaSaeedi\RuntimeMcp\Security\Auth\CurrentPrincipal;
use SinaSaeedi\RuntimeMcp\Testing\RuntimeMcp;
use SinaSaeedi\RuntimeMcp\Testing\RuntimeMcpTestKitMisused;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;

/*
 * 01-39 Part C, spec 7.5 and 11.2 ("Test kit: it throws outside the test environment"): the kit sets a principal without
 * a key, so it runs only while the app runs its tests (runningUnitTests(), APP_ENV=testing).
 */

beforeEach(fn () => AuditLog::clear());
afterEach(fn () => AuditLog::clear());

it('TKG1 throws outside the test environment, from every entry point, and sets no principal', function (string $environment) {
    currentTest()->bootWith(environment: $environment);
    config(['runtime-mcp.tools' => [EchoTool::class]]);
    Gate::define('runtime-mcp.echo', fn (): bool => true);

    expect(app()->environment())->toBe($environment)
        ->and(fn () => RuntimeMcp::test())->toThrow(RuntimeMcpTestKitMisused::class, RuntimeMcpTestKitMisused::OUTSIDE_TESTS)
        ->and(fn () => RuntimeMcp::assertToolContract(EchoTool::class))->toThrow(RuntimeMcpTestKitMisused::class, RuntimeMcpTestKitMisused::OUTSIDE_TESTS)
        ->and(app(CurrentPrincipal::class)->get())->toBeNull()
        ->and(AuditLog::entries())->toBe([]);
})->with(['production', 'staging', 'local', 'testing-eu']);

it('TKG2 runs in the testing environment', function () {
    currentTest()->bootWith(environment: 'testing');
    config(['runtime-mcp.tools' => [EchoTool::class]]);
    Gate::define('runtime-mcp.echo', fn (): bool => true);

    expect(app()->runningUnitTests())->toBeTrue();

    RuntimeMcp::test()->as('sina')->call('echo', ['text' => 'hi'])->assertOk();
    RuntimeMcp::assertToolContract(EchoTool::class);
});

it('TKG3 is a LogicException with a fixed message', function () {
    expect(RuntimeMcpTestKitMisused::outsideTests())->toBeInstanceOf(LogicException::class)
        ->and(RuntimeMcpTestKitMisused::outsideTests()->getMessage())->toBe('The Runtime MCP test kit runs only in your tests.')
        ->and(RuntimeMcpTestKitMisused::noPhpUnit()->getMessage())->toBe('The Runtime MCP test kit needs PHPUnit or Pest.');
});
