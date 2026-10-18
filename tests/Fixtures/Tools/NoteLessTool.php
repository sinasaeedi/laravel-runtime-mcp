<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/** Tries an empty result without a note: ToolResult throws (rule 11, 01-08). */
final class NoteLessTool extends RuntimeTool
{
    public function name(): string
    {
        return 'note_less';
    }

    public function description(): string
    {
        return 'Breaks the envelope rules.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function rules(): array
    {
        return [];
    }

    public function execute(ToolInput $input): ToolResult
    {
        return ToolResult::empty('');
    }
}
