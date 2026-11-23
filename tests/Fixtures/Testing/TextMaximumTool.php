<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Testing;

/** Breaks the contract: max: alone compares the length of the text, so 500 (3 characters) passes. */
final class TextMaximumTool extends MaximumTool
{
    protected function limitRules(): array
    {
        return ['max:100'];
    }
}
