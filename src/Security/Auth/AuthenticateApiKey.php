<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 5.3: only a request with a key whose hash is in this server's RUNTIME_MCP_API_KEYS reaches
 * laravel/mcp. Every rejection writes one warning audit entry, dispatches AuthFailed (spec 5.7), and
 * gets the same 401 (Unauthenticated).
 *
 * The audit's details hold only a scheme word or a driver name: never the header value, never a
 * part of it, never its hash. AuditLogger does not redact details.
 */
final class AuthenticateApiKey
{
    /** An auth-scheme word that is safe to log (RFC 7235 token, cut short). */
    public const SCHEME_PATTERN = '/^[A-Za-z][A-Za-z0-9-]{0,19}$/D';

    public const SCHEME_NONE = '[none]';

    public const SCHEME_INVALID = '[invalid]';

    public function __construct(
        private readonly Repository $config,
        private readonly Environment $environment,
        private readonly AuditLogger $audit,
        private readonly CurrentPrincipal $principals,
        private readonly PrincipalResolver $resolver,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        // 01-35 moves this check into the AuthenticateRequest dispatcher.
        $driver = $this->config->get('runtime-mcp.auth.driver');

        if ($driver !== 'api_key') {
            $details = is_string($driver) ? ['driver' => mb_substr($driver, 0, 20)] : [];

            return $this->reject($request, Reason::UnsupportedAuthDriver, null, $details);
        }

        $header = $request->headers->get('Authorization');
        $value = is_string($header) ? trim($header) : '';

        if ($value === '') {
            return $this->reject($request, Reason::MissingCredentials, AuthMethod::ApiKey);
        }

        // Parse the first word ourselves. Request::bearerToken() takes the LAST "Bearer " anywhere
        // in the header and cuts at a comma, so "Basic x, Bearer y" would pass as "y".
        $length = strcspn($value, " \t");
        $scheme = substr($value, 0, $length);
        $token = trim(substr($value, $length));

        if (strcasecmp($scheme, 'Bearer') !== 0) {
            // No space at all: the value is a bare credential (a client that forgot "Bearer "), so
            // not one character of it is logged.
            $logged = match (true) {
                $length === strlen($value) => self::SCHEME_NONE,
                preg_match(self::SCHEME_PATTERN, $scheme) === 1 => $scheme,
                default => self::SCHEME_INVALID,
            };

            return $this->reject($request, Reason::UnsupportedScheme, AuthMethod::ApiKey, ['scheme' => $logged]);
        }

        $bearer = ['scheme' => 'Bearer'];

        if ($token === '') {
            return $this->reject($request, Reason::BlankCredentials, AuthMethod::ApiKey, $bearer);
        }

        $name = ApiKeyRing::fromConfig($this->config)->match($token);

        if ($name === null) {
            return $this->reject($request, Reason::UnknownKey, AuthMethod::ApiKey, $bearer);
        }

        try {
            $principal = $this->resolver->resolve($name, AuthMethod::ApiKey);
        } catch (LinkedUserMissing) {
            return $this->reject($request, Reason::LinkedUserMissing, AuthMethod::ApiKey, $bearer);
        }

        $this->principals->set($principal);

        return $next($request);
    }

    /** @param  array<string, string>  $details */
    private function reject(Request $request, Reason $reason, ?AuthMethod $method, array $details = []): Response
    {
        // Never throws (01-05): a failed write is reported, and the 401 still goes out.
        $this->audit->rejectedRequest(AuditEntry::rejectedRequest(
            $this->environment->name(),
            $reason,
            $method?->value,
            $request->ip(),
            $details,
        ));

        return Unauthenticated::response();
    }
}
