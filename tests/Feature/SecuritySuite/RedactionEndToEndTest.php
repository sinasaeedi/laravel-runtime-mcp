<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\SecretTool;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FailedJobs;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tests\Support\LogFiles;
use SinaSaeedi\RuntimeMcp\Tests\Support\SecretSamples;
use SinaSaeedi\RuntimeMcp\Tests\Support\SecuritySuite;
use Symfony\Component\HttpFoundation\Response;

/*
 * 01-30, spec 5.6 and 11.2 ("Redaction: every built-in secret pattern"), end to end: a log file and a failed job hold
 * every sample of 01-04; the tools that read them return none of the raw secrets, anywhere in the response (the label,
 * the structured text, structuredContent), and the audit log holds none of them either, also when a secret is sent as
 * an argument; a custom and a wrapped tool, whose data only the pipeline's stage 7 redacts, show no secret either.
 */

beforeEach(fn () => SecuritySuite::clear());
afterEach(fn () => SecuritySuite::clear());

/**
 * Every raw secret that the response or the audit log still holds (the decoded text and its JSON-escaped form), whole
 * or only its end: a pattern matches from the start of a secret, so one that stops early ("Bearer
 * [redacted:bearer]\/klmn") leaves the end in the text (01-30 triage). The start is not searched: the sample names
 * in the log lines hold it ("github_token: github_pat_"). An "(accepted)" sample is a word that the engine redacts by
 * design, not a secret, so only its whole text is searched: the end of "authentication" is in "application".
 *
 * @param  TestResponse<Response>|null  $response
 * @return list<string> the names of the leaked samples
 */
function redactionLeaks(?TestResponse $response): array
{
    $haystack = ($response === null ? '' : $response->getContent().SecuritySuite::strings($response->json()))
        .implode("\n", AuditLog::lines());
    $leaks = [];

    foreach (SecretSamples::secrets() as $name => [$secret]) {
        $texts = str_ends_with($name, '(accepted)') ? [$secret] : [$secret, substr($secret, -SecretSamples::MIN_SECRET)];

        foreach ($texts as $text) {
            $escaped = substr((string) json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);

            if (str_contains($haystack, $text) || str_contains($haystack, $escaped)) {
                $leaks[] = $name;

                break;
            }
        }
    }

    return $leaks;
}

/**
 * @param  TestResponse<Response>  $response
 * @return list<string> the marker types that the response does not hold
 */
function redactionMissingMarkers(TestResponse $response): array
{
    $text = SecuritySuite::strings($response->json());

    return array_values(array_unique(array_filter(
        array_map(fn (array $secret): string => $secret[1], SecretSamples::secrets()),
        fn (string $type): bool => ! str_contains($text, "[redacted:{$type}]"),
    )));
}

/** Every sample as an error message in the line format, and as a JSON context key (small, and after 2,500 bytes). */
function redactionLogs(): void
{
    $lines = [];
    $json = [];

    foreach (SecretSamples::builtIn() as $name => [$input]) {
        $time = now()->subMinutes(30)->format('Y-m-d H:i:s');
        $lines[] = LogFiles::line($time, 'error', 'Sample '.$name.': '.$input);
        $json[] = LogFiles::json($time, 'error', 'Key sample '.$name, [$input => 'v']);
        $json[] = LogFiles::json($time, 'error', 'Late key sample '.$name, ['pad' => str_repeat('x', 2500), $input => 'v']);
    }

    LogFiles::write('app.log', $lines);
    LogFiles::write('app-json.log', $json);
}

it('RD1 logs_search returns every sample as its marker, never the secret, in the line format and as a JSON context key', function () {
    SecuritySuite::serve(LogFiles::config([LogFiles::STACK]));
    redactionLogs();

    $response = FixtureTools::call('logs_search', ['limit' => 200]);

    expect(FixtureTools::envelope($response)['status'])->toBeIn(['ok', 'partial'])
        ->and(redactionLeaks($response))->toBe([])
        ->and(redactionMissingMarkers($response))->toBe([]);
});

it('RD2 logs_recent_errors groups the same file with markers only', function () {
    SecuritySuite::serve(LogFiles::config([LogFiles::STACK]));
    redactionLogs();

    $response = FixtureTools::call('logs_recent_errors', ['limit' => 100]);

    expect(FixtureTools::envelope($response)['status'])->toBe('ok')
        ->and(redactionLeaks($response))->toBe([]);
});

it('RD3 queue_failed_job_show returns an exception that holds every sample with markers only', function () {
    SecuritySuite::serve();
    $message = implode("\n", array_map(fn (array $sample): string => $sample[0], SecretSamples::builtIn()));
    $id = FailedJobs::log('App\\Jobs\\SendInvoice', new RuntimeException($message), now()->subMinutes(5)->format('Y-m-d H:i:s'));

    $response = FixtureTools::call('queue_failed_job_show', ['id' => $id]);

    expect(FixtureTools::envelope($response)['status'])->toBe('ok')
        ->and(redactionLeaks($response))->toBe([])
        ->and(redactionMissingMarkers($response))->toBe([]);
});

it('RD4 keeps every secret out of the audit params: a sample sent as an argument is audited with its marker', function (string $tool, string $argument) {
    SecuritySuite::serve();

    // The whole sample: a pattern needs the text around a secret ("Bearer ", "scheme://user:"), as in any app data.
    foreach (SecretSamples::builtIn() as [$input]) {
        if (strlen($input) <= 200) {
            FixtureTools::call($tool, [$argument => $input]);
        }
    }

    expect(AuditLog::entries())->not->toBeEmpty()
        ->and(redactionLeaks(null))->toBe([])
        ->and(implode("\n", AuditLog::lines()))->toContain('[redacted:');
})->with([
    'logs_search contains' => ['logs_search', 'contains'],
    'queue_failed_job_show id' => ['queue_failed_job_show', 'id'],
]);

it('RD5 matches contains on the redacted text: a secret prefix finds nothing (no secret oracle)', function () {
    SecuritySuite::serve(LogFiles::config([LogFiles::STACK]));
    redactionLogs();

    $envelope = FixtureTools::envelope(FixtureTools::call('logs_search', ['contains' => 'gh'.'p_AAAAAAAAAA']));

    expect($envelope['status'])->toBe('empty');
});

it('RD6 redacts what a custom tool and a wrapped tool return: their data passes stage 7 too', function (string $tool, array $arguments) {
    SecuritySuite::serve();

    $response = FixtureTools::call($tool, $arguments);
    $text = SecuritySuite::strings($response->json());

    expect(FixtureTools::envelope($response)['status'])->toBe('ok')
        ->and($response->getContent())->not->toContain(SecretTool::JWT)
        ->and($text)->not->toContain(SecretTool::JWT)
        ->and($text)->toContain('[redacted:jwt]');
})->with([
    'a custom tool (01-29)' => ['erp_order', ['action' => 'token']],
    'a wrapped tool (01-38)' => ['app_order_lookup', ['order' => 7, 'mode' => 'jwt']],
]);
