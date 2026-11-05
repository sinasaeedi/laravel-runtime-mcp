<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * 01-21 Part E: encrypted casts. ssn, prefs, history and wallet would be returned decrypted; pin is in
 * $hidden; api_secret matches the default mask `*secret*`; notes has a plain cast.
 */
final class EncryptedProfile extends Model
{
    protected $table = 'profiles';

    protected $hidden = ['pin'];

    protected $casts = [
        'ssn' => 'encrypted',
        'prefs' => 'encrypted:array',
        'history' => AsEncryptedCollection::class,
        'wallet' => AsEncryptedArrayObject::class,
        'pin' => 'encrypted',
        'api_secret' => 'encrypted',
        'notes' => 'array',
    ];
}
