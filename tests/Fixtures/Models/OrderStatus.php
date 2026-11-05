<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

/** 01-21: an enum cast; model_show returns its value. OrderPolicy denies `blocked` and throws for `boom`. */
enum OrderStatus: string
{
    case Paid = 'paid';
    case Blocked = 'blocked';
    case Boom = 'boom';
}
