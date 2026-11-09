<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobFilter;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobReader;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobsUnavailable;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobWindow;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * Spec 6.6, 01-23 Part B: the newest failed jobs, with a one-line reason each. The payload is never selected
 * (01-24 shows one job). Rows are limited in the database (rule 8): limit + 1, and the extra row says "more".
 *
 * Built through the container on every request (also tools/list), so the constructor reads nothing.
 */
final class QueueFailedJobsList extends RuntimeTool
{
    public const MAX_NAME = 255;

    public const MAX_TIME = 64;

    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    /** %s: where it looked ("in table `failed_jobs`"). */
    public const NONE = 'No failed jobs %s.';

    /** %s: the filters in input order, then where it looked. */
    public const NO_MATCH = 'No failed jobs match %s %s.';

    /** The extra row proves that more match, not how many: truncated stays false (principle 1.4.8). */
    public const MORE = 'More failed jobs match; showing the newest %d. Narrow with from and to, or job_class.';

    public function __construct(private readonly FailedJobReader $reader) {}

    public function name(): string
    {
        return 'queue_failed_jobs_list';
    }

    public function description(): string
    {
        return 'Lists failed queue jobs, newest first: id, job_class, queue, connection, failed_at (ISO 8601) and the '
            .'first line of the exception (redacted, at most 300 characters). The job payload is never returned. '
            .'Filters, all optional and exact: job_class (as queue_failed_jobs_summary shows it), queue, connection '
            .'(each at most 255 characters), from and to (ISO 8601 date or date-time, at most 64 characters; without an '
            .'offset, the app timezone; a date-only to includes that whole day). limit: 1 to 100, default 25.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        $name = fn (string $what) => $schema->string()->description("The exact {$what}.")->max(self::MAX_NAME);
        $time = fn (string $what) => $schema->string()
            ->description("{$what}: an ISO 8601 date or date-time, for example 2026-10-01 or 2026-10-01T09:00:00+01:00.")
            ->max(self::MAX_TIME);

        return [
            'job_class' => $name('job class, for example App\\Jobs\\SendInvoice'),
            'queue' => $name('queue name'),
            'connection' => $name('queue connection name'),
            'from' => $time('The earliest failure time'),
            'to' => $time('The latest failure time'),
            'limit' => $schema->integer()->description('How many jobs to return. Default 25.')->min(1)->max(self::MAX_LIMIT),
        ];
    }

    public function rules(): array
    {
        // filled: the validator skips the other rules for "", which would filter for an empty name (01-19).
        $name = ['filled', 'string', 'max:'.self::MAX_NAME];
        $time = ['filled', 'string', 'max:'.self::MAX_TIME, 'date'];

        return [
            'job_class' => $name,
            'queue' => $name,
            'connection' => $name,
            'from' => $time,
            'to' => [...$time, new FailedJobWindow],
            // numeric refuses true, which integer takes as 1 (as db_query).
            'limit' => ['integer', 'numeric', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function execute(ToolInput $input): ToolResult
    {
        $text = fn (string $key): ?string => is_string($input->get($key)) ? $input->get($key) : null;
        $from = $text('from');
        $to = $text('to');
        $limit = $input->get('limit');
        $limit = is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;

        $filter = new FailedJobFilter(
            jobClass: $text('job_class'),
            queue: $text('queue'),
            connection: $text('connection'),
            from: $from === null ? null : FailedJobWindow::bound($from, end: false),
            to: $to === null ? null : FailedJobWindow::bound($to, end: true),
        );
        $source = $this->reader->source();

        try {
            $page = $this->reader->list($source, $filter, $limit);
        } catch (FailedJobsUnavailable $e) {
            return ToolResult::unavailable($e->getMessage());
        }

        $data = ['jobs' => $page['jobs']];
        $count = count($page['jobs']);

        if ($count === 0) {
            $filters = $filter->describe();
            $place = FailedJobReader::place($source);

            return ToolResult::empty($filters === '' ? sprintf(self::NONE, $place) : sprintf(self::NO_MATCH, $filters, $place), $data)
                ->withListKey('jobs');
        }

        $notes = [];

        if ($page['more']) {
            $notes[] = sprintf(self::MORE, $count);
        }

        if ($page['payload_hidden']) {
            $notes[] = sprintf(FailedJobReader::PAYLOAD_HIDDEN, (string) $source->table);
        }

        return $notes === []
            ? ToolResult::ok($data, $count)->withListKey('jobs')
            : ToolResult::partial($data, implode(' ', $notes), false, $count)->withListKey('jobs');
    }
}
