<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Budget;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\ErrorGroups;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogChannelResolver;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogEntry;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogScan;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogSettings;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogSource;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobWindow;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Support\Timestamp;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * Spec 6.10, 01-26 Part B: the error-level entries since a time, grouped by cause (ErrorFingerprint), with a count,
 * first and last seen and one redacted sample each. Reads with 01-25's LogScan within logs.max_bytes, so a cut read
 * says that the counts are lower bounds; every result that is not ok says which files it read, or why it could not
 * look (rule 11).
 *
 * Built through the container on every request (also tools/list): the constructor reads nothing, and the Redactor
 * (which throws for a bad extra pattern) is made in execute().
 */
final class LogsRecentErrors extends RuntimeTool
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 100;

    public const NO_CHANNELS = 'No log channel is set for logs_recent_errors. Set runtime-mcp.logs.channels to channel names from config/logging.php.';

    /** %1$d the limit, %2$d the number of groups. */
    public const MORE = 'Showing the %1$d most frequent of %2$d groups.';

    /** %1$d the bytes read, %2$s the files, %3$s the time before which errors may be missing. */
    public const CUT = 'Read the last %1$d bytes (%2$s); errors older than %3$s may be missing. Counts are lower bounds.';

    /** A cut where a source gave no entry, so no time is true for every source: %1$d the bytes, %2$s the files. */
    public const CUT_NO_TIME = 'Read the last %1$d bytes (%2$s); older errors may be missing. Counts are lower bounds.';

    /** %1$s since, %2$s the files. */
    public const NO_ERRORS = 'No error-level entries since %1$s in %2$s.';

    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
        private readonly LogChannelResolver $resolver,
        private readonly LogScan $scan,
    ) {}

    public function name(): string
    {
        return 'logs_recent_errors';
    }

    public function description(): string
    {
        return 'Groups the error-level log entries (error, critical, alert, emergency) of one channel by cause: numbers, '
            .'UUIDs and IDs in the message are replaced, so the same error with another order number or key is one '
            .'group. Each group has a fingerprint, the level, the pattern (at most 300 characters), the count, '
            .'first_seen and last_seen (ISO 8601), and one sample: the newest entry (time, level, env, channel, file, '
            .'message and context; redacted, a context over 2,000 bytes is cut). Groups are sorted by count, then by '
            .'last_seen. It reads from the end of each file, at most runtime-mcp.logs.max_bytes bytes per call (default '
            .'200,000), so a read can stop before since: then the status is partial, the note says how far it read, and '
            .'the counts are lower bounds. Input, all optional: channel (one of runtime-mcp.logs.channels, default the '
            .'first), since (ISO 8601 date or date-time, at most 64 characters, not in the future; without an offset, '
            .'the app timezone; default 24 hours before now), limit (the number of groups, 1 to 100, default 20).';
    }

    public function tier(): Tier
    {
        return Tier::ReadSensitive;
    }

    public function schema(JsonSchema $schema): array
    {
        $channels = $this->settings()->channels();
        $channel = $channels === [] ? $schema->string()->max(LogsSearch::MAX_CHANNEL) : $schema->string()->enum($channels);

        return [
            'channel' => $channel->description('The log channel, from runtime-mcp.logs.channels. Default: the first.'),
            'since' => $schema->string()
                ->description('The earliest entry time: an ISO 8601 date or date-time, for example 2026-10-01 or 2026-10-01T09:00:00+01:00, not in the future. Default: 24 hours before now.')
                ->max(LogsSearch::MAX_TIME),
            'limit' => $schema->integer()->description('How many groups to return. Default 20.')->min(1)->max(self::MAX_LIMIT),
        ];
    }

    public function rules(): array
    {
        $channels = $this->settings()->channels();

        return [
            // filled: the validator skips the other rules for "" (01-19).
            'channel' => $channels === [] ? ['filled', 'string', 'max:'.LogsSearch::MAX_CHANNEL] : ['filled', 'string', Rule::in($channels)],
            // A future since has nothing to read; a daily channel would name a day range that runs backwards.
            'since' => ['filled', 'string', 'max:'.LogsSearch::MAX_TIME, 'date', 'before_or_equal:now'],
            // numeric refuses true, which integer takes as 1 (as db_query).
            'limit' => ['integer', 'numeric', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function execute(ToolInput $input): ToolResult
    {
        $settings = $this->settings();
        $channels = $settings->channels();

        if ($channels === []) {
            return ToolResult::unavailable(self::NO_CHANNELS);
        }

        $text = fn (string $key): ?string => is_string($input->get($key)) ? $input->get($key) : null;
        $channel = $text('channel') ?? $channels[0];
        $since = $text('since');
        $since = $since === null
            ? CarbonImmutable::now()->setTimezone(date_default_timezone_get())->startOfSecond()->subHours(FailedJobWindow::SUMMARY_HOURS)
            : FailedJobWindow::bound($since, end: false);
        $limit = $input->get('limit');
        $limit = is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;
        $groups = new ErrorGroups($this->app->make(Redactor::class));
        $resolved = $this->resolver->resolve($channel);

        $scan = $this->scan->run(
            $resolved,
            $since,
            null,
            new Budget($settings->maxBytes()),
            function (LogEntry $entry, LogSource $source, string $file) use ($groups): null {
                $groups->add($entry, $source, $file);

                return null;
            },
            PHP_INT_MAX,
        );

        if ($scan->files === []) {
            return ToolResult::unavailable($scan->skipped === [] ? sprintf(LogsSearch::NOTHING, LogChannelResolver::shown($channel)) : implode(' ', $scan->skipped));
        }

        $top = $groups->top($limit);
        $files = LogsSearch::join($scan->files);
        $data = ['since' => FailedJobWindow::iso($since), 'groups' => $top, 'entries_read' => $groups->read(), 'files' => $scan->files];
        $omitted = $groups->count() - count($top);
        $notes = [];

        if ($omitted > 0) {
            $notes[] = sprintf(self::MORE, $limit, $groups->count());
        }

        if ($scan->cut) {
            $time = $groups->cutTime(count($resolved->sources));
            $notes[] = $time === null
                ? sprintf(self::CUT_NO_TIME, $scan->bytes, $files)
                : sprintf(self::CUT, $scan->bytes, $files, Timestamp::iso($time));
        } elseif ($top === []) {
            $notes[] = sprintf(self::NO_ERRORS, FailedJobWindow::iso($since), $files);
        }

        if ($scan->skipped !== []) {
            $notes[] = sprintf(LogsSearch::SKIPPED, implode(' ', $scan->skipped));
        }

        if ($notes === []) {
            return ToolResult::ok($data, count($top))->withListKey('groups');
        }

        // Empty only when every file was read and nothing was skipped: a cut or a skipped part is partial (rule 11).
        if ($top === [] && ! $scan->cut && $scan->skipped === []) {
            return ToolResult::empty($notes[0], $data)->withListKey('groups');
        }

        return ToolResult::partial($data, implode(' ', $notes), $omitted > 0 ? $omitted : false, count($top))->withListKey('groups');
    }

    private function settings(): LogSettings
    {
        return new LogSettings($this->config);
    }
}
