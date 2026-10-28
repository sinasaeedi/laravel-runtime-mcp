<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Boot;

use SinaSaeedi\RuntimeMcp\Security\Boot\BootRule;

/** A plugin rule with a bug: check() returns an array that is not a list of BootIssue. */
final class InvalidResultRule implements BootRule
{
    public const ID = 'fixture.invalid';

    /** @param  array<mixed>  $result */
    public function __construct(private readonly array $result = ['Horizon is not configured.']) {}

    public function id(): string
    {
        return self::ID;
    }

    public function check(): array
    {
        // @phpstan-ignore return.type (the bug this fixture stands for)
        return $this->result;
    }
}
