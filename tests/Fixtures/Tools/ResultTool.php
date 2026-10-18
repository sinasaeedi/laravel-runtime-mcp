<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/** Returns one result per kind, for the envelope tests. Read sensitive, and it opens the world (01-08). */
final class ResultTool extends RuntimeTool
{
    public function name(): string
    {
        return 'result';
    }

    public function description(): string
    {
        return 'Returns the result that kind names.';
    }

    public function tier(): Tier
    {
        return Tier::ReadSensitive;
    }

    public const KINDS = ['ok', 'empty', 'partial_counted', 'partial_unknown', 'unavailable', 'invalid_utf8', 'infinite', 'text'];

    public function opensWorld(): bool
    {
        return true;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['kind' => $schema->string()->enum(self::KINDS)->required()];
    }

    public function rules(): array
    {
        return ['kind' => ['required', 'string', 'in:'.implode(',', self::KINDS)]];
    }

    public function execute(ToolInput $input): ToolResult
    {
        return match ($input->get('kind')) {
            'ok' => ToolResult::ok(['rows' => [[1], [2]]], 2),
            'empty' => ToolResult::empty('No match in laravel-2026-10-01.log.'),
            'partial_counted' => ToolResult::partial(['rows' => [[1]]], 'The last 3 rows were omitted.', 3, 1),
            'partial_unknown' => ToolResult::partial(['rows' => [[1]]], 'More than 1 row matches; showing the first 1.', false, 1),
            'unavailable' => ToolResult::unavailable('Channel `stderr` writes to stdout, so there is no file to read.'),
            'invalid_utf8' => ToolResult::ok(['line' => "caf\xE9 au lait"]),
            'infinite' => ToolResult::ok(['ratio' => INF]),
            default => ToolResult::ok(['url' => 'https://app.test/orders/1', 'name' => 'Zoë']),
        };
    }
}
