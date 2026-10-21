<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Psr\Log\LoggerInterface;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Principal;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Spec 5.3, rule 21: the sanctum driver. Only a bearer token of Sanctum's personal access token model
 * with the exact ability `runtime-mcp`, not expired, owned by an instance of the app's user model
 * (UserModel), reaches laravel/mcp. The principal is "user-<id>", and Gates run for the owner.
 *
 * Never the sanctum guard, auth:sanctum or the stateful middleware: that guard also accepts a browser
 * session cookie. Never can() or tokenCan(): both treat `*` as every ability. The expiry rules are
 * Sanctum's own (Guard::isValidAccessToken()), but Sanctum::$accessTokenAuthenticationCallback is not
 * run: it is the app's rule for its own API, and it must not decide access here. No TokenAuthenticated
 * event, and no withAccessToken(): app code written for the app's API must not run, and no token model
 * outlives the request.
 *
 * AuthenticateRequest runs it only while the Sanctum classes exist.
 */
final class AuthenticateSanctumToken
{
    /** Rule 21: the exact ability. `*` is not enough. */
    public const ABILITY = 'runtime-mcp';

    /** A larger id cannot be a bigint key; PostgreSQL would throw on it. */
    public const MAX_ID_DIGITS = 18;

    public const LOOKUP_WARNING = 'Runtime MCP: the Sanctum token lookup failed (%s), so the request was rejected.';

    public const LAST_USED_WARNING = 'Runtime MCP: could not update last_used_at of a Sanctum token (%s).';

    public function __construct(
        private readonly Repository $config,
        private readonly AuthRejection $rejection,
        private readonly CurrentPrincipal $principals,
        private readonly LoggerInterface $log,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $credential = BearerCredential::fromRequest($request);
        $details = $credential->details;

        if ($credential->reason !== null) {
            return $this->reject($request, $credential->reason, $details);
        }

        $model = self::tokenModel();

        if (! is_string($model) || ! is_a($model, PersonalAccessToken::class, true)) {
            return $this->reject($request, Reason::TokenLookupFailed, $details);
        }

        if (! self::wellFormed($model, $credential->token)) {
            return $this->reject($request, Reason::UnknownToken, $details);
        }

        try {
            $row = $model::findToken($credential->token);
        } catch (Throwable $e) {
            // The class only: a QueryException message holds the SQL bindings (the token hash).
            $this->warn(self::LOOKUP_WARNING, $e);

            return $this->reject($request, Reason::TokenLookupFailed, $details);
        }

        if (! $row instanceof PersonalAccessToken) {
            return $this->reject($request, Reason::UnknownToken, $details);
        }

        if (! $this->fresh($row)) {
            return $this->reject($request, Reason::TokenExpired, $details);
        }

        $abilities = $row->getAttribute('abilities');

        if (! is_array($abilities) || ! in_array(self::ABILITY, $abilities, true)) {
            return $this->reject($request, Reason::TokenMissingAbility, $details);
        }

        $owner = $this->owner($row);
        $id = $owner?->getAuthIdentifier();

        // A database key is an int or a string; anything else is not an owner we can name.
        if ($owner === null || ! (is_int($id) || is_string($id))) {
            return $this->reject($request, Reason::TokenOwnerInvalid, $details);
        }

        $this->touch($row);
        $this->principals->set(new Principal(Principal::userName($id), AuthMethod::Sanctum, $owner));

        return $next($request);
    }

    /**
     * Sanctum::personalAccessTokenModel(), as the app configured it (usePersonalAccessTokenModel()).
     * Typed mixed: Sanctum does not check the value, so neither may PHPStan assume it.
     */
    private static function tokenModel(): mixed
    {
        return Sanctum::personalAccessTokenModel();
    }

    /**
     * Sanctum's Guard::isValidBearerToken(): with an int key, the part before "|" must be digits. Without
     * this, PostgreSQL throws on find('abc') (SQLSTATE 22P02). We also cap the digits (a bigint has at
     * most 19) and refuse an empty secret, so neither reaches the database.
     *
     * @param  class-string<PersonalAccessToken>  $model
     */
    private static function wellFormed(string $model, string $token): bool
    {
        if (! str_contains($token, '|')) {
            return true;
        }

        [$id, $secret] = explode('|', $token, 2);

        if ($secret === '') {
            return false;
        }

        return (new $model)->getKeyType() !== 'int'
            || (ctype_digit($id) && strlen($id) <= self::MAX_ID_DIGITS);
    }

    /**
     * Guard::isValidAccessToken()'s expiry rules: the row's expires_at, and sanctum.expiration (minutes;
     * null or 0 = none) on created_at. A value that is not a date or a number fails closed, also a
     * stored date that the model's cast cannot read (it throws).
     */
    private function fresh(PersonalAccessToken $row): bool
    {
        try {
            $expiresAt = $row->getAttribute('expires_at');
            $createdAt = $row->getAttribute('created_at');
        } catch (Throwable) {
            return false;
        }

        if ($expiresAt !== null && (! $expiresAt instanceof DateTimeInterface || $expiresAt < Carbon::now())) {
            return false;
        }

        $minutes = $this->config->get('sanctum.expiration');

        if ($minutes === null || $minutes === 0 || $minutes === '0' || $minutes === '') {
            return true;
        }

        if (! is_int($minutes) && ! (is_string($minutes) && ctype_digit($minutes))) {
            return false;
        }

        return $createdAt instanceof DateTimeInterface
            && $createdAt > Carbon::now()->subMinutes((int) $minutes);
    }

    /** The token's owner, only when it is an instance of the app's user model (Sina, 2026-10-20). */
    private function owner(PersonalAccessToken $row): ?Authenticatable
    {
        $userModel = UserModel::fromConfig($this->config);

        if ($userModel === null) {
            return null;
        }

        try {
            $owner = $row->getRelationValue('tokenable');
        } catch (Throwable) {
            // A morph class that no longer exists, for example.
            return null;
        }

        return $owner instanceof Model && $owner instanceof Authenticatable && $owner instanceof $userModel
            ? $owner
            : null;
    }

    /**
     * Sanctum's Guard::updateLastUsedAt(): the write must not mark the connection as modified, or a
     * sticky read/write connection reads from the writer for the rest of the request. A failure is
     * logged and the request goes on.
     */
    private function touch(PersonalAccessToken $row): void
    {
        try {
            $connection = $row->getConnection();
            $modified = $connection->hasModifiedRecords();
            $row->forceFill(['last_used_at' => Carbon::now()])->save();
            $connection->setRecordModificationState($modified);
        } catch (Throwable $e) {
            $this->warn(self::LAST_USED_WARNING, $e);
        }
    }

    private function warn(string $message, Throwable $e): void
    {
        try {
            $this->log->warning(sprintf($message, $e::class));
        } catch (Throwable) {
            // The decision stands without the warning.
        }
    }

    /** @param  array<string, string>  $details */
    private function reject(Request $request, Reason $reason, array $details): Response
    {
        return $this->rejection->respond($request, $reason, AuthMethod::Sanctum, $details);
    }
}
