<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Integration');
pest()->group('pgsql')->in('Integration/Pgsql');
pest()->group('mysql')->in('Integration/Mysql');
