<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Authorization;

use SinaSaeedi\RuntimeMcp\Principal;

/** A Policy with no view or viewAny method: Laravel denies both, and so does ModelPolicy. */
final class ViewlessInvoicePolicy
{
    public function update(Principal $principal, Invoice $invoice): bool
    {
        return true;
    }
}
