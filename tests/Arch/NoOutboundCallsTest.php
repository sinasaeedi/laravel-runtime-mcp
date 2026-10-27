<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\SourceFiles;
use SinaSaeedi\RuntimeMcp\Tests\Support\SourceScanner;

// Spec rule 4: the package code makes no outbound calls. The runtime half lives in
// TestCase::setUp() (Http::preventStrayRequests() and OutboundGuard). This file is the static half.

arch('package code uses no HTTP client and no mailer (rule 4)', function () {
    expect('SinaSaeedi\RuntimeMcp')->not->toUse([
        'Illuminate\Support\Facades\Http', 'Illuminate\Http\Client', 'GuzzleHttp',
        'Illuminate\Support\Facades\Mail', 'Illuminate\Mail', 'Illuminate\Contracts\Mail',
    ]);
});

// Pest's arch check is silently green for a name that resolves to nothing.
// The root alias \Http has no class file, so Pest cannot see it; Http::preventStrayRequests() catches it at run time.
test('the banned HTTP client and mailer classes exist', function () {
    expect(class_exists('Illuminate\Support\Facades\Http'))->toBeTrue()
        ->and(class_exists('Illuminate\Http\Client\Factory'))->toBeTrue()
        ->and(class_exists('GuzzleHttp\Client'))->toBeTrue()
        ->and(class_exists('Illuminate\Support\Facades\Mail'))->toBeTrue()
        ->and(class_exists('Illuminate\Mail\Mailer'))->toBeTrue()
        ->and(interface_exists('Illuminate\Contracts\Mail\Mailer'))->toBeTrue();
});

// curl, socket, FTP, mail and DNS functions do not use stream wrappers, so the runtime guard cannot
// see them. A UDP packet (socket_sendto) needs no socket_connect(), and a DNS lookup can carry data out.
// Known limit: a function name in a string (call_user_func('curl_init')) is not found.
test('package code calls no network function (rule 4)', function () {
    expect(SourceScanner::bannedCalls(SourceFiles::paths(), [
        'curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_connect',
        'socket_sendto', 'ftp_connect', 'ftp_ssl_connect', 'mail', 'mb_send_mail',
        'dns_get_record', 'dns_get_mx', 'dns_check_record', 'checkdnsrr', 'getmxrr', 'gethostbyname', 'gethostbynamel',
    ]))->toBe([]);
});

// Pest's arch API ignores PHP's own classes, so it cannot ban SoapClient: the token scan does.
test('package code uses no SOAP client (rule 4)', function () {
    expect(SourceScanner::bannedClasses(SourceFiles::paths(), ['SoapClient']))->toBe([]);
});
