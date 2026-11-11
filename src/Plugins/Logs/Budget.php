<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Plugins\Logs;

use LogicException;

/**
 * Spec 5.8, 01-25 Part B: the bytes that one call may read, across all its files. One instance per call (never shared:
 * rule 14).
 */
final class Budget
{
    private int $used = 0;

    public function __construct(private readonly int $bytes)
    {
        if ($bytes < 1) {
            throw new LogicException('A read budget is 1 byte or more.');
        }
    }

    /**
     * Takes up to $wanted bytes and returns how many may be read now (0 when the budget is spent).
     *
     * @return int<0, max>
     */
    public function take(int $wanted): int
    {
        $granted = max(0, min($wanted, $this->bytes - $this->used));
        $this->used += $granted;

        return $granted;
    }

    public function spent(): bool
    {
        return $this->used >= $this->bytes;
    }

    public function used(): int
    {
        return $this->used;
    }
}
