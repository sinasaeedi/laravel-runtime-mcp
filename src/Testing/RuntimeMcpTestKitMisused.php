<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Testing;

use LogicException;

/**
 * The test kit was called where it must not run (spec 7.5, 01-39 Part C). It sets a principal without a key, so it runs
 * only while the app runs its tests: app()->runningUnitTests(), which is APP_ENV=testing.
 */
final class RuntimeMcpTestKitMisused extends LogicException
{
    public const OUTSIDE_TESTS = 'The Runtime MCP test kit runs only in your tests.';

    public const NO_PHPUNIT = 'The Runtime MCP test kit needs PHPUnit or Pest.';

    public static function outsideTests(): self
    {
        return new self(self::OUTSIDE_TESTS);
    }

    public static function noPhpUnit(): self
    {
        return new self(self::NO_PHPUNIT);
    }
}
