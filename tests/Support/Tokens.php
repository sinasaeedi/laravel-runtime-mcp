<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use DateTimeInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateSanctumToken;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\OtherTokenOwner;
use SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth\TokenUser;

/**
 * The sanctum driver in tests (01-35). Sanctum's provider only publishes its migration, so tables()
 * makes the tables on the default connection. The tables live in the current app's connection: call
 * tables() again after bootWith() or rebootApplication(), as Users::create().
 */
final class Tokens
{
    public const EMAIL = 'sina@example.com';

    /**
     * Boot-time config for the sanctum driver: the endpoint on, and the fixture user model.
     *
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    public static function config(array $more = []): array
    {
        return [
            'runtime-mcp.enabled' => true,
            'runtime-mcp.auth.driver' => 'sanctum',
            'auth.providers.users.model' => TokenUser::class,
            ...$more,
        ];
    }

    /** The users table, another owner table, and the columns of Sanctum's migration (v4.3.3). */
    public static function tables(): void
    {
        foreach (['users', 'other_token_owners'] as $name) {
            if (! Schema::hasTable($name)) {
                Schema::create($name, function (Blueprint $table): void {
                    $table->id();
                    $table->string('email')->unique();
                    $table->string('password')->default('');
                    $table->timestamps();
                });
            }
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table): void {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public static function user(string $email = self::EMAIL): TokenUser
    {
        self::tables();

        $user = new TokenUser;
        $user->forceFill(['email' => $email])->save();

        return $user;
    }

    public static function otherOwner(string $email = self::EMAIL): OtherTokenOwner
    {
        self::tables();

        $owner = new OtherTokenOwner;
        $owner->forceFill(['email' => $email])->save();

        return $owner;
    }

    /**
     * A new token, as runtime-mcp:token (01-27) creates it: "<id>|<secret>".
     *
     * @param  list<string>  $abilities
     */
    public static function issue(
        TokenUser|OtherTokenOwner $owner,
        array $abilities = [AuthenticateSanctumToken::ABILITY],
        ?DateTimeInterface $expiresAt = null,
    ): string {
        return $owner->createToken('runtime-mcp', $abilities, $expiresAt)->plainTextToken;
    }

    /** The principal name that the sanctum driver gives this user (spec 5.3), written out. */
    public static function principalName(TokenUser $user): string
    {
        $id = $user->getKey();
        assert(is_int($id));

        return 'user-'.$id;
    }

    /** The row ID of a token "<id>|<secret>". */
    public static function id(string $token): string
    {
        return explode('|', $token, 2)[0];
    }

    /** The secret of a token "<id>|<secret>", which Sanctum also finds by its hash alone. */
    public static function secret(string $token): string
    {
        return explode('|', $token, 2)[1] ?? '';
    }
}
