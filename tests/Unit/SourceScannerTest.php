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

it('finds every banned import in a use function list, but not an alias', function () {
    $code = <<<'PHP'
        <?php
        use function strlen, curl_exec as ce, \curl_init;
        use function strlen as dump;
        $a = ce('x');
        PHP;

    expect(SourceScanner::bannedCallsInCode($code, ['curl_exec', 'curl_init', 'dump']))->toBe([
        ['curl_exec', 2],
        ['curl_init', 2],
    ]);
});

it('fails on a file it cannot read, instead of scanning it as empty', function () {
    expect(fn () => SourceScanner::bannedCalls([__DIR__.'/missing-file.php'], ['dd']))
        ->toThrow(RuntimeException::class, 'Cannot read');
});

it('finds every use of a banned class, but not a member or an alias with its name', function () {
    $code = <<<'PHP'
        <?php
        namespace A;
        use SoapClient;
        $a = new \SoapClient(null, []);
        function f(soapclient $c): void {}
        $b = SoapClient::class;
        $c = $obj->SoapClient();
        $d = Foo::SoapClient;
        $e = 'SoapClient';
        use Foo\Bar as SoapClient;
        class K { public function SoapClient() {} const SoapClient = 1; }
        PHP;

    expect(SourceScanner::bannedClassesInCode($code, ['SoapClient']))->toBe([
        ['SoapClient', 3],
        ['SoapClient', 4],
        ['soapclient', 5],
        ['SoapClient', 6],
    ]);
});
