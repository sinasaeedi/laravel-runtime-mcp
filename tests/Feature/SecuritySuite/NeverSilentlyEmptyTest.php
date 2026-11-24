<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobReader;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\FailedJobSource;
use SinaSaeedi\RuntimeMcp\Plugins\Queue\Tools\QueueOverview;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\SecuritySuite;

/*
 * 01-30, spec 14 ("No tool returns an empty result when it could not look (a test proves it)"), rule 11, spec 11.2
 * ("Envelope"): every tool of the package that reads an outside source, in the case where it cannot look, answers
 * `unavailable` with the note that says why, never `empty`. The note is asserted exactly: on SQLite a database tool is
 * `unavailable` for its driver, which would pass for the wrong reason. The database tools' cases are in
 * tests/Integration/{Pgsql,Mysql}/SecuritySuite/NeverSilentlyEmptyTest.php; app_info reads no outside source.
 */

beforeEach(fn () => SecuritySuite::clear());
afterEach(fn () => SecuritySuite::clear());

const NEVER_EMPTY_STDERR = 'Channel `stderr` writes to stderr, so there is no file to read.';

/**
 * case => [tool, more config, the failed-job file content (null: leave the seeded file), the note].
 *
 * @var array<string, array{string, array<string, mixed>, string|null, string}>
 */
const NEVER_EMPTY_CASES = [
    'logs_search: a channel with no file' => ['logs_search', ['runtime-mcp.logs.channels' => ['stderr']], null, NEVER_EMPTY_STDERR],
    'logs_recent_errors: a channel with no file' => ['logs_recent_errors', ['runtime-mcp.logs.channels' => ['stderr']], null, NEVER_EMPTY_STDERR],
    'queue_overview: the sync driver' => ['queue_overview', ['queue.default' => 'sync'], null, 'Connection `sync` runs jobs immediately, so it has no queue to measure.'],
    'queue_failed_jobs_list: no failed-job store' => ['queue_failed_jobs_list', ['queue.failed.driver' => null], null, FailedJobSource::NULL_DRIVER],
    'queue_failed_jobs_summary: no failed-job store' => ['queue_failed_jobs_summary', ['queue.failed.driver' => null], null, FailedJobSource::NULL_DRIVER],
    'queue_failed_job_show: no failed-job store' => ['queue_failed_job_show', ['queue.failed.driver' => null], null, FailedJobSource::NULL_DRIVER],
    'queue_failed_jobs_list: a file Laravel cannot read' => ['queue_failed_jobs_list', [], 'not json', FailedJobReader::FILE_FORMAT],
    'queue_failed_jobs_summary: a file Laravel cannot read' => ['queue_failed_jobs_summary', [], 'not json', FailedJobReader::FILE_FORMAT],
    'queue_failed_job_show: a file Laravel cannot read' => ['queue_failed_job_show', [], 'not json', FailedJobReader::FILE_FORMAT],
];

it('NE1 has a "could not look" case for every tool of the package that reads an outside source', function () {
    SecuritySuite::serve();
    $package = array_values(array_diff(array_keys(SecuritySuite::registeredTools()), SecuritySuite::APP_TOOLS));
    $covered = [...array_column(NEVER_EMPTY_CASES, 0), ...SecuritySuite::DATABASE_TOOLS, ...SecuritySuite::NO_SOURCE];

    expect(array_values(array_unique($covered)))->toEqualCanonicalizing($package)
        ->and(sprintf(QueueOverview::NO_QUEUE['sync'], 'sync'))->toBe(NEVER_EMPTY_CASES['queue_overview: the sync driver'][3]);
});

it('NE2 answers unavailable with the reason, never empty, when the tool could not look', function (string $case) {
    [$tool, $config, $file, $note] = NEVER_EMPTY_CASES[$case];
    SecuritySuite::serve($config);

    if ($file !== null) {
        file_put_contents(SecuritySuite::failedJobsFile(), $file);
    }

    expect(FixtureTools::envelope(FixtureTools::call($tool, SecuritySuite::TOOLS[$tool][0])))->toBe([
        'environment' => SecuritySuite::ENVIRONMENT, 'status' => 'unavailable', 'note' => $note, 'truncated' => false, 'data' => null,
    ]);
})->with(array_keys(NEVER_EMPTY_CASES));
