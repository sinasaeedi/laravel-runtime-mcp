<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** 01-21 Part A: an abstract model is not a valid entry (model_show calls `new`). */
abstract class AbstractRecord extends Model {}
