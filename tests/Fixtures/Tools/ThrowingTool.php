<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use RuntimeException;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/** Throws with a password URL in the message (01-08), or with the message the call sends. */
final class ThrowingTool extends RuntimeTool
{
    public function name(): string
    {
        return 'throwing';
    }

    public function description(): string
    {
        return 'Always fails.';
    }

    public function tier(): Tier
    {
        return Tier::Read;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['message' => $schema->string()->description('The exception message. MESSAGE when not sent.')];
    }

    public function rules(): array
    {
        return ['message' => ['sometimes', 'string']];
    }

    public const MESSAGE = 'db password is hunter2 at postgres://u:p@h';

    public function execute(ToolInput $input): ToolResult
    {
        $message = $input->get('message');

        throw new RuntimeException(is_string($message) ? $message : self::MESSAGE);
    }
}
