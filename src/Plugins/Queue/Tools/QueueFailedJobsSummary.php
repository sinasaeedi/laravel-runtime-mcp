<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Date;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobReader;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobsUnavailable;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobWindow;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * Spec 6.8, 01-23 Part C: how many jobs failed in a window, grouped by job class and by exception class. The
 * groups are counted in the database, at most 50 per list (rule 8); the payload and the trace never reach PHP.
 *
 * No list key: the cap (01-17) can mark one list only, and both lists are short (50 names of at most 255
 * characters each), so the cap would cut strings, never one list for the other.
 *
 * Built through the container on every request (also tools/list), so the constructor reads nothing.
 */
final class QueueFailedJobsSummary extends RuntimeTool
{
    public const MAX_TIME = QueueFailedJobsList::MAX_TIME;

    /** %s: from, to (ISO 8601), where it looked. */
    public const NONE = 'No failed jobs between %s and %s %s.';

    public const MORE_JOB_CLASSES = 'More than %1$d job classes failed in this window; by_job_class shows the %1$d with the most failures.';

    public const MORE_EXCEPTION_CLASSES = 'More than %1$d exception classes failed in this window; by_exception_class shows the %1$d with the most failures.';

    public function __construct(private readonly FailedJobReader $reader) {}

    public function name(): string
    {
        return 'queue_failed_jobs_summary';
    }

    public function description(): string
    {
        return 'Counts failed queue jobs in a time window, grouped by job class and by exception class (the class on '
            .'the first line of the stored exception), the 50 largest groups of each. Input: from and to, both optional '
            .'(ISO 8601 date or date-time, at most 64 characters; without an offset, the app timezone; a date-only to '
            .'includes that whole day). Default: the last 24 hours, ending now; only from runs to now; only to starts '
            .'24 hours before it.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        $time = fn (string $what) => $schema->string()
            ->description("{$what}: an ISO 8601 date or date-time, for example 2026-10-01 or 2026-10-01T09:00:00+01:00.")
            ->max(self::MAX_TIME);

        return [
            'from' => $time('The start of the window'),
            'to' => $time('The end of the window'),
        ];
    }

    public function rules(): array
    {
        $time = ['filled', 'string', 'max:'.self::MAX_TIME, 'date'];

        return ['from' => $time, 'to' => [...$time, new FailedJobWindow]];
    }

    public function execute(ToolInput $input): ToolResult
    {
        $from = $input->get('from');
        $to = $input->get('to');
        [$start, $end] = FailedJobWindow::summary(
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
            CarbonImmutable::instance(Date::now()),
        );
        $source = $this->reader->source();

        try {
            $summary = $this->reader->summary($source, $start, $end);
        } catch (FailedJobsUnavailable $e) {
            return ToolResult::unavailable($e->getMessage());
        }

        $data = [
            'from' => FailedJobWindow::iso($start),
            'to' => FailedJobWindow::iso($end),
            'total' => $summary['total'],
            'by_job_class' => $summary['by_job_class'],
            'by_exception_class' => $summary['by_exception_class'],
        ];

        if ($summary['total'] === 0) {
            return ToolResult::empty(sprintf(self::NONE, $data['from'], $data['to'], FailedJobReader::place($source)), $data);
        }

        $notes = [];

        if ($summary['more_job_classes']) {
            $notes[] = sprintf(self::MORE_JOB_CLASSES, FailedJobReader::GROUPS);
        }

        if ($summary['more_exception_classes']) {
            $notes[] = sprintf(self::MORE_EXCEPTION_CLASSES, FailedJobReader::GROUPS);
        }

        if ($summary['payload_hidden']) {
            $notes[] = sprintf(FailedJobReader::PAYLOAD_HIDDEN, (string) $source->table);
        }

        return $notes === []
            ? ToolResult::ok($data, $summary['total'])
            : ToolResult::partial($data, implode(' ', $notes), false, $summary['total']);
    }
}
