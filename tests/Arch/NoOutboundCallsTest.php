<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\SourceFiles;
use SinaSaeedi\RuntimeMcp\Tests\Support\SourceScanner;

// Spec rule 4: the package code makes no outbound calls. The runtime half lives in
// TestCase::setUp() (Http::preventStrayRequests() and OutboundGuard). This file is the static half.

arch('package code uses no HTTP client (rule 4)', function () {
    expect('SinaSaeedi\RuntimeMcp')
        ->not->toUse(['Illuminate\Support\Facades\Http', 'Illuminate\Http\Client', 'GuzzleHttp']);
});

// Pest's arch check is silently green for a name that resolves to nothing.
// The root alias \Http has no class file, so Pest cannot see it; Http::preventStrayRequests() catches it at run time.
test('the banned HTTP client classes exist', function () {
    expect(class_exists('Illuminate\Support\Facades\Http'))->toBeTrue()
        ->and(class_exists('Illuminate\Http\Client\Factory'))->toBeTrue()
        ->and(class_exists('GuzzleHttp\Client'))->toBeTrue();
});

// curl and socket functions do not use stream wrappers, so the runtime guard cannot see them.
// Known limit: a function name in a string (call_user_func('curl_init')) is not found.
test('package code calls no curl or socket function (rule 4)', function () {
    expect(SourceScanner::bannedCalls(SourceFiles::paths(), [
        'curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_connect',
    ]))->toBe([]);
});
