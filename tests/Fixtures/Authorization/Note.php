<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Authorization;

use Illuminate\Database\Eloquent\Model;

/** A model with no Policy: Gate::getPolicyFor() finds none for it (01-09). */
final class Note extends Model {}
