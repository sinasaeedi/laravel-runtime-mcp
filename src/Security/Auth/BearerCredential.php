<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Illuminate\Http\Request;
use SinaSaeedi\RuntimeMcp\Audit\Reason;

/**
 * Spec 5.3: the bearer credential of a request, read by one rule for every auth driver (01-06, 01-35).
 * Either a token, or the rejection reason with the audit details for it.
 *
 * The details hold only a scheme word: never the header value, never a part of it, never its hash.
 * AuditLogger does not redact details.
 */
final readonly class BearerCredential
{
    /** An auth-scheme word that is safe to log (RFC 7235 token, cut short). */
    public const SCHEME_PATTERN = '/^[A-Za-z][A-Za-z0-9-]{0,19}$/D';

    public const SCHEME_NONE = '[none]';

    public const SCHEME_INVALID = '[invalid]';

    /**
     * @param  string  $token  the credential after "Bearer ", or '' when $reason is set
     * @param  array<string, string>  $details  the audit details for a rejection
     */
    private function __construct(
        public string $token,
        public ?Reason $reason,
        public array $details,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $header = $request->headers->get('Authorization');
        $value = is_string($header) ? trim($header) : '';

        if ($value === '') {
            return new self('', Reason::MissingCredentials, []);
        }

        // Parse the first word ourselves. Request::bearerToken() takes the LAST "Bearer " anywhere
        // in the header and cuts at a comma, so "Basic x, Bearer y" would pass as "y".
        $length = strcspn($value, " \t");
        $scheme = substr($value, 0, $length);
        $token = trim(substr($value, $length));

        if (strcasecmp($scheme, 'Bearer') !== 0) {
            return new self('', Reason::UnsupportedScheme, ['scheme' => self::schemeWord($value)]);
        }

        if ($token === '') {
            return new self('', Reason::BlankCredentials, ['scheme' => 'Bearer']);
        }

        return new self($token, null, ['scheme' => 'Bearer']);
    }

    /**
     * The first word of an Authorization value, safe to log (01-06, and 01-07 for a refused host or
     * origin): "Bearer" for any case of bearer; "[none]" when the value has no space or tab (a bare
     * credential, a client that forgot "Bearer "); the word when it matches SCHEME_PATTERN; else
     * "[invalid]". Never one character of the credential.
     */
    public static function schemeWord(string $value): string
    {
        $value = trim($value);
        $length = strcspn($value, " \t");
        $scheme = substr($value, 0, $length);

        return match (true) {
            strcasecmp($scheme, 'Bearer') === 0 => 'Bearer',
            $length === strlen($value) => self::SCHEME_NONE,
            preg_match(self::SCHEME_PATTERN, $scheme) === 1 => $scheme,
            default => self::SCHEME_INVALID,
        };
    }
}
