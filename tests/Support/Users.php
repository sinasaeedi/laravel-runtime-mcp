<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;

/**
 * A users table on the in-memory SQLite connection, for auth.link_users (01-06). Testbench's default
 * guard "web" uses the provider "users" with Illuminate\Foundation\Auth\User.
 *
 * The table lives in the current app's connection: call it again after bootWith() or rebootApplication().
 */
final class Users
{
    public static function create(string $email): User
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('email')->unique();
                $table->string('password')->default('');
                $table->timestamps();
            });
        }

        $user = new User;
        $user->forceFill(['email' => $email])->save();

        return $user;
    }
}
