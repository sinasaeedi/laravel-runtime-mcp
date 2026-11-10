<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Plugins\Queue\PayloadMask;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Queue\SendInvoiceJob;
use SinaSaeedi\RuntimeMcp\Tests\Support\FailedJobs;

/*
 * 01-24 Part B (spec 5.6 "Job payloads"): the allow-list mask of a failed job's payload. JSON only; nothing is ever
 * unserialized (PM4).
 */

const PAYLOAD_MASK_P = Redactor::PAYLOAD;

/* A payload (as JSON text), then the mask's result. */
const PAYLOAD_MASK_ROWS = [
    "Laravel's keys" => [
        '{"uuid":"u-1","displayName":"App\\\\Jobs\\\\SendInvoice","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":3,"maxExceptions":null,"failOnTimeout":false,"countCrashesAsExceptions":false,"backoff":"1,5,10","timeout":60,"retryUntil":null,"deleteWhenMissingModels":true,"createdAt":1759359600,"delay":30,"data":{"commandName":"App\\\\Jobs\\\\SendInvoice","command":"O:8:\"stdClass\":0:{}","batchId":"b-1"}}',
        ['uuid' => 'u-1', 'displayName' => 'App\\Jobs\\SendInvoice', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'maxTries' => 3, 'maxExceptions' => null, 'failOnTimeout' => false, 'countCrashesAsExceptions' => false, 'backoff' => '1,5,10', 'timeout' => 60, 'retryUntil' => null, 'deleteWhenMissingModels' => true, 'createdAt' => 1759359600, 'delay' => 30, 'data' => ['commandName' => 'App\\Jobs\\SendInvoice', 'command' => PAYLOAD_MASK_P, 'batchId' => 'b-1']],
    ],
    "RedisQueue's id and attempts" => ['{"uuid":"u-1","id":"r4nd0m","attempts":2}', ['uuid' => 'u-1', 'id' => 'r4nd0m', 'attempts' => 2]],
    'a createPayloadUsing() key' => ['{"uuid":"u-1","tenant":"acme","user_email":"ada@example.com"}', ['uuid' => 'u-1', 'tenant' => PAYLOAD_MASK_P, 'user_email' => PAYLOAD_MASK_P]],
    "Horizon's keys" => ['{"uuid":"u-1","type":"job","tags":["App\\\\Models\\\\User:42","ada@example.com"],"pushedAt":"1759359600.1234","silenced":false}', ['uuid' => 'u-1', 'type' => PAYLOAD_MASK_P, 'tags' => PAYLOAD_MASK_P, 'pushedAt' => PAYLOAD_MASK_P, 'silenced' => PAYLOAD_MASK_P]],
    'a kept key with an array value' => ['{"uuid":"u-1","backoff":{"email":"ada@example.com"},"timeout":[60]}', ['uuid' => 'u-1', 'backoff' => PAYLOAD_MASK_P, 'timeout' => PAYLOAD_MASK_P]],
    'a data key with an array value' => ['{"data":{"commandName":["ada@example.com"],"command":"x"}}', ['data' => ['commandName' => PAYLOAD_MASK_P, 'command' => PAYLOAD_MASK_P]]],
    'a string job: data with the app\'s keys' => ['{"job":"App\\\\Jobs\\\\Legacy@handle","data":{"ada@example.com":1,"order":7}}', ['job' => 'App\\Jobs\\Legacy@handle', 'data' => PAYLOAD_MASK_P]],
    'data with one more key' => ['{"data":{"commandName":"A","command":"x","note":"call ada"}}', ['data' => PAYLOAD_MASK_P]],
    'data as a string' => ['{"data":"ada@example.com"}', ['data' => PAYLOAD_MASK_P]],
    'data as a list' => ['{"data":["ada@example.com"]}', ['data' => PAYLOAD_MASK_P]],
    'data empty' => ['{"data":{}}', ['data' => PAYLOAD_MASK_P]],
];

/* A payload that is not Laravel's format: decode() gives null, so the whole payload is the marker. */
const PAYLOAD_MASK_INVALID = [
    'not JSON' => 'O:8:"stdClass":0:{}',
    'empty' => '',
    'a JSON list' => '["ada@example.com"]',
    'an empty JSON list' => '[]',
    'a JSON string' => '"ada@example.com"',
    'a number' => '42',
    'a list cut short' => '{"uuid":"u-1","data":{"command":"ada',
];

it('PM1 keeps only Laravel\'s descriptive keys, and masks every other value', function (string $case) {
    [$json, $expected] = PAYLOAD_MASK_ROWS[$case];
    $decoded = PayloadMask::decode($json);
    assert($decoded !== null);

    expect(PayloadMask::mask($decoded))->toBe($expected);
})->with(array_keys(PAYLOAD_MASK_ROWS));

it('PM2 gives null for a payload that is not a JSON object', function (string $case) {
    expect(PayloadMask::decode(PAYLOAD_MASK_INVALID[$case]))->toBeNull();
})->with(array_keys(PAYLOAD_MASK_INVALID));

it('PM3 decodes at most 31 nested arrays or objects (json_decode depth 32), and an object with leading space', function () {
    $nested = fn (int $levels): string => '{"a":'.str_repeat('[', $levels - 1).str_repeat(']', $levels - 1).'}';

    expect(PayloadMask::decode($nested(31)))->toBeArray()
        ->and(PayloadMask::decode($nested(32)))->toBeNull()
        ->and(PayloadMask::decode(" \n{\"uuid\":\"u-1\"}"))->toBe(['uuid' => 'u-1'])
        ->and(PayloadMask::decode('{}'))->toBe([])
        ->and(PayloadMask::mask([]))->toBe([]);
});

it('PM4 masks Laravel\'s real payload of a job with customer data, and never unserializes it', function () {
    currentTest()->bootWith();
    $payload = FailedJobs::realPayload(new SendInvoiceJob);
    $decoded = PayloadMask::decode($payload);
    assert($decoded !== null);
    $masked = PayloadMask::mask($decoded);
    $json = (string) json_encode($masked);

    // The real payload has the data; the mask has none of it, and keeps every key Laravel wrote.
    expect($payload)->toContain(FailedJobs::CUSTOMER_EMAIL)->toContain(FailedJobs::CUSTOMER_ADDRESS)
        ->and(array_keys($masked))->toBe(array_keys($decoded))
        ->and(array_diff(array_keys($masked), [...PayloadMask::KEPT, 'data']))->toBe([])
        ->and($masked['displayName'])->toBe(SendInvoiceJob::class)
        ->and($masked['maxTries'])->toBe(3)
        ->and($masked['timeout'])->toBe(60)
        ->and($masked['data'])->toBe(['commandName' => SendInvoiceJob::class, 'command' => PAYLOAD_MASK_P, 'batchId' => null]);
    expect($json)->not->toContain(FailedJobs::CUSTOMER_EMAIL);
    expect($json)->not->toContain('Acacia');

    // DoD 2: no code calls unserialize(), so every file of src/ (01-24 triage, review m3); no decrypt in the queue
    // plugin (model_show applies an encrypted cast on purpose: 01-21's ModelsCheck names it).
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 4).'/src', FilesystemIterator::SKIP_DOTS));
    $scanned = 0;

    foreach ($files as $file) {
        assert($file instanceof SplFileInfo);

        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = implode('', array_map(
            fn (mixed $token): string => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            token_get_all((string) file_get_contents($file->getPathname())),
        ));
        $scanned++;

        expect(stripos($code, 'unserialize'))->toBeFalse("unserialize in {$file->getPathname()}");

        if (str_contains($file->getPathname(), '/src/Plugins/Queue/')) {
            expect(stripos($code, 'decrypt'))->toBeFalse("decrypt in {$file->getPathname()}");
        }
    }

    expect($scanned)->toBeGreaterThan(100);
});
