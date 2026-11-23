<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing;

/** Keeps the contract: numeric makes max: compare the number. */
final class NumericMaximumTool extends MaximumTool
{
    protected function limitRules(): array
    {
        return ['numeric', 'max:100'];
    }
}
