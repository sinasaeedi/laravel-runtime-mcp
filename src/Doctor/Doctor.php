<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor;

use Closure;
use Illuminate\Contracts\Container\Container;
use LogicException;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\AllowedHosts;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\AllowedOrigins;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\AuditLog;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\BootRules;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Endpoint;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\LinkedUsers;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\MaskLists;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Plugins;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\ReadOnlyRolePrivileges;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\ReadOnlyTransactionConnections;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\RedactionPatterns;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Route;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\Sanctum;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\StatementTimeout;
use SinaSaeedi\RuntimeMcp\Doctor\Checks\StdioPrincipal;
use SinaSaeedi\RuntimeMcp\Plugins\PluginRegistry;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use Throwable;

/**
 * Spec 7.3: runs every core check, then the checks of every active plugin, and never throws: a check
 * that throws (or cannot be built) becomes one Fail line, so one bug never hides the rest of the report.
 *
 * Not bound in the container: built per run, so it never holds a stale config (as BootValidator).
 */
final class Doctor
{
    /**
     * The core checks, in report order. 01-13 added ReadOnlyRolePrivileges (spec 5.1); 01-35 put
     * Sanctum where 01-12's AuthDriver was (the auth.driver boot rule now reports a bad driver in Boot
     * rules); 01-14 appended StatementTimeout, 01-16 ReadOnlyTransactionConnections. Later stories
     * append: 01-17 (Limits), 01-40 (Catalog).
     */
    public const CHECKS = [
        Endpoint::class,
        BootRules::class,
        Sanctum::class,
        LinkedUsers::class,
        StdioPrincipal::class,
        Route::class,
        AllowedHosts::class,
        AllowedOrigins::class,
        RedactionPatterns::class,
        MaskLists::class,
        ReadOnlyRolePrivileges::class,
        Plugins::class,
        AuditLog::class,
        StatementTimeout::class,
        ReadOnlyTransactionConnections::class,
    ];

    /** @param  list<class-string<Check>>  $checks */
    public function __construct(
        private readonly Container $container,
        private readonly PluginRegistry $plugins,
        private readonly Environment $environment,
        private readonly array $checks = self::CHECKS,
    ) {}

    public function run(): DoctorReport
    {
        $lines = [];

        foreach ($this->checks as $class) {
            array_push($lines, ...$this->lines($class, $class, fn (): Check => $this->build($class)));
        }

        array_push($lines, ...$this->pluginLines());

        return new DoctorReport($lines, $this->environment->isRelaxed());
    }

    /** @return list<DoctorLine> */
    private function pluginLines(): array
    {
        try {
            $plugins = $this->plugins->active();
        } catch (Throwable $e) {
            return [self::couldNotRun('Plugins', PluginRegistry::class, $e)];
        }

        $lines = [];

        foreach ($plugins as $plugin) {
            $title = "Plugin `{$plugin->name()}`";

            try {
                $checks = $plugin->doctorChecks();
            } catch (Throwable $e) {
                $lines[] = self::couldNotRun($title, $plugin::class, $e);

                continue;
            }

            foreach ($checks as $check) {
                array_push($lines, ...$this->lines($title, $plugin::class, fn (): Check => $check));
            }
        }

        return $lines;
    }

    /**
     * @param  class-string  $source
     * @param  Closure(): Check  $check
     * @return list<DoctorLine>
     */
    private function lines(string $fallbackTitle, string $source, Closure $check): array
    {
        $title = $fallbackTitle;

        try {
            $instance = $check();
            $source = $instance::class;
            $title = $instance->title();

            return array_map(
                fn (CheckResult $result): DoctorLine => new DoctorLine($title, $result, $source),
                $instance->run(),
            );
        } catch (Throwable $e) {
            return [self::couldNotRun($title, $source, $e)];
        }
    }

    /** @param  class-string<Check>  $class */
    private function build(string $class): Check
    {
        $check = $this->container->make($class);

        if (! $check instanceof Check) {
            throw new LogicException("[{$class}] is not a doctor Check.");
        }

        return $check;
    }

    /** @param  class-string  $source */
    private static function couldNotRun(string $title, string $source, Throwable $e): DoctorLine
    {
        // The class only: an exception message can hold a config value or a credential.
        return new DoctorLine($title, CheckResult::fail("Check {$title} could not run: ".$e::class.'.'), $source);
    }
}
