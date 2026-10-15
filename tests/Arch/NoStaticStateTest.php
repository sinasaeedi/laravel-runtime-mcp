<?php

declare(strict_types=1);

use SinaSaeedi\RuntimeMcp\Tests\Support\SourceFiles;

// Spec rule 14: a static property that holds request data leaks between requests under Octane.
// Only the statics a class declares count: inherited framework statics (ServiceProvider has six)
// are not ours. A static from a trait we use counts as ours. There is no allow-list: a story that
// needs a static property changes this test and says why.
test('no class in src/ declares a static property (rule 14)', function () {
    expect(SourceFiles::paths())->not->toBeEmpty();

    $missing = [];
    $statics = [];

    foreach (SourceFiles::classNames() as $name) {
        // Autoload once: Composer includes the file again on each autoload, so a file whose class
        // name differs from its path would be included twice and stop the run with a fatal error.
        if (! class_exists($name) && ! interface_exists($name, false) && ! trait_exists($name, false) && ! enum_exists($name, false)) {
            $missing[] = $name;

            continue;
        }

        $reflection = new ReflectionClass($name);

        foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            if ($property->getDeclaringClass()->getName() === $reflection->getName()) {
                $statics[] = $name.'::$'.$property->getName();
            }
        }
    }

    expect($missing)->toBe([], 'These names do not exist, so a file path and its class name differ (PSR-4).')
        ->and($statics)->toBe([], 'These classes declare a static property (spec rule 14).');
});
