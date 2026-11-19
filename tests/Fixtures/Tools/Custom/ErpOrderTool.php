<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\Custom;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\SecretTool;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;
use SinaSaeedi\RuntimeMcp\Tools\ToolInput;
use SinaSaeedi\RuntimeMcp\Tools\ToolResult;

/**
 * 01-29: a team tool that "reads an ERP", registered through runtime-mcp.tools and not through a plugin. `action`
 * picks what its app code does: `status` answers, `insert` writes to the default connection (01-16 blocks it), `token`
 * returns a JWT from the "ERP" (redaction hides it).
 */
final class ErpOrderTool extends RuntimeTool
{
    public const ACTIONS = ['status', 'insert', 'token'];

    public function name(): string
    {
        return 'erp_order';
    }

    public function description(): string
    {
        return 'Returns the state of one order in the ERP. action: status, insert or token.';
    }

    public function tier(): Tier
    {
        return Tier::ReadSensitive;
    }

    public function opensWorld(): bool
    {
        return true;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['action' => $schema->string()->enum(self::ACTIONS)->required()];
    }

    public function rules(): array
    {
        return ['action' => ['required', 'string', 'in:'.implode(',', self::ACTIONS)]];
    }

    public function execute(ToolInput $input): ToolResult
    {
        return match ($input->get('action')) {
            'insert' => ToolResult::ok(['inserted' => DB::table('ro_orders')->insert(['n' => 'erp'])]),
            'token' => ToolResult::ok(['state' => 'shipped', 'token' => SecretTool::JWT]),
            default => ToolResult::ok(['state' => 'shipped']),
        };
    }
}
