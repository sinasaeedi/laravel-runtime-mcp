<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\PendingCommand;
use SinaSaeedi\RuntimeMcp\Console\MakeToolCommand;
use SinaSaeedi\RuntimeMcp\Tests\Support\AuditLog;
use SinaSaeedi\RuntimeMcp\Tests\Support\FixtureTools;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;
use SinaSaeedi\RuntimeMcp\Tools\Tier;

/*
 * Spec 7.4 (01-29): php artisan runtime-mcp:make-tool <Name>. The command writes under app_path(), which is the
 * Testbench skeleton inside vendor/ (vendor/orchestra/testbench-core/laravel/app, namespace App\), so every test
 * deletes app/RuntimeMcp and stubs/ after itself. PHP cannot unload a class: a test that requires a generated file
 * uses a class name that no other test generates. Artisan::call() is interactive unless --no-interaction is passed.
 * Helpers start with "makeTool".
 */

beforeEach(function () {
    File::deleteDirectory(app_path('RuntimeMcp'));
    File::deleteDirectory(base_path('stubs'));
    File::deleteDirectory(base_path(MakeToolCommand::TEST_DIRECTORY)); // 01-39: make-tool writes a test by default
});

afterEach(function () {
    File::deleteDirectory(app_path('RuntimeMcp'));
    File::deleteDirectory(base_path('stubs'));
    File::deleteDirectory(base_path(MakeToolCommand::TEST_DIRECTORY));
    AuditLog::clear();
});

/**
 * @param  array<string, mixed>  $options
 * @return array{int, string} the exit code and the whole output
 */
function makeToolRun(string $name, array $options = []): array
{
    $code = Artisan::call(MakeToolCommand::NAME, ['name' => $name, '--no-interaction' => true, ...$options]);

    return [$code, Artisan::output()];
}

/** @param  array<string, mixed>  $parameters */
function makeToolAsk(array $parameters): PendingCommand
{
    $pending = currentTest()->artisan(MakeToolCommand::NAME, $parameters);

    if (! $pending instanceof PendingCommand) {
        throw new LogicException('mockConsoleOutput is off.');
    }

    return $pending;
}

/**
 * A dataset's options with string keys: PHPStan reads no PHPDoc on a Pest closure (01-22 trap).
 *
 * @param  array<array-key, mixed>  $options
 * @return array<string, mixed>
 */
function makeToolOptions(array $options): array
{
    $typed = [];

    foreach ($options as $key => $value) {
        $typed[(string) $key] = $value;
    }

    return $typed;
}

function makeToolPath(string $class): string
{
    return app_path("RuntimeMcp/Tools/{$class}.php");
}

/** The file the package stub gives for these answers, built here from the stub's placeholders. */
function makeToolExpected(string $class, string $tool, string $tier, string $openWorld, string $namespace = 'App\RuntimeMcp\Tools'): string
{
    $stub = (string) file_get_contents(dirname(__DIR__, 3).'/'.MakeToolCommand::STUB);

    return str_replace(
        ['{{ namespace }}', '{{ class }}', '{{ tool }}', '{{ tier }}', '{{ openWorld }}'],
        [$namespace, $class, $tool, $tier, $openWorld],
        $stub,
    );
}

it('MT1 writes a read_sensitive open-world tool from the stub, prints the next steps, and exits 0', function () {
    [$code, $output] = makeToolRun('OrderStatusFromErp', ['--tier' => 'read_sensitive', '--open-world' => true]);
    $file = (string) file_get_contents(makeToolPath('OrderStatusFromErp'));

    expect($code)->toBe(0)
        ->and($file)->toBe(makeToolExpected('OrderStatusFromErp', 'order_status_from_erp', 'ReadSensitive', 'true'))
        ->and($file)->toContain("namespace App\\RuntimeMcp\\Tools;\n")
        ->and($file)->toContain('final class OrderStatusFromErp extends RuntimeTool')
        ->and($file)->toContain("return 'order_status_from_erp';")
        ->and($file)->toContain('return Tier::ReadSensitive;')
        ->and($file)->toContain("public function opensWorld(): bool\n    {\n        return true;")
        ->and(str_contains($file, '{{'))->toBeFalse()
        // Spec 5.9 (01-29 triage M1): only the default and the listed connections are read-only, so say so.
        ->and($file)->toContain("Database writes are blocked on the default connection and on\n    // each connection in runtime-mcp.read_only_transactions.connections")
        ->and($output)->toContain('created successfully')
        ->and($output)->toContain(sprintf(MakeToolCommand::TOOL_NAME, 'order_status_from_erp', 'runtime-mcp.order_status_from_erp'))
        ->and($output)->toContain(MakeToolCommand::NEXT)
        ->and($output)->toContain('  1. Add App\RuntimeMcp\Tools\OrderStatusFromErp::class to runtime-mcp.tools in config/runtime-mcp.php.')
        ->and($output)->toContain("  2. Define the Gate: Gate::define('runtime-mcp.order_status_from_erp', fn (Principal|User \$who) => …); // Principal: SinaSaeedi\\RuntimeMcp\\Principal")
        ->and($output)->toContain(sprintf(MakeToolCommand::STEP_TEST, 'OrderStatusFromErp'))
        ->and($output)->toContain(MakeToolCommand::CLAIM)
        ->and($output)->toContain('blocks database writes inside the tool on the default connection and on each connection in runtime-mcp.read_only_transactions.connections')
        ->and(str_contains($output, MakeToolCommand::OPEN_WORLD_HINT))->toBeFalse();
});

it('MT2 writes a read tool that calls no external system, and prints the open-world hint', function () {
    [$code, $output] = makeToolRun('AppVersionTool', ['--tier' => 'read']);

    expect($code)->toBe(0)
        ->and((string) file_get_contents(makeToolPath('AppVersionTool')))->toBe(makeToolExpected('AppVersionTool', 'app_version', 'Read', 'false'))
        ->and($output)->toContain(sprintf(MakeToolCommand::TOOL_NAME, 'app_version', 'runtime-mcp.app_version'))
        ->and($output)->toContain(MakeToolCommand::OPEN_WORLD_HINT);
});

it('MT3 writes a file that loads, keeps the tool contract, and runs through the server as unavailable', function (string $class, array $options, string $tool, Tier $tier, bool $openWorld) {
    [$code] = makeToolRun($class, makeToolOptions($options));
    expect($code)->toBe(0);

    require makeToolPath($class);
    $fqcn = 'App\\RuntimeMcp\\Tools\\'.$class;
    $instance = app($fqcn);
    assert($instance instanceof RuntimeTool);

    assertToolContract($instance); // no plugin: a custom tool has no prefix (01-18)
    expect($instance->name())->toBe($tool)
        ->and($instance->tier())->toBe($tier)
        ->and($instance->opensWorld())->toBe($openWorld);

    currentTest()->bootWith(['runtime-mcp.enabled' => true, 'runtime-mcp.tools' => [$fqcn]]);
    Gate::define('runtime-mcp.'.$tool, fn (): bool => true);

    expect(FixtureTools::envelope(FixtureTools::call($tool)))->toBe([
        'environment' => 'testing',
        'status' => 'unavailable',
        'note' => 'Not implemented yet.',
        'truncated' => false,
        'data' => null,
    ]);
})->with([
    'read' => ['ContractVersionTool', ['--tier' => 'read'], 'contract_version', Tier::Read, false],
    'read_sensitive, open world' => ['ContractErpLookup', ['--tier' => 'read_sensitive', '--open-world' => true], 'contract_erp_lookup', Tier::ReadSensitive, true],
]);

it('MT4 refuses a bad name or tier with exit 1, one error line, and no file', function (string $name, array $options, string $message) {
    [$code, $output] = makeToolRun($name, makeToolOptions($options));

    expect($code)->toBe(1)
        ->and($output)->toContain($message)
        ->and(File::isDirectory(app_path('RuntimeMcp')))->toBeFalse()
        ->and(str_contains($output, 'created successfully'))->toBeFalse();
})->with([
    'a dash' => ['order-status', ['--tier' => 'read'], MakeToolCommand::BAD_CLASS],
    'a folder' => ['Erp/OrderStatus', ['--tier' => 'read'], MakeToolCommand::BAD_CLASS],
    'a namespace' => ['App\Erp', ['--tier' => 'read'], MakeToolCommand::BAD_CLASS],
    'lower case' => ['orderStatus', ['--tier' => 'read'], MakeToolCommand::BAD_CLASS],
    'a space' => ['Order Status', ['--tier' => 'read'], MakeToolCommand::BAD_CLASS],
    'only Tool' => ['Tool', ['--tier' => 'read'], 'The tool name from Tool would be "", which is not lower snake case of at most 64 characters. Choose another class name.'],
    'a name over 64' => [str_repeat('Ab', 33), ['--tier' => 'read'], 'which is not lower snake case of at most 64 characters.'],
    'a PHP word' => ['Class', ['--tier' => 'read'], 'The name Class is reserved by PHP.'],
    'write_safe' => ['OrderRetry', ['--tier' => 'write_safe'], MakeToolCommand::WRITE_TIER],
    'another tier' => ['OrderStatus', ['--tier' => 'admin'], MakeToolCommand::BAD_TIER],
    'an empty tier' => ['OrderStatus', ['--tier' => ''], MakeToolCommand::BAD_TIER],
    'no tier and no terminal' => ['OrderStatus', [], MakeToolCommand::TIER_NEEDED],
]);

it('MT5 keeps an existing file without --force (exit 1), and replaces it with --force', function () {
    makeToolRun('OrderNote', ['--tier' => 'read']);
    $path = makeToolPath('OrderNote');
    file_put_contents($path, '<?php // the team\'s own work');

    [$code, $output] = makeToolRun('OrderNote', ['--tier' => 'read_sensitive']);

    expect($code)->toBe(1)
        ->and($output)->toContain('already exists. Pass --force to replace it.')
        ->and((string) file_get_contents($path))->toBe('<?php // the team\'s own work');

    [$code] = makeToolRun('OrderNote', ['--tier' => 'read_sensitive', '--force' => true]);

    expect($code)->toBe(0)
        ->and((string) file_get_contents($path))->toBe(makeToolExpected('OrderNote', 'order_note', 'ReadSensitive', 'false'));
});

it('MT6 checks the file before it asks for the tier', function () {
    makeToolRun('OrderNote', ['--tier' => 'read']);

    makeToolAsk(['name' => 'OrderNote'])
        ->expectsOutputToContain('already exists.')
        ->assertExitCode(1);
});

it('MT7 publishes the stub with the runtime-mcp-stubs tag and then writes from the published copy', function () {
    Artisan::call('vendor:publish', ['--tag' => 'runtime-mcp-stubs']);
    $published = base_path(MakeToolCommand::STUB);

    expect($published)->toBeFile()
        ->and((string) file_get_contents($published))->toBe((string) file_get_contents(dirname(__DIR__, 3).'/'.MakeToolCommand::STUB));

    file_put_contents($published, str_replace('final class', "// Reviewed by the platform team.\nfinal class", (string) file_get_contents($published)));
    [$code] = makeToolRun('OrderAudit', ['--tier' => 'read']);

    expect($code)->toBe(0)
        ->and((string) file_get_contents(makeToolPath('OrderAudit')))->toContain("// Reviewed by the platform team.\nfinal class OrderAudit extends RuntimeTool");
});

it('MT8 asks for the tier and the external system in a terminal', function (string $tier, string $openWorld, string $expectedTier, string $expectedOpenWorld) {
    makeToolAsk(['name' => 'AskedErp'])
        ->expectsOutputToContain(MakeToolCommand::TIER_HELP[0])
        ->expectsOutputToContain(MakeToolCommand::TIER_HELP[1])
        ->expectsChoice(MakeToolCommand::TIER_QUESTION, $tier, ['read', 'read_sensitive'])
        ->expectsConfirmation(MakeToolCommand::OPEN_WORLD_QUESTION, $openWorld)
        ->assertExitCode(0);

    expect((string) file_get_contents(makeToolPath('AskedErp')))->toBe(makeToolExpected('AskedErp', 'asked_erp', $expectedTier, $expectedOpenWorld));
})->with([
    'read, no' => ['read', 'no', 'Read', 'false'],
    'read_sensitive, yes' => ['read_sensitive', 'yes', 'ReadSensitive', 'true'],
]);

it('MT9 asks no question for an answer given as an option', function () {
    makeToolAsk(['name' => 'OptionErp', '--tier' => 'read', '--open-world' => true])
        ->doesntExpectOutputToContain(MakeToolCommand::TIER_HELP[0])
        ->assertExitCode(0);

    expect((string) file_get_contents(makeToolPath('OptionErp')))->toContain('return true;');
});

it('MT10 derives the tool name from the class: snake case, without one trailing Tool', function (string $class, string $tool) {
    expect(MakeToolCommand::toolName($class))->toBe($tool);
})->with([
    ['OrderStatusFromErp', 'order_status_from_erp'],
    ['OrderStatusTool', 'order_status'],
    ['PingTools', 'ping_tools'],
    ['ToolTool', 'tool'],
    ['Order2Fa', 'order2_fa'],
    ['ERPOrderTool', 'e_r_p_order'],
    ['Tool', ''],
]);

it('MT11 prints a surprising tool name, so the author can change it', function () {
    [$code, $output] = makeToolRun('ERPOrderTool', ['--tier' => 'read']);

    expect($code)->toBe(0)
        ->and($output)->toContain(sprintf(MakeToolCommand::TOOL_NAME, 'e_r_p_order', 'runtime-mcp.e_r_p_order'))
        ->and((string) file_get_contents(makeToolPath('ERPOrderTool')))->toContain("return 'e_r_p_order';");
});

it('MT12 uses the app\'s own root namespace', function () {
    (new ReflectionProperty(app(), 'namespace'))->setValue(app(), 'Acme\\');

    [$code, $output] = makeToolRun('AcmeLookup', ['--tier' => 'read']);

    expect($code)->toBe(0)
        ->and((string) file_get_contents(makeToolPath('AcmeLookup')))->toBe(makeToolExpected('AcmeLookup', 'acme_lookup', 'Read', 'false', 'Acme\RuntimeMcp\Tools'))
        ->and($output)->toContain('  1. Add Acme\RuntimeMcp\Tools\AcmeLookup::class to runtime-mcp.tools');
});

it('MT13 is registered as an Artisan command', function () {
    expect(Artisan::all())->toHaveKey(MakeToolCommand::NAME);
});
