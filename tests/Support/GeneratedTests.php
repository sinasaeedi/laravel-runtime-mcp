<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Closure;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use SinaSaeedi\RuntimeMcp\Tests\TestCase;

/**
 * 01-39: runs the tests of a file that `runtime-mcp:make-tool` generated, inside the running package test, against the
 * current app. The file itself cannot run as a test here: the Testbench skeleton has no Tests\TestCase and no
 * tests/Pest.php, and a Pest file required while a test runs registers tests that never run.
 *
 * A Pest file is evaluated in a namespace of its own, where beforeEach() and it() are functions that keep the closures
 * (PHP resolves an unqualified function in the current namespace first). Then each it() runs after every beforeEach().
 * A PHPUnit file is loaded with Tests\TestCase as an alias of the package's TestCase; each public test_ method runs on
 * an instance made without its constructor, so setUp() does not run and the caller sets what setUp() sets.
 */
final class GeneratedTests
{
    /** @var list<Closure> */
    public static array $before = [];

    /** @var array<string, Closure> */
    public static array $tests = [];

    /** @return list<string> the descriptions of the tests that ran, in order */
    public static function runPest(string $path): array
    {
        self::$before = [];
        self::$tests = [];
        // Not under SinaSaeedi\RuntimeMcp: Pest's arch tests read the file of every function in that namespace, and
        // eval'd code has none (StrictTypesTest: "Path must not be empty").
        $namespace = 'RuntimeMcpGeneratedTests\Run'.bin2hex(random_bytes(6));
        $capture = '\\'.self::class;

        eval("namespace {$namespace}; function beforeEach(\\Closure \$c): void { {$capture}::\$before[] = \$c; } function it(string \$d, \\Closure \$c): void { {$capture}::\$tests[\$d] = \$c; }");
        eval("namespace {$namespace}; ".self::withoutOpenTag($path));

        return self::runCaptured();
    }

    /** @return list<string> */
    private static function runCaptured(): array
    {
        foreach (self::$tests as $test) {
            foreach (self::$before as $before) {
                $before();
            }

            $test();
        }

        return array_keys(self::$tests);
    }

    /**
     * @param  string  $class  the generated test class
     * @return list<string> the test methods that ran, in order
     */
    public static function runPhpUnit(string $path, string $class): array
    {
        if (! class_exists('Tests\TestCase', false)) {
            class_alias(TestCase::class, 'Tests\TestCase');
        }

        require_once $path;

        if (! class_exists($class, false)) {
            throw new LogicException("[{$path}] does not declare [{$class}].");
        }

        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $ran = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test_') && $method->getDeclaringClass()->getName() === $class) {
                $method->invoke($instance);
                $ran[] = $method->getName();
            }
        }

        return $ran;
    }

    private static function withoutOpenTag(string $path): string
    {
        $code = (string) file_get_contents($path);

        if (! str_starts_with($code, "<?php\n")) {
            throw new LogicException("[{$path}] does not start with <?php.");
        }

        return substr($code, strlen("<?php\n"));
    }
}
