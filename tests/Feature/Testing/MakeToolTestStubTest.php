<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\PendingCommand;
use SinaSaeedi\RuntimeMcp\Console\MakeToolCommand;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\GeneratedTests;

/*
 * 01-39 Part D, spec 7.4: `runtime-mcp:make-tool` also writes a test that uses the kit, unless --no-test. The command
 * writes into the Testbench skeleton inside vendor/ (app/, tests/, stubs/), so every test deletes what it made. A
 * generated class cannot be unloaded: a test that loads one uses a class name that no other test makes.
 */

/** Deletes everything make-tool can write in the skeleton. */
function stubTestClean(): void
{
    File::deleteDirectory(app_path('RuntimeMcp'));
    File::deleteDirectory(base_path('stubs'));
    File::deleteDirectory(base_path(MakeToolCommand::TEST_DIRECTORY));
    File::delete(base_path('tests/Pest.php'));
    AuditLog::clear();
}

beforeEach(fn () => stubTestClean());
afterEach(fn () => stubTestClean());

/**
 * @param  array<string, mixed>  $options
 * @return array{int, string} the exit code and the whole output
 */
function stubTestRun(string $name, array $options = ['--tier' => 'read']): array
{
    $code = Artisan::call(MakeToolCommand::NAME, ['name' => $name, '--no-interaction' => true, ...$options]);

    return [$code, Artisan::output()];
}

function stubTestPath(string $class): string
{
    return base_path(MakeToolCommand::TEST_DIRECTORY."/{$class}Test.php");
}

/** The test file the package stub gives for this class, built here from the stub's placeholders. */
function stubTestExpected(string $stub, string $class, string $tool): string
{
    return str_replace(
        ['{{ toolClass }}', '{{ class }}', '{{ tool }}'],
        ["App\\RuntimeMcp\\Tools\\{$class}", $class, $tool],
        (string) file_get_contents(dirname(__DIR__, 3).'/'.$stub),
    );
}

/** The app uses Pest, by Laravel's rule: tests/Pest.php exists (Pest itself is loaded in this suite). */
function stubTestUsePest(): void
{
    File::ensureDirectoryExists(base_path('tests'));
    file_put_contents(base_path('tests/Pest.php'), "<?php\n");
}

it('TKS1 writes a PHPUnit test by default, from its stub, and names it in the next steps', function () {
    [$code, $output] = stubTestRun('StubUnitText');
    $file = (string) file_get_contents(stubTestPath('StubUnitText'));

    expect($code)->toBe(0)
        ->and($file)->toBe(stubTestExpected(MakeToolCommand::TEST_STUB_PHPUNIT, 'StubUnitText', 'stub_unit_text'))
        ->and($file)->toContain("namespace Tests\\Feature\\RuntimeMcp;\n")
        ->and($file)->toContain("use App\\RuntimeMcp\\Tools\\StubUnitText;\n")
        ->and($file)->toContain('final class StubUnitTextTest extends TestCase')
        ->and($file)->toContain("config(['runtime-mcp.tools' => [StubUnitText::class]]);")
        ->and($file)->toContain("->call('stub_unit_text')->assertDenied('gate_denied');")
        ->and(str_contains($file, '{{'))->toBeFalse()
        ->and(token_get_all($file, TOKEN_PARSE))->not->toBeEmpty()
        ->and($output)->toContain('created successfully')
        // Laravel's info component shows the path relative to the app (EnsureRelativePaths), and may wrap the line.
        ->and(str_replace(["\n", ' '], '', $output))->toContain('Test[tests/Feature/RuntimeMcp/StubUnitTextTest.php]createdsuccessfully.')
        ->and($output)->toContain(sprintf(MakeToolCommand::STEP_TEST, 'StubUnitText'))
        ->and($output)->toContain('  3. Run the new test: php artisan test --filter=StubUnitTextTest.')
        ->and(str_contains($output, MakeToolCommand::STEP_WRITE_TEST))->toBeFalse();
});

it('TKS2 writes a Pest test when the app uses Pest (Laravel\'s make:test rule)', function () {
    stubTestUsePest();

    [$code] = stubTestRun('StubPestText');
    $file = (string) file_get_contents(stubTestPath('StubPestText'));

    expect($code)->toBe(0)
        ->and($file)->toBe(stubTestExpected(MakeToolCommand::TEST_STUB_PEST, 'StubPestText', 'stub_pest_text'))
        ->and($file)->toContain("it('is denied when its Gate refuses', function () {")
        ->and(str_contains($file, 'namespace'))->toBeFalse()
        ->and(str_contains($file, '{{'))->toBeFalse()
        ->and(token_get_all($file, TOKEN_PARSE))->not->toBeEmpty();
});

it('TKS3 writes a Pest test whose own three tests pass against the generated tool', function () {
    stubTestUsePest();
    stubTestRun('StubPestRun');
    require app_path('RuntimeMcp/Tools/StubPestRun.php');

    expect(GeneratedTests::runPest(stubTestPath('StubPestRun')))->toBe([
        'keeps the tool contract', 'is denied when its Gate refuses', 'returns an envelope with a Gate',
    ])
        ->and(config('runtime-mcp.tools'))->toBe(['App\RuntimeMcp\Tools\StubPestRun'])
        ->and(array_column(AuditLog::entries(), 'decision'))->toBe(['denied', 'allowed'])
        ->and(array_column(AuditLog::entries(), 'auth_method'))->toBe(['testing', 'testing']);
});

it('TKS4 writes a PHPUnit test whose own three test methods pass against the generated tool', function () {
    stubTestRun('StubUnitRun', ['--tier' => 'read_sensitive', '--open-world' => true]);
    require app_path('RuntimeMcp/Tools/StubUnitRun.php');
    // What the generated setUp() does; it cannot run here (it would boot a second app).
    config(['runtime-mcp.tools' => ['App\RuntimeMcp\Tools\StubUnitRun']]);

    expect(GeneratedTests::runPhpUnit(stubTestPath('StubUnitRun'), 'Tests\Feature\RuntimeMcp\StubUnitRunTest'))->toBe([
        'test_it_keeps_the_tool_contract', 'test_it_is_denied_when_its_gate_refuses', 'test_it_returns_an_envelope_with_a_gate',
    ])
        ->and(array_column(AuditLog::entries(), 'decision'))->toBe(['denied', 'allowed']);
});

it('TKS5 writes no test with --no-test, and prints 01-29\'s step 3', function () {
    [$code, $output] = stubTestRun('StubNoTest', ['--tier' => 'read', '--no-test' => true]);

    expect($code)->toBe(0)
        ->and(app_path('RuntimeMcp/Tools/StubNoTest.php'))->toBeFile()
        ->and(stubTestPath('StubNoTest'))->not->toBeFile()
        ->and($output)->toContain(MakeToolCommand::STEP_WRITE_TEST)
        ->and(str_contains($output, 'Test ['))->toBeFalse()
        ->and(str_contains($output, '--filter='))->toBeFalse();
});

it('TKS6 refuses an existing test file before anything is written (exit 1), and replaces both files with --force', function () {
    File::ensureDirectoryExists(base_path(MakeToolCommand::TEST_DIRECTORY));
    file_put_contents(stubTestPath('StubKept'), '<?php // the team\'s own test');

    [$code, $output] = stubTestRun('StubKept');

    expect($code)->toBe(1)
        ->and(str_replace("\n", '', $output))->toContain('StubKeptTest.php already exists. Pass --force to replace it.')
        ->and(app_path('RuntimeMcp/Tools/StubKept.php'))->not->toBeFile()
        ->and((string) file_get_contents(stubTestPath('StubKept')))->toBe('<?php // the team\'s own test');

    [$code] = stubTestRun('StubKept', ['--tier' => 'read', '--force' => true]);

    expect($code)->toBe(0)
        ->and(app_path('RuntimeMcp/Tools/StubKept.php'))->toBeFile()
        ->and((string) file_get_contents(stubTestPath('StubKept')))->toBe(stubTestExpected(MakeToolCommand::TEST_STUB_PHPUNIT, 'StubKept', 'stub_kept'));
});

it('TKS7 keeps an existing test file with --no-test, without --force', function () {
    File::ensureDirectoryExists(base_path(MakeToolCommand::TEST_DIRECTORY));
    file_put_contents(stubTestPath('StubOwnTest'), '<?php // the team\'s own test');

    [$code] = stubTestRun('StubOwnTest', ['--tier' => 'read', '--no-test' => true]);

    expect($code)->toBe(0)
        ->and((string) file_get_contents(stubTestPath('StubOwnTest')))->toBe('<?php // the team\'s own test');
});

it('TKS8 checks the test file before it asks for the tier', function () {
    File::ensureDirectoryExists(base_path(MakeToolCommand::TEST_DIRECTORY));
    file_put_contents(stubTestPath('StubAsked'), '<?php');

    $pending = currentTest()->artisan(MakeToolCommand::NAME, ['name' => 'StubAsked']);
    assert($pending instanceof PendingCommand);

    $pending->expectsOutputToContain('already exists.')->assertExitCode(1);
});

it('TKS9 refuses with exit 1 when the test file cannot be written, and keeps the tool file', function () {
    File::ensureDirectoryExists(base_path(MakeToolCommand::TEST_DIRECTORY));
    chmod(base_path(MakeToolCommand::TEST_DIRECTORY), 0555);

    try {
        [$code, $output] = stubTestRun('StubUnwritable');
    } finally {
        chmod(base_path(MakeToolCommand::TEST_DIRECTORY), 0755);
    }

    expect($code)->toBe(1)
        ->and($output)->toContain(MakeToolCommand::TEST_NOT_WRITTEN)
        ->and(app_path('RuntimeMcp/Tools/StubUnwritable.php'))->toBeFile()
        ->and(stubTestPath('StubUnwritable'))->not->toBeFile();
});

it('TKS10 publishes both test stubs with the runtime-mcp-stubs tag, and writes from a published copy first', function (string $stub, bool $pest) {
    Artisan::call('vendor:publish', ['--tag' => 'runtime-mcp-stubs']);

    foreach (MakeToolCommand::STUBS as $each) {
        expect(base_path($each))->toBeFile()
            ->and((string) file_get_contents(base_path($each)))->toBe((string) file_get_contents(dirname(__DIR__, 3).'/'.$each));
    }

    file_put_contents(base_path($stub), "<?php\n\n// Reviewed by the platform team.\n".substr((string) file_get_contents(base_path($stub)), strlen("<?php\n\n")));

    if ($pest) {
        stubTestUsePest();
    }

    [$code] = stubTestRun($pest ? 'StubPublishedPest' : 'StubPublishedUnit');

    expect($code)->toBe(0)
        ->and((string) file_get_contents(stubTestPath($pest ? 'StubPublishedPest' : 'StubPublishedUnit')))->toStartWith("<?php\n\n// Reviewed by the platform team.\n");
})->with([
    'PHPUnit' => [MakeToolCommand::TEST_STUB_PHPUNIT, false],
    'Pest' => [MakeToolCommand::TEST_STUB_PEST, true],
]);

it('TKS11 lists the three stubs it publishes', function () {
    expect(MakeToolCommand::STUBS)->toBe(['stubs/runtime-mcp-tool.stub', 'stubs/runtime-mcp-tool-pest.stub', 'stubs/runtime-mcp-tool-test.stub'])
        ->and(dirname(__DIR__, 3).'/'.MakeToolCommand::TEST_STUB_PEST)->toBeFile()
        ->and(dirname(__DIR__, 3).'/'.MakeToolCommand::TEST_STUB_PHPUNIT)->toBeFile();
});

// Triage 2026-11-23 (review m1): next step 2 defines the tool's Gate in the app (a service provider boots it in every
// test). The generated tests must stay green after it, whether that Gate allows the test principal or refuses it.
it('TKS12 writes a Pest test that still passes once the app defines the tool\'s Gate', function () {
    stubTestUsePest();
    stubTestRun('StubPestGate');
    require app_path('RuntimeMcp/Tools/StubPestGate.php');

    foreach ([true, false] as $appAllows) {
        // What the team's AppServiceProvider does after next step 2.
        Gate::define('runtime-mcp.stub_pest_gate', fn () => $appAllows);

        expect(GeneratedTests::runPest(stubTestPath('StubPestGate')))->toHaveCount(3);
    }

    expect(array_column(AuditLog::entries(), 'decision'))->toBe(['denied', 'allowed', 'denied', 'allowed'])
        ->and(array_column(AuditLog::entries(), 'reason'))->toBe(['gate_denied', null, 'gate_denied', null]);
});

it('TKS13 writes a PHPUnit test that still passes once the app defines the tool\'s Gate', function () {
    stubTestRun('StubUnitGate');
    require app_path('RuntimeMcp/Tools/StubUnitGate.php');
    config(['runtime-mcp.tools' => ['App\RuntimeMcp\Tools\StubUnitGate']]);

    foreach ([true, false] as $appAllows) {
        Gate::define('runtime-mcp.stub_unit_gate', fn () => $appAllows);

        expect(GeneratedTests::runPhpUnit(stubTestPath('StubUnitGate'), 'Tests\Feature\RuntimeMcp\StubUnitGateTest'))->toHaveCount(3);
    }

    expect(array_column(AuditLog::entries(), 'decision'))->toBe(['denied', 'allowed', 'denied', 'allowed'])
        ->and(array_column(AuditLog::entries(), 'reason'))->toBe(['gate_denied', null, 'gate_denied', null]);
});
