<?php

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Support\Arrayable;
use SinaSaeedi\RuntimeMcp\Security\Redaction\InvalidRedactionPattern;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Patterns;
use SinaSaeedi\RuntimeMcp\Security\Redaction\Redactor;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Redaction\SampleStatus;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Redaction\SampleSuit;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Redaction\SelfSerializing;

/*
 * Every secret sample is fake and built by concatenation, so GitHub push protection never sees a
 * whole key in the source. Pest test-file functions are global, so each helper name says "redaction".
 */

/** A redactor over runtime-mcp.redaction.extra_patterns = $extra, without the container. */
function redactionEngine(mixed $extra = []): Redactor
{
    return new Redactor(new ConfigRepository(['runtime-mcp' => ['redaction' => ['extra_patterns' => $extra]]]));
}

function redactionJwt(): string
{
    return 'eyJ'.'hbGciOiJIUzI1NiJ9.'.'eyJ'.'zdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
}

function redactionPem(string $kind, bool $withEnd = true): string
{
    $block = '-----BEGIN '.$kind.' PRIVATE KEY-----'."\nMIIEowIBAAKCAQEAfakefakefake\nZmFrZWZha2U=\n";

    return $withEnd ? $block.'-----END '.$kind.' PRIVATE KEY-----' : $block;
}

/** @return array<string, array{string, string}> input => exact output, one row per built-in pattern and form */
function redactionBuiltInSamples(): array
{
    $sanctum = '7|'.str_repeat('Ab3x', 10).'8cdc1683';

    return [
        'private_key: RSA block' => ['key: '.redactionPem('RSA').' tail', 'key: [redacted:private_key] tail'],
        'jwt' => ['token='.redactionJwt().'; next', 'token=[redacted:jwt]; next'],
        'aws_access_key: AKIA' => ['id AKIA'.'IOSFODNN7EXAMPLE end', 'id [redacted:aws_access_key] end'],
        'aws_access_key: ASIA' => ['id ASIA'.'IOSFODNN7EXAMPLE end', 'id [redacted:aws_access_key] end'],
        'stripe_key: sk_live_' => ['stripe sk_'.'live_'.str_repeat('0', 24).' failed', 'stripe [redacted:stripe_key] failed'],
        'stripe_key: rk_live_' => ['rk_'.'live_'.str_repeat('0', 24), '[redacted:stripe_key]'],
        'github_token: ghp_' => ['gh'.'p_'.str_repeat('A', 36), '[redacted:github_token]'],
        'github_token: gho_' => ['gh'.'o_'.str_repeat('B', 36), '[redacted:github_token]'],
        'github_token: ghs_' => ['gh'.'s_'.str_repeat('C', 36), '[redacted:github_token]'],
        'github_token: github_pat_' => ['github'.'_pat_'.str_repeat('A', 22).'_'.str_repeat('b', 59), '[redacted:github_token]'],
        'slack_token' => ['slack xox'.'b-1234567890-abcdefghij ok', 'slack [redacted:slack_token] ok'],
        'anthropic_key' => ['sk-'.'ant-api03-'.str_repeat('a', 40), '[redacted:anthropic_key]'],
        'openai_key: sk-proj-' => ['sk-'.'proj-'.str_repeat('b', 40), '[redacted:openai_key]'],
        'openai_key: sk-' => ['key sk-'.str_repeat('c', 48), 'key [redacted:openai_key]'],
        'openai_key: after a hyphen (accepted)' => ['task-sk-'.'abcdefghijklmnopqrstuv', 'task-[redacted:openai_key]'],
        'runtime_mcp_key' => ['leaked rmcp_'.str_repeat('Q', 43), 'leaked [redacted:runtime_mcp_key]'],
        'bearer: opaque token' => ['Authorization: Bearer 3f9a8c7e6d5b4a3f2e1d', 'Authorization: Bearer [redacted:bearer]'],
        'bearer: Sanctum token' => ['Authorization: Bearer '.$sanctum, 'Authorization: Bearer [redacted:bearer]'],
        'bearer: lower case, JSON header' => ['{"authorization":"bearer abcdefgh12"}', '{"authorization":"bearer [redacted:bearer]"}'],
        'bearer: an English word (accepted)' => ['Missing Bearer authentication header', 'Missing Bearer [redacted:bearer] header'],
        'password: user and password' => ['postgres://app:s3cr3t@db.internal:5432/app', 'postgres://app:[redacted:password]@db.internal:5432/app'],
        'password: empty user' => ['redis://:s3cr3tpass@redis.internal:6379', 'redis://:[redacted:password]@redis.internal:6379'],
        'password: holds @' => ['mysql://app:p@ss@db/app', 'mysql://app:[redacted:password]@db/app'],
        'password: only the URL, not a later email' => ['http://a:b@c and mail x@y.z', 'http://a:[redacted:password]@c and mail x@y.z'],
    ];
}

/** @return array<string, array{string}> text that must stay exactly as it is (spec 11.2, rule 13) */
function redactionFalsePositives(): array
{
    return [
        'long order number' => ['Order 40012345678901234567 shipped'],
        'card number with spaces' => ['4111 1111 1111 1111'],
        'card number' => ['4111111111111111'],
        'UUID' => ['550e8400-e29b-41d4-a716-446655440000'],
        'git SHA-1' => ['da39a3ee5e6b4b0d3255bfef95601890afd80709'],
        'SHA-256' => ['e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'],
        'ISO date' => ['2026-10-16T10:00:00+00:00'],
        'product slug' => ['desk-lamp-with-a-very-long-product-name'],
        'base64 image data' => ['data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='],
        'IBAN-like' => ['GB82WEST12345698765432'],
        'URL without user info' => ['https://example.com/path?x=1'],
        'URL with a port and an @ in the query' => ['https://example.com:8080/path?x=a@b'],
        'URL with a user and no password' => ['https://user@example.com/p'],
        'a time and an email' => ['at 10:30 user@example.com wrote'],
        'a short bearer word' => ['Bearer token required'],
        'rmcp_ inside a word' => ['my_rmcp_'.str_repeat('Q', 43)],
        'sk_test_ is not live' => ['sk_'.'test_'.str_repeat('0', 24)],
        'empty string' => [''],
    ];
}

it('redacts each built-in format with its typed marker and keeps the text around it', function (string $input, string $expected) {
    expect(redactionEngine()->redactString($input))->toBe($expected);
})->with(redactionBuiltInSamples());

it('marks the most specific type first', function (string $input, string $expected) {
    expect(redactionEngine()->redactString($input))->toBe($expected);
})->with([
    'a JWT after Bearer is a jwt' => ['Authorization: Bearer '.redactionJwt(), 'Authorization: Bearer [redacted:jwt]'],
    'an rmcp_ key after Bearer is a runtime_mcp_key' => ['Bearer rmcp_'.str_repeat('Q', 43), 'Bearer [redacted:runtime_mcp_key]'],
    'an Anthropic key after Bearer is an anthropic_key' => ['Bearer sk-'.'ant-api03-'.str_repeat('a', 40), 'Bearer [redacted:anthropic_key]'],
    'sk-ant- is anthropic, not openai' => ['sk-'.'ant-'.str_repeat('z', 30), '[redacted:anthropic_key]'],
    'a JWT in a URL password is still redacted' => ['postgres://app:'.redactionJwt().'@db/app', 'postgres://app:[redacted:password]@db/app'],
]);

it('redacts every PEM private key form', function (string $input, string $expected) {
    expect(redactionEngine()->redactString($input))->toBe($expected);
})->with([
    'RSA' => [redactionPem('RSA'), '[redacted:private_key]'],
    'EC' => [redactionPem('EC'), '[redacted:private_key]'],
    'OPENSSH' => [redactionPem('OPENSSH'), '[redacted:private_key]'],
    'PKCS#8 (no kind)' => [str_replace('  ', ' ', redactionPem('')), '[redacted:private_key]'],
    'cut off before END: everything to the end' => ['x '.redactionPem('RSA', false).'more text', 'x [redacted:private_key]'],
    'newlines escaped by a JSON log line' => [str_replace("\n", '\n', redactionPem('RSA')).'"}', '[redacted:private_key]"}'],
    'a public key stays' => ["-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0C\n-----END PUBLIC KEY-----", "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0C\n-----END PUBLIC KEY-----"],
]);

it('redacts a 200 KB string that holds a PEM block without END', function () {
    $text = redactionPem('RSA', false).str_repeat('QUJDREVGR0g=', 16_667);

    expect(redactionEngine()->redactString($text))->toBe('[redacted:private_key]');
});

it('leaves text that only looks random unchanged', function (string $text) {
    expect(redactionEngine()->redactString($text))->toBe($text);
})->with(redactionFalsePositives());

it('is idempotent', function (string $input) {
    $engine = redactionEngine();
    $once = $engine->redactString($input);

    expect($engine->redactString($once))->toBe($once);
})->with(fn (): array => [
    ...array_map(fn (array $row): array => [$row[0]], redactionBuiltInSamples()),
    ...redactionFalsePositives(),
]);

it('redacts a JWT in a string with invalid UTF-8 and keeps the bytes', function () {
    $text = "bad \xff\xfe bytes ".redactionJwt();

    expect(redactionEngine()->redactString($text))->toBe("bad \xff\xfe bytes [redacted:jwt]");
});

it('redacts every string in nested arrays and keeps keys and other scalars', function () {
    $value = [
        'token' => 'gh'.'p_'.str_repeat('A', 36),
        'count' => 3,
        'ratio' => 0.5,
        'ok' => true,
        'none' => null,
        'rows' => [['dsn' => 'redis://:pw123456@h'], ['note' => 'plain']],
        'list' => ['Bearer 3f9a8c7e6d5b4a3f2e1d', 7],
    ];

    expect(redactionEngine()->redact($value))->toBe([
        'token' => '[redacted:github_token]',
        'count' => 3,
        'ratio' => 0.5,
        'ok' => true,
        'none' => null,
        'rows' => [['dsn' => 'redis://:[redacted:password]@h'], ['note' => 'plain']],
        'list' => ['Bearer [redacted:bearer]', 7],
    ]);
});

it('converts objects to plain data before it redacts them', function (mixed $value, mixed $expected) {
    expect(redactionEngine()->redact($value))->toBe($expected);
})->with(fn (): array => [
    'JsonSerializable' => [
        new class implements JsonSerializable
        {
            /** @return array<string, string> */
            public function jsonSerialize(): array
            {
                return ['auth' => 'Bearer 3f9a8c7e6d5b4a3f2e1d'];
            }
        },
        ['auth' => 'Bearer [redacted:bearer]'],
    ],
    // A Collection is also JsonSerializable, so this object proves the Arrayable branch on its own.
    'Arrayable only' => [
        new class implements Arrayable
        {
            /** @return array<string, string> */
            public function toArray(): array
            {
                return ['k' => 'sk-'.str_repeat('c', 48)];
            }
        },
        ['k' => '[redacted:openai_key]'],
    ],
    'a Collection' => [collect(['k' => 'sk-'.str_repeat('c', 48)]), ['k' => '[redacted:openai_key]']],
    'Stringable' => [
        new class implements Stringable
        {
            public function __toString(): string
            {
                return 'dsn postgres://u:secretpw@h/db';
            }
        },
        'dsn postgres://u:[redacted:password]@h/db',
    ],
    'BackedEnum: its value' => [SampleStatus::Shipped, 'shipped'],
    'UnitEnum: its name' => [SampleSuit::Hearts, 'Hearts'],
    'stdClass: unsupported' => [new stdClass, Redactor::UNSUPPORTED],
    'Closure: unsupported' => [fn (): string => 'x', Redactor::UNSUPPORTED],
    'an object inside an array' => [['a' => new stdClass, 'b' => 'ok'], ['a' => Redactor::UNSUPPORTED, 'b' => 'ok']],
    'a JsonSerializable that returns itself: unsupported' => [new SelfSerializing, Redactor::UNSUPPORTED],
]);

it('turns a resource into the unsupported marker', function () {
    $handle = fopen('php://memory', 'r');

    try {
        expect(redactionEngine()->redact(['h' => $handle]))->toBe(['h' => Redactor::UNSUPPORTED]);
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
});

it('stops at depth 64', function () {
    // 63 arrays around a string: the string is at depth 64 and is kept.
    $kept = 'Bearer 3f9a8c7e6d5b4a3f2e1d';
    $cut = 'Bearer 3f9a8c7e6d5b4a3f2e1d';
    for ($i = 0; $i < 63; $i++) {
        $kept = [$kept];
    }
    // 64 arrays around a string: the string is at depth 65 and becomes the marker.
    for ($i = 0; $i < 64; $i++) {
        $cut = [$cut];
    }

    $walk = function (mixed $value, int $levels): mixed {
        for ($i = 0; $i < $levels; $i++) {
            assert(is_array($value));
            $value = $value[0];
        }

        return $value;
    };

    expect($walk(redactionEngine()->redact($kept), 63))->toBe('Bearer [redacted:bearer]')
        ->and($walk(redactionEngine()->redact($cut), 64))->toBe(Redactor::UNSUPPORTED);
});

it('adds user patterns after the built-in ones', function () {
    $engine = redactionEngine([
        '/\bINT-[0-9A-F]{8}\b/',
        'acme' => ['pattern' => '/\bacme_live_[a-z0-9]{8}\b/', 'type' => 'acme_key'],
        // If this ran first, the JWT would become [redacted:greedy].
        ['type' => 'greedy', 'pattern' => '/eyJ[A-Za-z0-9_.-]+/'],
    ]);

    expect($engine->redactString('id INT-0A1B2C3D'))->toBe('id [redacted:custom]')
        ->and($engine->redactString('k acme_live_abcd1234'))->toBe('k [redacted:acme_key]')
        ->and($engine->redactString(redactionJwt()))->toBe('[redacted:jwt]');
});

it('refuses a bad user pattern and names its entry', function (array $entries, int|string $entry, string $why) {
    // try/catch, not toThrow(fn (InvalidRedactionPattern $e) …): PHPStan wants a Closure(Throwable) there.
    $thrown = null;

    try {
        redactionEngine($entries);
    } catch (InvalidRedactionPattern $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InvalidRedactionPattern::class)
        ->and($thrown?->entry)->toBe($entry)
        ->and($thrown?->why)->toBe($why)
        ->and($thrown?->getMessage())->toBe("runtime-mcp.redaction.extra_patterns entry {$entry} is invalid: {$why}.")
        ->and($thrown?->getMessage())->not->toContain('NEVERSHOWN');
})->with([
    'not a string or an array' => [['/ok/', 42], 1, InvalidRedactionPattern::NOT_AN_ENTRY],
    'a regex that does not compile' => [['/NEVERSHOWN(/'], 0, InvalidRedactionPattern::DOES_NOT_COMPILE],
    'no delimiters' => [['NEVERSHOWN'], 0, InvalidRedactionPattern::DOES_NOT_COMPILE],
    'an empty string' => [[''], 0, InvalidRedactionPattern::DOES_NOT_COMPILE],
    'the removed e modifier' => [['/NEVERSHOWN/e'], 0, InvalidRedactionPattern::DOES_NOT_COMPILE],
    'a bad regex in the array shape' => [[['pattern' => '/NEVERSHOWN[/', 'type' => 'ok']], 0, InvalidRedactionPattern::DOES_NOT_COMPILE],
    'a type with a dash' => [[['pattern' => '/x/', 'type' => 'acme-key']], 0, InvalidRedactionPattern::BAD_TYPE],
    'a type in upper case' => [[['pattern' => '/x/', 'type' => 'Acme']], 0, InvalidRedactionPattern::BAD_TYPE],
    'a type of 33 characters' => [[['pattern' => '/x/', 'type' => 'a'.str_repeat('b', 32)]], 0, InvalidRedactionPattern::BAD_TYPE],
    'an empty type' => [[['pattern' => '/x/', 'type' => '']], 0, InvalidRedactionPattern::BAD_TYPE],
    'no type' => [[['pattern' => '/x/']], 0, InvalidRedactionPattern::NOT_AN_ENTRY],
    'a typo in a key' => [[['regex' => '/x/', 'type' => 'ok']], 0, InvalidRedactionPattern::NOT_AN_ENTRY],
    'an extra key' => [[['pattern' => '/x/', 'type' => 'ok', 'enabled' => true]], 0, InvalidRedactionPattern::NOT_AN_ENTRY],
    'a pattern that is not a string' => [[['pattern' => 1, 'type' => 'ok']], 0, InvalidRedactionPattern::NOT_AN_ENTRY],
    'a string key' => [['acme' => '/NEVERSHOWN(/'], 'acme', InvalidRedactionPattern::DOES_NOT_COMPILE],
    'the second of two entries' => [['/ok/', '/NEVERSHOWN(/'], 1, InvalidRedactionPattern::DOES_NOT_COMPILE],
]);

it('cannot be turned off', function (array $config) {
    $engine = new Redactor(new ConfigRepository($config));

    expect($engine->redactString('Bearer 3f9a8c7e6d5b4a3f2e1d'))->toBe('Bearer [redacted:bearer]')
        ->and($engine->redactString(redactionJwt()))->toBe('[redacted:jwt]');
})->with([
    'an enabled = false key' => [['runtime-mcp' => ['redaction' => ['enabled' => false, 'extra_patterns' => []]]]],
    'extra_patterns null' => [['runtime-mcp' => ['redaction' => ['extra_patterns' => null]]]],
    'extra_patterns false' => [['runtime-mcp' => ['redaction' => ['extra_patterns' => false]]]],
    'extra_patterns a string' => [['runtime-mcp' => ['redaction' => ['extra_patterns' => '/x/']]]],
    'an empty redaction key' => [['runtime-mcp' => ['redaction' => []]]],
    'redaction null' => [['runtime-mcp' => ['redaction' => null]]],
    'no runtime-mcp config at all' => [[]],
]);

it('treats a string it could not scan as a secret', function () {
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');

    try {
        // A short string with no match does not reach the limit; a long one does.
        $text = str_repeat('plain log text ', 1_000).'Bearer 3f9a8c7e6d5b4a3f2e1d';

        expect(redactionEngine()->redactString($text))->toBe(Redactor::UNSCANNABLE);
    } finally {
        ini_set('pcre.backtrack_limit', is_string($limit) ? $limit : '1000000');
    }
});

it('treats a user pattern with /u on invalid UTF-8 as unscannable', function () {
    $engine = redactionEngine(['/secret/u']);

    expect($engine->redactString('a secret here'))->toBe('a [redacted:custom] here')
        ->and($engine->redactString("a secret \xff here"))->toBe(Redactor::UNSCANNABLE);
});

it('lists its marker types in pattern order', function () {
    $engine = redactionEngine();

    expect($engine->markerTypes())->toBe([
        'private_key', 'jwt', 'aws_access_key', 'stripe_key', 'github_token', 'slack_token',
        'anthropic_key', 'openai_key', 'runtime_mcp_key', 'bearer', 'password',
    ])
        ->and($engine->markerTypes())->toBe(array_keys(Patterns::BUILT_IN))
        ->and($engine->extraMarkerTypes())->toBe(['custom', 'unscannable', 'unsupported_value', 'payload'])
        ->and(Redactor::PAYLOAD)->toBe('[redacted:payload]')
        ->and(Redactor::UNSCANNABLE)->toBe('[redacted:unscannable]')
        ->and(Redactor::UNSUPPORTED)->toBe('[redacted:unsupported_value]');
});

it('uses no /u modifier in a built-in pattern', function () {
    foreach (Patterns::BUILT_IN as $type => $regex) {
        $modifiers = substr($regex, strrpos($regex, '/') + 1);

        // Not ->not->toContain('u', $message): toContain() is variadic, so the message would be a needle.
        expect(str_contains($modifiers, 'u'))->toBeFalse("Pattern {$type} has the /u modifier.");
    }
});

it('is a scoped binding that reads extra_patterns from the config', function () {
    config()->set('runtime-mcp.redaction.extra_patterns', ['/\bINT-[0-9]{4}\b/']);

    $first = app(Redactor::class);

    expect($first->redactString('INT-1234'))->toBe('[redacted:custom]')
        ->and(app(Redactor::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(Redactor::class))->not->toBe($first);
});

it('throws through the container for a bad user pattern', function () {
    config()->set('runtime-mcp.redaction.extra_patterns', ['/(/']);

    expect(fn () => app(Redactor::class))->toThrow(InvalidRedactionPattern::class);
});
