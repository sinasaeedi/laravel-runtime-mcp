<?php

declare(strict_types=1);

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\OutboundFixture;
use SinaSaeedi\RuntimeMcp\Tests\Support\OutboundCallAttempted;

it('blocks file_get_contents() on an https URL', function () {
    expect(fn () => (new OutboundFixture)->readHttps())
        ->toThrow(OutboundCallAttempted::class, 'https://example.com');
});

it('blocks fopen() on an http URL', function () {
    expect(fn () => (new OutboundFixture)->openHttp())
        ->toThrow(OutboundCallAttempted::class, 'http://example.com');
});

it('blocks copy() from an ftp URL', function () {
    expect(fn () => (new OutboundFixture)->copyFtp())
        ->toThrow(OutboundCallAttempted::class, 'ftp://example.com/x');
});

it('blocks file_get_contents() on an ftps URL', function () {
    expect(fn () => (new OutboundFixture)->readFtps())
        ->toThrow(OutboundCallAttempted::class, 'ftps://example.com/x');
});

it('blocks the Laravel HTTP client', function () {
    expect(fn () => Http::get('https://example.com'))->toThrow(StrayRequestException::class);
});

it('still reads a local file', function () {
    expect(file_get_contents(__FILE__))->toContain('still reads a local file');
});
