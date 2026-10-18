<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Authorization;

use Illuminate\Foundation\Auth\User;

/** Typed User only: PHP throws a TypeError for an unlinked key, whose subject is a Principal. */
final class UserOnlyInvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return true;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }
}
