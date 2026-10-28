<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Boot;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use SinaSaeedi\RuntimeMcp\Audit\Events\BootValidationFailed;
use SinaSaeedi\RuntimeMcp\Console\DoctorCommand;
use SinaSaeedi\RuntimeMcp\Security\BootValidator;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use Throwable;

/**
 * Spec 5.11, 4.6: what the provider does with the boot report. Strict: the endpoint stays off, one
 * critical line, one BootValidationFailed once the app has booted (throttled), and never a throw
 * (rule 15). Relaxed: throw RuntimeMcpMisconfigured, except while `php artisan runtime-mcp:doctor`
 * boots (01-12): doctor must start exactly when the config is broken. Warnings are logged
 * (throttled) in both.
 *
 * Logs through Psr\Log\LoggerInterface, the app's default log (as GateWarning, 01-09).
 */
final class BootGuard
{
    public const CRITICAL = 'Runtime MCP is off: the security config is not valid. Run php artisan runtime-mcp:doctor.';

    public const WARNING_PREFIX = 'Runtime MCP: ';

    public function __construct(
        private readonly BootValidator $validator,
        private readonly BootState $state,
        private readonly Environment $environment,
        private readonly BootThrottle $throttle,
        private readonly LoggerInterface $log,
        private readonly Dispatcher $events,
        private readonly Application $app,
    ) {}

    /**
     * Validates, records the report in BootState, and reports. Returns the report in a strict
     * environment and whenever validation passed.
     *
     * @throws RuntimeMcpMisconfigured in a relaxed environment when an error exists, unless doctor runs
     */
    public function check(): BootReport
    {
        $report = $this->validator->validate();
        $this->state->record($report);
        $environment = $this->environment->name();

        $warnings = $report->warnings();

        if ($warnings !== [] && $this->throttle->shouldReport('warning', self::ids($warnings))) {
            $this->quietly(function () use ($warnings): void {
                foreach ($warnings as $issue) {
                    $this->log->warning(self::WARNING_PREFIX.$issue->message, ['rule' => $issue->ruleId]);
                }
            });
        }

        if ($report->passed()) {
            return $report;
        }

        if ($this->environment->isRelaxed()) {
            // 01-12: the report is recorded and httpAllowed()/stdioAllowed() are false, so nothing
            // registers; doctor then prints every issue.
            if ($this->runningDoctor()) {
                return $report;
            }

            throw new RuntimeMcpMisconfigured($report, $environment);
        }

        if ($this->throttle->shouldReport('error', self::ids($report->errors()))) {
            $this->quietly(fn () => $this->log->critical(self::CRITICAL, [
                'environment' => $environment,
                'issues' => array_map(fn (BootIssue $issue): array => $issue->toArray(), $report->issues),
            ]));
            // After every provider has booted: an app attaches its listeners in its own providers, and
            // Laravel boots those after the package providers (review 01-10 M1).
            $this->app->booted(fn () => $this->quietly(fn () => $this->events->dispatch(new BootValidationFailed($environment, $report->issues))));
        }

        return $report;
    }

    /**
     * The same test as Laravel's Application::runningConsoleCommand(), which is not on the
     * Application contract: the console, and the command name in argv[1]. A web request or a queue
     * worker never matches. `php artisan -v runtime-mcp:doctor` does not match either (argv[1] is -v).
     */
    private function runningDoctor(): bool
    {
        $argv = $_SERVER['argv'] ?? null;

        return $this->app->runningInConsole()
            && is_array($argv)
            && ($argv[1] ?? null) === DoctorCommand::NAME;
    }

    /**
     * Rule 15: nothing may throw at boot in a strict environment, also a broken log channel or an
     * event listener that throws. The report is already recorded, so the endpoint stays off.
     *
     * @param  callable(): mixed  $report
     */
    private function quietly(callable $report): void
    {
        try {
            $report();
        } catch (Throwable) {
            // Nowhere left to report to.
        }
    }

    /**
     * @param  list<BootIssue>  $issues
     * @return list<string>
     */
    private static function ids(array $issues): array
    {
        return array_map(fn (BootIssue $issue): string => $issue->ruleId, $issues);
    }
}
