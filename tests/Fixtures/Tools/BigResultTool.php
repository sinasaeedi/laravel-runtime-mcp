<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/** Returns a large result of the kind it is asked for, for the output cap tests (01-17). */
final class BigResultTool extends RuntimeTool
{
    public const KINDS = ['rows', 'secrets', 'wire', 'infinite'];

    /** One GitHub-shaped token, as the redaction engine finds it (01-04). */
    public const TOKEN = 'ghp_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function name(): string
    {
        return 'big_result';
    }

    public function description(): string
    {
        return 'Returns a large result of the kind that kind names.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(self::KINDS)->required(),
            'count' => $schema->integer()->min(1)->max(100_000),
        ];
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:'.implode(',', self::KINDS)],
            'count' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function execute(ToolInput $input): ToolResult
    {
        $count = $input->get('count', 100);
        $count = is_int($count) ? $count : 100;

        return match ($input->get('kind')) {
            // Each row is about 60 bytes of JSON.
            'rows' => ToolResult::ok(['columns' => ['id', 'name'], 'rows' => array_map(
                fn (int $i): array => [$i, 'customer number '.str_pad((string) $i, 30, '0', STR_PAD_LEFT)],
                range(1, $count),
            )], $count)->withListKey('rows'),
            // Back-to-back tokens: a cut before redaction would leave a part of one that no pattern matches.
            'secrets' => ToolResult::ok(['text' => str_repeat(self::TOKEN.' ', $count)]),
            // Quotes, backslashes, slashes and multi-byte text: JSON escaping makes them larger on the wire.
            'wire' => ToolResult::ok(['rows' => array_map(
                fn (int $i): array => ['path' => "/var/www/app/storage/{$i}.log", 'quote' => 'say "hi" \\ bye', 'name' => 'Zoë 漢字 😀'],
                range(1, $count),
            )], $count)->withListKey('rows'),
            default => ToolResult::ok(['text' => str_repeat('a', 5_000), 'ratio' => INF]),
        };
    }
}
