<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\SourceFiles;
use SinaSaeedi\RuntimeMcp\Tests\Support\SourceScanner;

// A token scan, not Pest's arch API: ray is not installed, so Pest would check nothing.
test('package code calls no debug function', function () {
    expect(SourceScanner::bannedCalls(SourceFiles::paths(), ['dd', 'dump', 'ray', 'var_dump']))->toBe([]);
});
