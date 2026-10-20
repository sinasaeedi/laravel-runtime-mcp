<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use SinaSaeedi\RuntimeMcp\Console\DoctorCommand;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootGuard;
use SinaSaeedi\RuntimeMcp\Security\Boot\BootState;
use SinaSaeedi\RuntimeMcp\Security\Boot\RuntimeMcpMisconfigured;
use SinaSaeedi\RuntimeMcp\Tests\Support\BootCapture;

/*
 * 01-12 Part D: in a relaxed environment a failed boot rule throws at boot (01-10), except while
 * `php artisan runtime-mcp:doctor` boots. BootGuard reads argv[1], as Laravel's
 * runningConsoleCommand() does, so a test sets $_SERVER['argv'] before the boot and restores it.
 */

/**
 * @param  list<string>  $argv
 * @param  Closure(): void  $test
 */
function withArgvForDoctorBoot(array $argv, Closure $test): void
{
    $saved = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = $argv;

    try {
        $test();
    } finally {
        $_SERVER['argv'] = $saved;
    }
}

it('boots without the relaxed throw when doctor runs, registers nothing, and doctor lists the issue', function (string $environment) {
    withArgvForDoctorBoot(['artisan', DoctorCommand::NAME], function () use ($environment): void {
        $boot = BootCapture::boot(['runtime-mcp.auth.api_keys' => null], $environment);

        expect($boot->thrown)->toBeNull()
            ->and(Route::has('runtime-mcp'))->toBeFalse()
            ->and(app(BootState::class)->httpAllowed())->toBeFalse()
            ->and(app(BootState::class)->report()?->passed())->toBeFalse();

        expect(Artisan::call(DoctorCommand::NAME))->toBe(1)
            ->and(Artisan::output())->toContain('`api_keys.present`: RUNTIME_MCP_API_KEYS is empty.');
    });
})->with(['local', 'testing']);

it('still throws for every other command in the same config (Part D is narrow)', function (array $argv) {
    /** @var list<string> $argv */
    withArgvForDoctorBoot($argv, function (): void {
        $boot = BootCapture::boot(['runtime-mcp.auth.api_keys' => null], 'local');

        expect($boot->thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class);
    });
})->with([
    'another command' => [['artisan', 'list']],
    'an option before the name' => [['artisan', '-v', DoctorCommand::NAME]],
    'doctor later in argv' => [['artisan', 'help', DoctorCommand::NAME]],
    'no command' => [['artisan']],
    'a similar name' => [['artisan', 'runtime-mcp:doctors']],
]);

it('throws when argv says doctor but the app is not in the console', function () {
    currentTest()->bootWith(['runtime-mcp.auth.api_keys' => null], 'local');

    $web = Mockery::mock(Application::class);
    $web->shouldReceive('runningInConsole')->andReturn(false);

    withArgvForDoctorBoot(['artisan', DoctorCommand::NAME], function () use ($web): void {
        $thrown = null;

        try {
            app()->make(BootGuard::class, ['app' => $web])->check();
        } catch (RuntimeMcpMisconfigured $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(RuntimeMcpMisconfigured::class);
    });
});

it('changes nothing in a strict environment: no throw, one critical line, one event', function () {
    withArgvForDoctorBoot(['artisan', DoctorCommand::NAME], function (): void {
        $boot = BootCapture::boot(['runtime-mcp.auth.api_keys' => null], 'production');

        expect($boot->thrown)->toBeNull()
            ->and($boot->criticals())->toHaveCount(1)
            ->and($boot->events)->toHaveCount(1);
    });
});

it('does not touch a valid config: the endpoint registers while doctor boots', function () {
    withArgvForDoctorBoot(['artisan', DoctorCommand::NAME], function (): void {
        $boot = BootCapture::boot([], 'local');

        expect($boot->thrown)->toBeNull()
            ->and(Route::has('runtime-mcp'))->toBeTrue();
    });
});
