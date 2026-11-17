<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Auth;

use DateTimeInterface;
use Illuminate\Foundation\Auth\User;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * An app's user model whose createToken() saves the row but returns its own wrapper, not Sanctum's NewAccessToken
 * (01-27 triage): runtime-mcp:token cannot show the token and must not say that no user has the email.
 */
final class WrappedTokenUser extends User
{
    use HasApiTokens {
        createToken as sanctumCreateToken;
    }

    protected $table = 'users';

    /**
     * @param  list<string>  $abilities
     * @return object{token: NewAccessToken}
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): object
    {
        return (object) ['token' => $this->sanctumCreateToken($name, $abilities, $expiresAt)];
    }
}
