<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs\Tools;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\Budget;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogChannelResolver;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogEntry;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogEntryParser;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogScan;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogSettings;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\LogSource;
use SinaSaeedi\RuntimeMcp\Plugins\Logs\RedactedEntry;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobWindow;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * Spec 6.9, 01-25 Part D: log entries by level, time and text, newest first, read from the end of each file within
 * logs.max_bytes. Every result that is not ok says which files it searched, or why it could not look (rule 11).
 *
 * Built through the container on every request (also tools/list): the constructor reads nothing, and the Redactor
 * (which throws for a bad extra pattern) is made in execute().
 */
final class LogsSearch extends RuntimeTool
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    public const MAX_CONTAINS = 200;

    public const MAX_TIME = 64;

    /** The schema's limit for a channel when no channel is valid (no enum then), as LogSettings::NAME. */
    public const MAX_CHANNEL = 64;

    public const NO_CHANNELS = 'No log channel is set for logs_search. Set runtime-mcp.logs.channels to channel names from config/logging.php.';

    /** %d the limit. */
    public const MORE = 'More entries match; showing the newest %d. Narrow with from, to, level or contains.';

    /** %1$d the bytes read, %2$s the files. */
    public const CUT = 'Read the last %1$d bytes (%2$s); older entries were not searched.';

    /** %s the files. */
    public const NO_MATCH = 'No match in %s.';

    /** %s every reason, joined by a space. */
    public const SKIPPED = 'Not searched: %s';

    /** A stack whose channels were all met before (`stack` → [`stack`]): %s the channel. */
    public const NOTHING = '%s resolves to no log file.';

    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
        private readonly LogChannelResolver $resolver,
        private readonly LogScan $scan,
    ) {}

    public function name(): string
    {
        return 'logs_search';
    }

    public function description(): string
    {
        return 'Searches the log files of one channel, newest entry first: time (ISO 8601), level, env, channel, file, '
            .'message and context (redacted; a context over 2,000 bytes is cut). It reads from the end of each file, at '
            .'most runtime-mcp.logs.max_bytes bytes per call (default 200,000), so a long search can stop early: then '
            .'the status is partial and the note says which files it read. The status is never empty when it could '
            .'not read everything. Input, all optional: channel (one of runtime-mcp.logs.channels, default the first), '
            .'level (the lowest level to return: debug, info, notice, warning, error, critical, alert, emergency), from '
            .'and to (ISO 8601 date or date-time, at most 64 characters; without an offset, the app timezone; a '
            .'date-only to includes that whole day), contains (at most 200 characters, case-insensitive, in the message '
            .'or the context), limit (1 to 200, default 50).';
    }

    public function tier(): Tier
    {
        return Tier::ReadSensitive;
    }

    public function schema(JsonSchema $schema): array
    {
        $channels = $this->settings()->channels();
        $channel = $channels === [] ? $schema->string()->max(self::MAX_CHANNEL) : $schema->string()->enum($channels);
        $time = fn (string $what) => $schema->string()
            ->description("{$what}: an ISO 8601 date or date-time, for example 2026-10-01 or 2026-10-01T09:00:00+01:00.")
            ->max(self::MAX_TIME);

        return [
            'channel' => $channel->description('The log channel, from runtime-mcp.logs.channels. Default: the first.'),
            'level' => $schema->string()->enum(LogEntryParser::LEVELS)->description('The lowest level to return.'),
            'from' => $time('The earliest entry time'),
            'to' => $time('The latest entry time'),
            'contains' => $schema->string()->description('Text in the message or the context, case-insensitive.')->max(self::MAX_CONTAINS),
            'limit' => $schema->integer()->description('How many entries to return. Default 50.')->min(1)->max(self::MAX_LIMIT),
        ];
    }

    public function rules(): array
    {
        $channels = $this->settings()->channels();
        // filled: the validator skips the other rules for "" (01-19).
        $time = ['filled', 'string', 'max:'.self::MAX_TIME, 'date'];

        return [
            'channel' => $channels === [] ? ['filled', 'string', 'max:'.self::MAX_CHANNEL] : ['filled', 'string', Rule::in($channels)],
            'level' => ['filled', 'string', Rule::in(LogEntryParser::LEVELS)],
            'from' => $time,
            'to' => [...$time, new FailedJobWindow],
            'contains' => ['filled', 'string', 'max:'.self::MAX_CONTAINS],
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
        $from = $text('from');
        $to = $text('to');
        $level = $text('level');
        $contains = $text('contains');
        $limit = $input->get('limit');
        $limit = is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;
        $lowest = $level === null ? null : LogEntryParser::rank($level);
        $redactor = $this->app->make(Redactor::class);

        $keep = function (LogEntry $entry, LogSource $source, string $file) use ($lowest, $contains, $redactor): ?array {
            if ($lowest !== null && LogEntryParser::rank($entry->level) < $lowest) {
                return null;
            }

            $shown = RedactedEntry::of($entry, $redactor);

            return $contains === null || $shown->contains($contains) ? $shown->toArray($source->channel, $file) : null;
        };

        $scan = $this->scan->run(
            $this->resolver->resolve($channel),
            $from === null ? null : FailedJobWindow::bound($from, end: false),
            $to === null ? null : FailedJobWindow::bound($to, end: true),
            new Budget($settings->maxBytes()),
            $keep,
            $limit + 1,
        );

        if ($scan->files === []) {
            return ToolResult::unavailable($scan->skipped === [] ? sprintf(self::NOTHING, LogChannelResolver::shown($channel)) : implode(' ', $scan->skipped));
        }

        $more = count($scan->kept) > $limit;
        $entries = array_slice($scan->kept, 0, $limit);
        $data = ['entries' => $entries];
        $notes = [];

        if ($more) {
            $notes[] = sprintf(self::MORE, $limit);
        }

        if ($scan->cut) {
            $notes[] = sprintf(self::CUT, $scan->bytes, self::join($scan->files));
        } elseif ($entries === []) {
            $notes[] = sprintf(self::NO_MATCH, self::join($scan->files));
        }

        if ($scan->skipped !== []) {
            $notes[] = sprintf(self::SKIPPED, implode(' ', $scan->skipped));
        }

        if ($notes === []) {
            return ToolResult::ok($data, count($entries))->withListKey('entries');
        }

        // Empty only when every file was read and nothing was skipped: a cut or a skipped part is partial (rule 11).
        if ($entries === [] && ! $scan->cut && $scan->skipped === []) {
            return ToolResult::empty($notes[0], $data)->withListKey('entries');
        }

        return ToolResult::partial($data, implode(' ', $notes), false, count($entries))->withListKey('entries');
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param  list<string>  $names
     */
    public static function join(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }

    private function settings(): LogSettings
    {
        return new LogSettings($this->config);
    }
}
