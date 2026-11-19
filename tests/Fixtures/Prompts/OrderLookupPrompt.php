<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Mcp\OrderLookupTool;

/** 01-38: a custom prompt that needs the wrapped tool `app_order_lookup`, so prompts.tools must see wrapped tools. */
final class OrderLookupPrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'order_lookup_prompt';
    }

    public function title(): string
    {
        return 'Order lookup';
    }

    public function description(): string
    {
        return 'Looks up one order the way support sees it.';
    }

    public function tools(): array
    {
        return [OrderLookupTool::NAME];
    }

    public function message(PromptInput $input): string
    {
        return 'Call app_order_lookup with the order id.';
    }
}
