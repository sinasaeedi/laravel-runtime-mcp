<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 01-21: a SoftDeletes model whose deleted-at column the role cannot read (remember_token is masked). The
 * SoftDeletes scope would name it in every query, so model_show reports it before the query.
 */
final class SoftUser extends Model
{
    use SoftDeletes;

    public const DELETED_AT = 'remember_token';

    protected $table = 'users';
}
