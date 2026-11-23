<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/** 01-39 KitToolContractTest: the schema says at most 10 characters, the rules allow 20. */
final class MismatchTool extends RuntimeTool
{
    public function name(): string
    {
        return 'mismatch_fixture';
    }

    public function description(): string
    {
        return 'A fixture whose schema and rules disagree. text: at most 10 characters.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['text' => $schema->string()->max(10)];
    }

    public function rules(): array
    {
        return ['text' => ['string', 'max:20']];
    }

    public function execute(ToolInput $input): ToolResult
    {
        return ToolResult::ok(['text' => $input->get('text')]);
    }
}
