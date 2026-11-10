<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobReader;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobsUnavailable;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * Spec 5.6 "Job payloads", 6.7, 01-24 Part C: one failed job, with its full redacted exception, and its payload with
 * the job data replaced by [redacted:payload] (PayloadMask). read_sensitive: the trace is free text from the app.
 *
 * Built through the container on every request (also tools/list), so the constructor reads nothing.
 */
final class QueueFailedJobShow extends RuntimeTool
{
    public const MAX_ID = 64;

    /** A UUID or a decimal id, as queue_failed_jobs_list returns them. JSON Schema form: no delimiters, no flags. */
    public const ID_PATTERN = '^[A-Za-z0-9-]{1,64}$';

    /** %s: the id (it matched ID_PATTERN, so it holds no backtick), then where it looked ("in table `failed_jobs`"). */
    public const NOT_FOUND = 'No failed job with id `%s` %s.';

    public function __construct(private readonly FailedJobReader $reader) {}

    public function name(): string
    {
        return 'queue_failed_job_show';
    }

    public function description(): string
    {
        return 'Shows one failed queue job by its id (from queue_failed_jobs_list; a UUID or a number, at most 64 '
            .'characters): id, job_class, queue, connection, attempts (null when the queue driver does not record them '
            .'in the job), failed_at (ISO 8601), and the full exception with its stack trace (redacted; at most 200,000 '
            .'characters). The payload is returned with its job data replaced by [redacted:payload]; the serialized job '
            .'is never returned.';
    }

    public function tier(): Tier
    {
        return Tier::ReadSensitive;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('The id from queue_failed_jobs_list: a UUID, or a number.')
                ->max(self::MAX_ID)
                ->pattern(self::ID_PATTERN)
                ->required(),
        ];
    }

    public function rules(): array
    {
        // /D: without it, $ also matches before a final "\n" (DbSchema's trap).
        return ['id' => ['required', 'string', 'max:'.self::MAX_ID, 'regex:/'.self::ID_PATTERN.'/D']];
    }

    public function execute(ToolInput $input): ToolResult
    {
        $id = $input->get('id');
        $id = is_string($id) ? $id : '';
        $source = $this->reader->source();

        try {
            $job = $this->reader->find($source, $id);
        } catch (FailedJobsUnavailable $e) {
            return ToolResult::unavailable($e->getMessage());
        }

        if ($job === null) {
            return ToolResult::empty(sprintf(self::NOT_FOUND, $id, FailedJobReader::place($source)));
        }

        return $job['notes'] === []
            ? ToolResult::ok($job['job'], 1)
            : ToolResult::partial($job['job'], implode(' ', $job['notes']), $job['truncated'], 1);
    }
}
