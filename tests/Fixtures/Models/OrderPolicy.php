<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;
use SinaSaeedi\RuntimeMcp\Principal;

/** 01-21: denies a blocked order with a message, and throws for a `boom` order (gate_error). */
final class OrderPolicy
{
    public function view(Principal|Authenticatable $who, Order $order): Response
    {
        return match ($order->status) {
            OrderStatus::Blocked => Response::deny('Blocked orders are private.'),
            OrderStatus::Boom => throw new RuntimeException('The order policy failed.'),
            default => Response::allow(),
        };
    }
}
