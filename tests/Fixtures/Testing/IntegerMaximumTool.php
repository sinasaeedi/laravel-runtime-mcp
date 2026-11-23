<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing;

/** Keeps the contract: integer makes max: compare the number. */
final class IntegerMaximumTool extends MaximumTool
{
    protected function limitRules(): array
    {
        return ['integer', 'max:100'];
    }
}
