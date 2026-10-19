<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Psr\Log\LoggerInterface;
use SinaSaeedi\RuntimeMcp\Audit\Events\BootValidationFailed;
use SinaSaeedi\RuntimeMcp\Plugins\Contracts\Plugin;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Boot\RuntimeMcpMisconfigured;

/**
 * Boots the app (bootWith()) with a recorder in place before the providers boot (01-10): the log
 * lines and the BootValidationFailed events written while the provider boots, and the relaxed
 * exception, if one came out.
 *
 * After a throw, read only this object: the app did not finish its boot, and $this->app in the test
 * case is still the previous app.
 */
final class BootCapture
{
    public RecordingLogger $log;

    /** @var list<BootValidationFailed> */
    public array $events = [];

    public ?RuntimeMcpMisconfigured $thrown = null;

    private function __construct()
    {
        $this->log = new RecordingLogger;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  (Closure(Application): void)|null  $also  more pre-boot setup, for example a fake PluginRegistry
     */
    public static function boot(array $config = [], ?string $environment = null, ?Closure $also = null): self
    {
        $capture = new self;

        try {
            currentTest()->bootWith(['runtime-mcp.enabled' => true, ...$config], $environment, $capture->hook($also));
        } catch (RuntimeMcpMisconfigured $e) {
            $capture->thrown = $e;
        }

        return $capture;
    }

    /**
     * Pre-boot setup that replaces the plugin list, so boot validation sees fixture plugins. It runs
     * after the provider's register(), so it wins over its scoped(PluginRegistry::class).
     *
     * @param  list<class-string<Plugin>>  $plugins
     * @return Closure(Application): void
     */
    public static function plugins(array $plugins): Closure
    {
        return function (Application $app) use ($plugins): void {
            $app->scoped(PluginRegistry::class, fn (Application $app): PluginRegistry => new PluginRegistry(
                $app, $app->make(Repository::class), $plugins,
            ));
        };
    }

    /** @return list<string> the critical lines */
    public function criticals(): array
    {
        return $this->log->messages('critical');
    }

    /** @return list<string> the warning lines */
    public function warnings(): array
    {
        return $this->log->messages('warning');
    }

    /**
     * Each boot (also a rebootApplication()) installs a new logger and listener, and they all write
     * into this one object, so a test can count the lines of several boots.
     *
     * @param  (Closure(Application): void)|null  $also
     * @return Closure(Application): void
     */
    private function hook(?Closure $also): Closure
    {
        return function (Application $app) use ($also): void {
            $app->instance(LoggerInterface::class, $this->log);
            $app->make(Dispatcher::class)->listen(
                BootValidationFailed::class,
                function (BootValidationFailed $event): void {
                    $this->events[] = $event;
                },
            );

            if ($also !== null) {
                $also($app);
            }
        };
    }
}
