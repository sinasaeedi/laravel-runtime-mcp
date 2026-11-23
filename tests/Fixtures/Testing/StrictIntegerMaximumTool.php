<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing;

/** Keeps the contract: integer:strict counts (the rule name is the text before ":"). */
final class StrictIntegerMaximumTool extends MaximumTool
{
    protected function limitRules(): array
    {
        return ['integer:strict', 'max:100'];
    }
}
