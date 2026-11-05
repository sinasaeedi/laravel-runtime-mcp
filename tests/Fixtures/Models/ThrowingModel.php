<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** 01-21 Part E: a model whose constructor throws; doctor reports it and checks the other aliases. */
final class ThrowingModel extends Model
{
    public function __construct()
    {
        throw new RuntimeException('The constructor failed.');
    }
}
