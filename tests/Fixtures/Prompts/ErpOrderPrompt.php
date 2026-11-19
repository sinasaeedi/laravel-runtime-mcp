<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Prompts;

use SinaSaeedi\RuntimeMcp\Prompts\Contracts\RuntimePrompt;
use SinaSaeedi\RuntimeMcp\Prompts\PromptInput;

/** 01-29: a custom prompt that needs the custom tool `erp_order`, so prompts.tools must see the custom tools. */
final class ErpOrderPrompt extends RuntimePrompt
{
    public function name(): string
    {
        return 'erp_order_prompt';
    }

    public function title(): string
    {
        return 'ERP order';
    }

    public function description(): string
    {
        return 'Looks up one order in the ERP.';
    }

    public function tools(): array
    {
        return ['erp_order'];
    }

    public function message(PromptInput $input): string
    {
        return 'Call erp_order with action status.';
    }
}
