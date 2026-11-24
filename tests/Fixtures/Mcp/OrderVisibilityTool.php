<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * 01-40 triage: an official tool whose shouldRegister() reads the Request, as laravel/mcp's docs allow. It hides itself
 * for one order. Inside execute_tools, ToolSearch::invokeTool() asks it with the inner call's Request bound, so the
 * pre-scan must ask it the same way: with the outer {"calls": [...]} Request it would see no order at all.
 */
#[Description('Shows one order, except the one order that is hidden. order: the order id.')]
final class OrderVisibilityTool extends Tool
{
    public const NAME = 'app_order_visibility';

    public const HIDDEN_ORDER = 13;

    public function shouldRegister(Request $request): bool
    {
        return $request->get('order') !== self::HIDDEN_ORDER;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'order' => $schema->integer()->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        return Response::json(['id' => $request->integer('order')]);
    }
}
