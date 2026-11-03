<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use PHPUnit\Framework\AssertionFailedError;
use SinaSaeedi\RuntimeMcp\Plugins\Core\CorePlugin;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\ContractTool;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Tools\EchoTool;
use SinaSaeedi\RuntimeMcp\Tools\Contracts\RuntimeTool;

/*
 * 01-18 Part C, test 7: the contract helper that every tool story uses fails for each broken
 * contract, with the tool name and the check, and passes for a tool that keeps it. The cases are
 * strings: Pest calls a closure in a dataset.
 */

/** The message of the helper's failure, or null when it passed. */
function contractHelperFailure(RuntimeTool $tool, ?CorePlugin $plugin): ?string
{
    try {
        assertToolContract($tool, $plugin);
    } catch (AssertionFailedError $e) {
        return $e->getMessage();
    }

    return null;
}

/** A ContractTool that breaks one rule of the contract, named by $case. */
function contractHelperBrokenTool(string $case): ContractTool
{
    return match ($case) {
        'property without rule' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['text' => $s->string()->max(20)], []),
        'rule without property' => new ContractTool('app_fixture', fn (JsonSchema $s): array => [], ['text' => 'string|max:20']),
        'maxLength 10, max:20' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['text' => $s->string()->max(10)], ['text' => 'string|max:20']),
        'max: without schema limit' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['text' => $s->string()], ['text' => 'string|max:20']),
        'schema limit without max:' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['limit' => $s->integer()->max(100)], ['limit' => ['integer']]),
        'maxItems 5, max:6' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['with' => $s->array()->max(5)], ['with' => ['array', 'max:6']]),
        'enum against another in:' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['kind' => $s->string()->enum(['a', 'b'])], ['kind' => ['string', Rule::in(['a', 'c'])]]),
        'enum without in:' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['kind' => $s->string()->enum(['a', 'b'])], ['kind' => ['string']]),
        'in: without enum' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['kind' => $s->string()], ['kind' => 'string|in:a,b']),
        'items enum against another <key>.* in:' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['with' => $s->array()->items($s->string()->enum(['items']))], ['with' => ['array'], 'with.*' => ['in:items,customer']]),
        'items enum without <key>.* rule' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['with' => $s->array()->items($s->string()->enum(['items', 'customer']))->max(5)], ['with' => ['array', 'max:5']]),
        'items limit without <key>.* rule' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['with' => $s->array()->items($s->string()->max(10))], ['with' => 'array']),
        'nested enum without its rule key' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['filters' => $s->object(fn (JsonSchema $s): array => ['status' => $s->string()->enum(['paid'])])], ['filters' => 'array']),
        'property limit with only <key>.* rules' => new ContractTool('app_fixture', fn (JsonSchema $s): array => ['with' => $s->array()->items($s->string())->max(5)], ['with.*' => 'string']),
        'name without plugin prefix' => new ContractTool('fixture_info', fn (JsonSchema $s): array => [], []),
        'name against the name rule' => new ContractTool('app-info', fn (JsonSchema $s): array => [], []),
        default => throw new InvalidArgumentException("Unknown case [{$case}]."),
    };
}

it('passes for a tool whose schema and rules agree', function () {
    $tool = new ContractTool(
        'app_fixture',
        fn (JsonSchema $s): array => [
            'text' => $s->string()->max(20)->required(),
            'limit' => $s->integer()->max(100),
            'kind' => $s->string()->enum(['a', 'b c']),
            'with' => $s->array()->items($s->string()->enum(['items', 'customer']))->max(5),
        ],
        [
            'text' => 'required|string|max:20',
            'limit' => ['integer', 'max:100'],
            'kind' => ['string', Rule::in(['b c', 'a'])],
            'with' => ['array', 'max:5'],
            'with.*' => ['string', 'in:customer,items', fn (string $attribute, mixed $value, Closure $fail): null => null],
        ],
        opensWorld: true,
    );

    expect(contractHelperFailure($tool, new CorePlugin))->toBeNull();
});

it('passes for a custom tool with no plugin', function () {
    $custom = new ContractTool('order_status_from_erp', fn (JsonSchema $s): array => [], []);

    expect(contractHelperFailure($custom, null))->toBeNull()
        ->and(contractHelperFailure(new EchoTool, null))->toBeNull();
});

it('fails for a broken contract, with the tool name and the check', function (string $case, string $name, string $check) {
    $message = contractHelperFailure(contractHelperBrokenTool($case), new CorePlugin);

    expect($message)->toBeString()
        ->and(str_contains((string) $message, "Tool [{$name}] breaks the tool contract: "))->toBeTrue((string) $message)
        ->and(str_contains((string) $message, $check))->toBeTrue((string) $message);
})->with([
    'a schema property with no rule' => ['property without rule', 'app_fixture', 'the schema property [text] has no rule'],
    'a rule with no property' => ['rule without property', 'app_fixture', 'the rule key [text] names no schema property'],
    'maxLength 10 against max:20' => ['maxLength 10, max:20', 'app_fixture', 'the schema limits [text] to 10, but its rules say max:20'],
    'a max: rule with no schema limit' => ['max: without schema limit', 'app_fixture', 'the rules of [text] have max:20, but the schema states no limit'],
    'a schema limit with no max: rule' => ['schema limit without max:', 'app_fixture', 'the schema limits [limit] to 100, but its rules have no max: rule'],
    'maxItems 5 against max:6' => ['maxItems 5, max:6', 'app_fixture', 'the schema limits [with] to 5, but its rules say max:6'],
    'an enum against a different in:' => ['enum against another in:', 'app_fixture', 'the schema enum of [kind] is ["a","b"], but its in: rule allows ["a","c"]'],
    'an enum with no in: rule' => ['enum without in:', 'app_fixture', 'the schema has an enum for [kind], but its rules have no in: rule'],
    'an in: rule with no enum' => ['in: without enum', 'app_fixture', 'the rules of [kind] have an in: rule, but the schema has no enum'],
    'an items enum against a different key.* in:' => ['items enum against another <key>.* in:', 'app_fixture', 'the schema enum of [with.*] is ["items"]'],
    'an items enum with no key.* rule' => ['items enum without <key>.* rule', 'app_fixture', 'the schema has an enum for [with.*], but its rules have no in: rule'],
    'an items limit with no key.* rule' => ['items limit without <key>.* rule', 'app_fixture', 'the schema limits [with.*] to 10, but its rules have no max: rule'],
    'a nested property enum with no rule key' => ['nested enum without its rule key', 'app_fixture', 'the schema has an enum for [filters.status], but its rules have no in: rule'],
    'a property limit with only key.* rules' => ['property limit with only <key>.* rules', 'app_fixture', 'the schema limits [with] to 5, but its rules have no max: rule'],
    'a name without the plugin prefix' => ['name without plugin prefix', 'fixture_info', 'the name does not start with the prefix [app_] of the plugin [core]'],
    'a name that breaks the name rule' => ['name against the name rule', 'app-info', 'the name does not match'],
]);

it('throws an AssertionFailedError, so a contract test fails as a test', function () {
    expect(fn () => assertToolContract(contractHelperBrokenTool('maxLength 10, max:20'), new CorePlugin))
        ->toThrow(AssertionFailedError::class);
});
