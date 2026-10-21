<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp;

use Illuminate\Contracts\Auth\Authenticatable;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthMethod;

/**
 * Spec 2 and 5.3: the identity behind a request. The key name (or auth.stdio_principal), and the
 * linked User when auth.link_users links the name.
 *
 * It does not implement Authenticatable: it is not a user, and app code that calls auth()->user()
 * must never see one. Gates and Policies run for subject() (01-09).
 */
final readonly class Principal
{
    /** The name prefix of a user principal: the Sanctum driver (01-35) and the test kit's asUser() (01-39). */
    public const USER_PREFIX = 'user-';

    public function __construct(
        public string $name,
        public AuthMethod $method,
        public ?Authenticatable $user = null,
    ) {}

    /**
     * Spec 5.3: "user-<id>", in the format of a key name (ApiKey::NAME_PATTERN), so the audit and the
     * rate-limit keys (01-17) have one name format. A character outside [A-Za-z0-9._-] becomes "-", and
     * the id is cut to 59 characters, so the name is at most 64.
     */
    public static function userName(int|string $id): string
    {
        return self::USER_PREFIX.substr((string) preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $id), 0, 59);
    }

    /** What Gates and Policies run for: the linked User, or this Principal. */
    public function subject(): Authenticatable|self
    {
        return $this->user ?? $this;
    }

    /** The audit's user_id (spec 5.7): the linked User's key, or null. */
    public function userId(): int|string|null
    {
        $id = $this->user?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }
}
