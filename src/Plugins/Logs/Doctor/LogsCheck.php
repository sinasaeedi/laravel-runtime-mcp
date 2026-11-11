<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs\Doctor;

use Illuminate\Contracts\Config\Repository;
use SinaSaeedi\RuntimeMcp\Doctor\Check;
use SinaSaeedi\RuntimeMcp\Doctor\CheckResult;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogChannelResolver;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogSettings;

/**
 * Spec 4.5, 6.9, 01-25 Part E: runtime-mcp.logs, from the config only (doctor reads no log file: the files are on the
 * server that answers, which can be another container). A bad max_bytes fails, as 01-17's output.max_bytes: another
 * budget is in force than the operator thinks. A channel this tool cannot read warns, as 01-22's sqs queue.
 */
final class LogsCheck implements Check
{
    public const BAD_MAX_BYTES = 'runtime-mcp.logs.max_bytes is not a whole number of at least '.LogSettings::MIN_MAX_BYTES.', so '.LogSettings::DEFAULT_MAX_BYTES.' is used.';

    public const MAX_BYTES_FIX = 'Set it to the read budget in bytes, for example 200000.';

    public const NO_CHANNELS = 'runtime-mcp.logs.channels names no channel, so logs_search is unavailable.';

    public const CHANNELS_FIX = "Set runtime-mcp.logs.channels to channel names from config/logging.php, for example ['stack'].";

    /** %d the 1-based position (01-12: never the value). */
    public const BAD_ENTRY = 'Entry %d of runtime-mcp.logs.channels is not a channel name, so logs_search leaves it out.';

    public const BAD_ENTRY_FIX = 'Use letters, digits, _, . and - only, at most 64.';

    /** %s the reason from LogChannelResolver. */
    public const UNREADABLE = 'logs_search cannot read a part of it: %s';

    public const UNREADABLE_FIX = 'Add a single or daily channel to runtime-mcp.logs.channels, or accept that this part is not searched.';

    /** %s the audit channel ("Channel `runtime-mcp`"). */
    public const AUDIT = "%s is the package's audit log: logs_search shows every developer's tool calls and their params to an agent that may use it.";

    public const AUDIT_FIX = 'Remove it from runtime-mcp.logs.channels and its stacks, unless every key with the logs_search Gate may read the audit.';

    /** %1$s the channels ("`stack`, `daily`"), %2$d the budget. */
    public const PASS = 'logs_search reads %1$s; the read budget is %2$d bytes per call.';

    public function __construct(
        private readonly Repository $config,
        private readonly LogChannelResolver $resolver,
    ) {}

    public function title(): string
    {
        return 'Logs';
    }

    public function run(): array
    {
        $settings = new LogSettings($this->config);
        $results = [];

        if (! $settings->maxBytesIsValid()) {
            $results[] = CheckResult::fail(self::BAD_MAX_BYTES, self::MAX_BYTES_FIX);
        }

        foreach ($settings->badPositions() as $position) {
            $results[] = CheckResult::warn(sprintf(self::BAD_ENTRY, $position), self::BAD_ENTRY_FIX);
        }

        $channels = $settings->channels();

        if ($channels === []) {
            $results[] = CheckResult::fail(self::NO_CHANNELS, self::CHANNELS_FIX);

            return $results;
        }

        foreach ($channels as $channel) {
            $resolved = $this->resolver->resolve($channel);

            foreach ($resolved->skipped as $reason) {
                $results[] = CheckResult::warn(sprintf(self::UNREADABLE, $reason), self::UNREADABLE_FIX);
            }

            foreach ($resolved->sources as $source) {
                if ($this->resolver->isAuditLog($source)) {
                    $results[] = CheckResult::warn(sprintf(self::AUDIT, LogChannelResolver::shown($source->channel)), self::AUDIT_FIX);
                }
            }
        }

        if ($results === []) {
            $names = implode(', ', array_map(fn (string $channel): string => "`{$channel}`", $channels));
            $results[] = CheckResult::pass(sprintf(self::PASS, $names, $settings->maxBytes()));
        }

        return $results;
    }
}
