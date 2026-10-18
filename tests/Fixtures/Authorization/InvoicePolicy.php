<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use SinaSaeedi\RuntimeMcp\Principal;

/** Typed Principal|Authenticatable, as the docs advise: only the key "sina" may see invoices. */
final class InvoicePolicy
{
    public function view(Principal|Authenticatable $who, Invoice $invoice): Response
    {
        return self::sina($who) ? Response::allow() : Response::deny('Only sina may see invoices.');
    }

    /** The class-string argument of viewAny() is removed by Laravel: the Policy gets the user only. */
    public function viewAny(Principal|Authenticatable $who): bool
    {
        return self::sina($who);
    }

    private static function sina(Principal|Authenticatable $who): bool
    {
        return $who instanceof Principal && $who->name === 'sina';
    }
}
