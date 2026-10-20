<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Audit\AuditChannel;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;

/**
 * Spec 5.7, 01-05: the audit channel can be written, or every tool result is withheld (fail closed).
 * A channel with a path (the package's daily channel, or an app single/daily channel) needs a
 * writable directory; a channel without one (database, stack, custom) is the app's to check.
 *
 * is_writable() checks the user that runs doctor, which can differ from the PHP-FPM user (docs).
 */
final class AuditLog implements Check
{
    public function __construct(private readonly Repository $config) {}

    public function title(): string
    {
        return 'Audit log';
    }

    public function run(): array
    {
        $name = AuditChannel::name($this->config);
        $results = $this->fallbacks($name);
        $channel = $this->config->get("logging.channels.{$name}");

        if (! is_array($channel)) {
            $results[] = CheckResult::fail(
                "Log channel `{$name}` is not defined, so tool results are withheld.",
                "Define logging.channels.{$name}, or remove it from config/logging.php so the package registers its own.",
            );

            return $results;
        }

        $path = $channel['path'] ?? null;

        if (! is_string($path) || $path === '') {
            $results[] = CheckResult::pass("The app's channel `{$name}` writes the audit.");

            return $results;
        }

        $directory = self::nearestDirectory(dirname($path));

        if (! is_writable($directory)) {
            $results[] = CheckResult::fail(
                "The audit cannot be written to {$directory}, so tool results are withheld.",
                'Make the directory writable for the user that runs PHP.',
            );

            return $results;
        }

        $results[] = CheckResult::pass("Channel `{$name}` writes to {$directory}.");

        return $results;
    }

    /**
     * 01-05: a bad audit.channel or audit.days falls back to the default, so the audit never turns
     * off. Say so.
     *
     * @return list<CheckResult>
     */
    private function fallbacks(string $name): array
    {
        $results = [];

        if ($this->config->get('runtime-mcp.audit.channel') !== $name) {
            $results[] = CheckResult::warn(
                "runtime-mcp.audit.channel is not a valid channel name, so the audit uses `{$name}`.",
                'Use letters, digits, _ and - only, at most 64.',
            );
        }

        $days = $this->config->get('runtime-mcp.audit.days');
        $usable = (is_int($days) && $days >= 1)
            || (is_string($days) && preg_match('/^[0-9]{1,6}$/', $days) === 1 && (int) $days >= 1);

        if (! $usable) {
            $results[] = CheckResult::warn(
                'runtime-mcp.audit.days is not a whole number of 1 or more, so '.AuditChannel::days($this->config).' is used.',
                'Set runtime-mcp.audit.days to the number of days to keep the audit.',
            );
        }

        return $results;
    }

    /** The directory itself, or its nearest parent that exists: the daily handler creates the rest. */
    private static function nearestDirectory(string $directory): string
    {
        while (! is_dir($directory) && dirname($directory) !== $directory) {
            $directory = dirname($directory);
        }

        return $directory;
    }
}
