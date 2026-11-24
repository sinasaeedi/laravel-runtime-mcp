<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

/**
 * 01-04's fake secret samples, one row per built-in pattern and form, for every test that needs them: the redactor's
 * unit test and the end-to-end redaction test of 01-30. Every sample is built by concatenation, so GitHub push
 * protection never sees a whole key in the source. Moved here from RedactorTest (01-30): a Pest file function is
 * there only when its file runs.
 */
final class SecretSamples
{
    public static function jwt(): string
    {
        return 'eyJ'.'hbGciOiJIUzI1NiJ9.'.'eyJ'.'zdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
    }

    public static function pem(string $kind, bool $withEnd = true): string
    {
        $block = '-----BEGIN '.$kind.' PRIVATE KEY-----'."\nMIIEowIBAAKCAQEAfakefakefake\nZmFrZWZha2U=\n";

        return $withEnd ? $block.'-----END '.$kind.' PRIVATE KEY-----' : $block;
    }

    /** @return array<string, array{string, string}> input => exact output, one row per built-in pattern and form */
    public static function builtIn(): array
    {
        $sanctum = '7|'.str_repeat('Ab3x', 10).'8cdc1683';

        return [
            'private_key: RSA block' => ['key: '.self::pem('RSA').' tail', 'key: [redacted:private_key] tail'],
            'jwt' => ['token='.self::jwt().'; next', 'token=[redacted:jwt]; next'],
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
            'bearer: JSON text with escaped slashes (01-30 triage)' => ['{"Authorization":"Bearer Q9xT2kLm\/8pR4vW7zAb3x"}', '{"Authorization":"Bearer [redacted:bearer]"}'],
            'bearer: an English word (accepted)' => ['Missing Bearer authentication header', 'Missing Bearer [redacted:bearer] header'],
            'password: user and password' => ['postgres://app:s3cr3t@db.internal:5432/app', 'postgres://app:[redacted:password]@db.internal:5432/app'],
            'password: empty user' => ['redis://:s3cr3tpass@redis.internal:6379', 'redis://:[redacted:password]@redis.internal:6379'],
            'password: holds @' => ['mysql://app:p@ss@db/app', 'mysql://app:[redacted:password]@db/app'],
            'password: only the URL, not a later email' => ['http://a:b@c and mail x@y.z', 'http://a:[redacted:password]@c and mail x@y.z'],
            'password: user holds @ (an email)' => ['smtp://postmaster@mg.example.com:s3cret@smtp.example.org:587', 'smtp://postmaster@mg.example.com:[redacted:password]@smtp.example.org:587'],
            'password: user holds @ (user@server)' => ['pgsql://app@myserver:s3cret@myserver.example.net/app', 'pgsql://app@myserver:[redacted:password]@myserver.example.net/app'],
            'password: JSON text with escaped slashes' => ['{"smtp":"smtp:\/\/app:s3cret@mail.internal:587"}', '{"smtp":"smtp:\/\/app:[redacted:password]@mail.internal:587"}'],
            'password: JSON text encoded twice (01-30)' => ['{"k":"{\\"smtp\\":\\"smtp:\\\\/\\\\/app:s3cret@mail.internal:587\\"}"}', '{"k":"{\\"smtp\\":\\"smtp:\\\\/\\\\/app:[redacted:password]@mail.internal:587\\"}"}'],
        ];
    }

    /**
     * The secret part of each sample: the input without the text that the redacted output keeps around it. A secret
     * shorter than MIN_SECRET characters ("b" of "http://a:b@c") is left out, because a response holds such a short
     * text for other reasons.
     *
     * @return array<string, array{string, string}> sample name => [secret, marker type]
     */
    public static function secrets(): array
    {
        $secrets = [];

        foreach (self::builtIn() as $name => [$input, $expected]) {
            $prefix = 0;
            $max = min(strlen($input), strlen($expected));

            while ($prefix < $max && $input[$prefix] === $expected[$prefix]) {
                $prefix++;
            }

            $suffix = 0;

            while ($suffix < $max - $prefix && $input[strlen($input) - 1 - $suffix] === $expected[strlen($expected) - 1 - $suffix]) {
                $suffix++;
            }

            $secret = substr($input, $prefix, strlen($input) - $prefix - $suffix);

            if (strlen($secret) >= self::MIN_SECRET && preg_match('/\[redacted:([a-z_]+)\]/', $expected, $type) === 1) {
                $secrets[$name] = [$secret, $type[1]];
            }
        }

        return $secrets;
    }

    /** The shortest secret that secrets() keeps. */
    public const MIN_SECRET = 6;
}
