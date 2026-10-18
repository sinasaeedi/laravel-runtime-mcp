<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/** Returns a JWT in data, built by concatenation so push protection never sees a whole token (01-08). */
final class SecretTool extends RuntimeTool
{
    public function name(): string
    {
        return 'secret';
    }

    public function description(): string
    {
        return 'Returns a token.';
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

    public const JWT = 'eyJ'.'hbGciOiJIUzI1NiJ9.'.'eyJ'.'zdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

    public function execute(ToolInput $input): ToolResult
    {
        return ToolResult::ok(['token' => self::JWT]);
    }
}
