<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;

beforeEach(fn () => Databases::requiresPostgres());

it('talks to PostgreSQL', function () {
    expect(DB::connection('testing_pgsql')->scalar('select version()'))
        ->toBeString()
        ->toContain('PostgreSQL');
});
