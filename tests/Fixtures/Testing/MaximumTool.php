<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * 01-39 KitToolContractTest: a custom tool with schema `limit: integer, maximum 100`. Each subclass gives the rules of
 * `limit`, so RuntimeMcp::assertToolContract() is checked by class name, as a team calls it.
 */
abstract class MaximumTool extends RuntimeTool
{
    /** @return list<string>|string the rules of `limit` */
    abstract protected function limitRules(): array|string;

    public function name(): string
    {
        return 'maximum_fixture';
    }

    public function description(): string
    {
        return 'A fixture with one integer limit. limit: at most 100.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->max(100)];
    }

    public function rules(): array
    {
        return ['limit' => $this->limitRules()];
    }

    public function execute(ToolInput $input): ToolResult
    {
        return ToolResult::ok(['limit' => $input->get('limit')]);
    }
}
