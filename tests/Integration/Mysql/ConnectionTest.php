<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SinaSaeedi\RuntimeMcp\Tests\Support\Databases;

beforeEach(fn () => Databases::requiresMysql());

// The driver and the server must agree, so a CI cell proves the database it claims to test.
it('talks to the server that the driver names', function () {
    $connection = DB::connection('testing_mysql');
    $version = $connection->scalar('select version()');

    expect($version)->toBeString();

    if ($connection->getDriverName() === 'mariadb') {
        expect($version)->toContain('MariaDB');
    } else {
        expect($version)->not->toContain('MariaDB');
    }
});
