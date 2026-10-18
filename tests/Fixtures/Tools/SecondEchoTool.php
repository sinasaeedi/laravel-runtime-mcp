<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/** A second echo tool, for two inner calls of execute_tools (01-08). */
final class SecondEchoTool extends RuntimeTool
{
    public function name(): string
    {
        return 'second_echo';
    }

    public function description(): string
    {
        return 'Sends the text back. text: at most 100 characters.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['text' => $schema->string()->max(100)->description('The text to send back.')->required()];
    }

    public function rules(): array
    {
        return ['text' => ['required', 'string', 'max:100']];
    }

    public function execute(ToolInput $input): ToolResult
    {
        return ToolResult::ok(['text' => $input->get('text')], 1);
    }
}
