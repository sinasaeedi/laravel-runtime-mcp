<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing;

/** Keeps the contract: decimal:0,2 counts (the rule name is the text before ":"). */
final class DecimalMaximumTool extends MaximumTool
{
    protected function limitRules(): string
    {
        return 'decimal:0,2|max:100';
    }
}
