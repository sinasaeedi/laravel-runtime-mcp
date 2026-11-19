<?php

declare(strict_types=1);

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Content\ResourceLink;
use SinaSaeedi\RuntimeMcp\Mcp\Wrapped\ResponseConverter;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\SecretTool;
use SinaSaeedi\RuntimeMcp\Tools\Pipeline\RunTool;
use SinaSaeedi\RuntimeMcp\Tools\Status;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/*
 * 01-38 Part C: what an official handle() returns, as a ToolResult. Each step of ResponseConverter::convert(), in
 * order: collect (a Generator, laravel/mcp's array and string rules), error, classify, data, result.
 */

function convertWrapped(mixed $value): ToolResult
{
    return app(ResponseConverter::class)->convert($value);
}

/** @return array{string, mixed, ?string, int|false} status, data, note, truncated */
function convertedParts(ToolResult $result): array
{
    return [$result->status->value, $result->data, $result->note, $result->truncated];
}

function linkItem(): Response
{
    return Response::resourceLink(new ResourceLink(uri: 'file:///order.pdf', name: 'order.pdf'));
}

it('RC1 turns text into data: a JSON object or array decoded, any other text under `text`', function (mixed $value, array $data) {
    expect(convertedParts(convertWrapped($value)))->toBe(['ok', $data, null, false]);
})->with([
    'a plain string' => ['Order 7 shipped', ['text' => 'Order 7 shipped']],
    'Response::text' => [Response::text('Order 7 shipped'), ['text' => 'Order 7 shipped']],
    'a JSON object' => [Response::text('{"id":1}'), ['id' => 1]],
    'Response::json of a list' => [Response::json([1, 2]), [1, 2]],
    'a JSON scalar stays text' => [Response::text('7'), ['text' => '7']],
    'broken JSON stays text' => [Response::text('{"id":'), ['text' => '{"id":']],
    'two texts, one blank line between' => [[Response::text('{"a":1}'), 'b'], ['text' => "{\"a\":1}\n\nb"]],
    'one factory as the only item' => [[Response::make(Response::text('x'))], ['text' => 'x']],
]);

it('RC2 takes structuredContent first and never reads its text copy again', function () {
    $factory = Response::make([Response::text('Summary for a human')])->withStructuredContent(['id' => 7]);

    expect(convertedParts(convertWrapped(Response::structured(['id' => 7, 'state' => 'shipped']))))->toBe(['ok', ['id' => 7, 'state' => 'shipped'], null, false])
        ->and(convertedParts(convertWrapped($factory)))->toBe(['ok', ['id' => 7], null, false]);
});

it('RC3 leaves out content that is not text and says so: partial, with the count as truncated', function (mixed $value, mixed $data, string $note, int $left) {
    expect(convertedParts(convertWrapped($value)))->toBe(['partial', $data, $note, $left]);
})->with([
    'text and an image' => [[Response::text('Order 7'), Response::image('iVBORw0KGgo=')], ['text' => 'Order 7'], ResponseConverter::LEFT_OUT_ONE, 1],
    'only an image: data [], never empty' => [[Response::image('iVBORw0KGgo=')], [], ResponseConverter::LEFT_OUT_ONE, 1],
    'audio, a blob and a link' => [[Response::audio('UklGRg=='), Response::blob('raw'), linkItem()], [], sprintf(ResponseConverter::LEFT_OUT, 3), 3],
    'a string with a NUL byte is a blob' => ["a\0b", [], ResponseConverter::LEFT_OUT_ONE, 1],
    'structured content and an image' => [Response::make([Response::image('iVBORw0KGgo=')])->withStructuredContent(['id' => 7]), ['id' => 7], ResponseConverter::LEFT_OUT_ONE, 1],
]);

it('RC4 says the tool returned no content only when nothing was there', function (mixed $value) {
    expect(convertedParts(convertWrapped($value)))->toBe(['empty', [], ResponseConverter::NO_CONTENT, false]);
})->with([
    'an empty list' => [[]],
    'an empty factory' => [Response::make([])],
    'only a notification' => [[Response::notification('notifications/progress', ['progress' => 1])]],
]);

it('RC5 makes an error unavailable, with the error texts joined, redacted whole and then cut to 300 characters', function () {
    // The JWT starts at character 265 of the joined text and ends after 300, so a cut before the redaction would leave a
    // part of it that no pattern matches.
    $text = str_repeat('x', 254).' '.SecretTool::JWT.' and more';
    $result = convertWrapped([Response::error('Not found'), Response::error($text), Response::text('kept out')]);
    $expected = mb_substr('Not found '.str_repeat('x', 254).' [redacted:jwt] and more', 0, RunTool::MAX_MESSAGE);

    expect($result->status)->toBe(Status::Unavailable)
        ->and($result->note)->toBe('The tool returned an error: '.$expected)
        ->and(str_contains((string) $result->note, 'eyJ'))->toBeFalse()
        ->and($result->data)->toBeNull()
        ->and(convertWrapped(Response::error(str_repeat('y', 400)))->note)->toBe('The tool returned an error: '.str_repeat('y', RunTool::MAX_MESSAGE));
});

it('RC6 reads a Generator to its end and drops its notifications', function () {
    $stream = (function (): Generator {
        yield Response::notification('notifications/progress', ['progress' => 1, 'total' => 2]);
        yield Response::text('Order 7');
        yield 'shipped';
    })();

    $factory = (function (): Generator {
        yield Response::notification('notifications/progress', ['progress' => 1]);
        yield Response::structured(['id' => 7]);
    })();

    expect(convertedParts(convertWrapped($stream)))->toBe(['ok', ['text' => "Order 7\n\nshipped"], null, false])
        ->and(convertedParts(convertWrapped($factory)))->toBe(['ok', ['id' => 7], null, false]);
});

it('RC7 refuses a value that laravel/mcp would refuse too', function (mixed $value) {
    expect(fn () => convertWrapped($value))->toThrow(LogicException::class, ResponseConverter::NOT_A_RESPONSE);
})->with([
    'an int' => [42],
    'null' => [null],
    'two factories' => [[Response::make(Response::text('a')), Response::make(Response::text('b'))]],
    'a factory beside a response' => [[Response::make(Response::text('a')), Response::text('b')]],
    'an object' => [new stdClass],
]);
