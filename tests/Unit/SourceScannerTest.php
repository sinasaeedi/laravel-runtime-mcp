<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\SourceScanner;

it('finds only real calls and imports of banned global functions', function () {
    $code = <<<'PHP'
        <?php
        namespace A;
        use function curl_init;
        $a = curl_init('x');
        $b = \curl_exec($a);
        $c = $obj->curl_init();
        $d = Foo::dump();
        function dump() {}
        $e = 'curl_init';
        $f = new dd();
        $g = DUMP ($x);
        $h = fsockopen(...);
        // curl_init() in a comment
        $i = $obj?->ray();
        class K { public function var_dump() {} const dd = 1; }
        PHP;

    $found = SourceScanner::bannedCallsInCode(
        $code,
        ['curl_init', 'curl_exec', 'dump', 'dd', 'fsockopen', 'ray', 'var_dump'],
    );

    expect($found)->toBe([
        ['curl_init', 3],
        ['curl_init', 4],
        ['curl_exec', 5],
        ['dump', 11],
        ['fsockopen', 12],
    ]);
});
