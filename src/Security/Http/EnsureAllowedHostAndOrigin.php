<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Http;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Auth\AuthenticateApiKey;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 5.4: the Host must be allowed, and a request with an Origin header must come from an allowed
 * origin. This stops DNS rebinding and a malicious web page in a developer's browser. A request
 * without Origin (a CLI agent, a server-side client) is not a browser request, so it passes.
 *
 * The only security check that lives in HTTP middleware (rule 3): it is about the whole request,
 * not one tool. It runs before auth, so a refused request never has its credential read. No
 * environment turns it off (rule 12). Every refusal writes one warning audit entry, dispatches
 * AuthFailed (spec 5.7) and gets the same 403.
 */
final class EnsureAllowedHostAndOrigin
{
    public const BODY = ['message' => 'Forbidden.'];

    public function __construct(
        private readonly Repository $config,
        private readonly Environment $environment,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $rules = HostRules::fromConfig($this->config);

        // getHost() applies the app's TrustProxies settings: X-Forwarded-Host counts only from a
        // trusted proxy. It throws for a malformed or untrusted host, then returns '' on a later
        // call; both are refused. Never read X-Forwarded-Host here.
        try {
            $host = $request->getHost();
            $port = $request->getPort();
        } catch (RequestExceptionInterface) {
            $host = '';
            $port = null;
        }

        if (! $rules->allowsHost($host, $port)) {
            return $this->reject($request, Reason::BadHost, $this->hostDetails($request, $host));
        }

        if ($request->headers->has('Origin')) {
            // Logged raw (AuditEntry cuts it to 255 characters); compared normalised.
            $origin = (string) $request->headers->get('Origin', '');

            if (! $rules->allowsOrigin($origin)) {
                return $this->reject($request, Reason::BadOrigin, ['origin' => $origin]);
            }
        }

        return $next($request);
    }

    public static function forbidden(): JsonResponse
    {
        return new JsonResponse(self::BODY, 403);
    }

    /**
     * The raw Host header. Behind a trusted proxy the checked host can come from X-Forwarded-Host;
     * then it is added as forwarded_host, so the operator sees which name was refused.
     *
     * @return array<string, string>
     */
    private function hostDetails(Request $request, string $checked): array
    {
        $raw = (string) $request->headers->get('Host', '');
        $details = ['host' => $raw];

        if ($checked !== '' && $request->isFromTrustedProxy()
            && $checked !== strtolower((string) preg_replace('/:\d+$/', '', trim($raw)))) {
            $details['forwarded_host'] = $checked;
        }

        return $details;
    }

    /** @param  array<string, string>  $details */
    private function reject(Request $request, Reason $reason, array $details): Response
    {
        // The scheme word only, by 01-06's rule; never a character of the credential.
        $authorization = $request->headers->get('Authorization');

        if (is_string($authorization) && trim($authorization) !== '') {
            $details['scheme'] = AuthenticateApiKey::schemeWord($authorization);
        }

        // auth_method is null: auth has not run. Never throws (01-05): the 403 still goes out.
        $this->audit->rejectedRequest(AuditEntry::rejectedRequest(
            $this->environment->name(),
            $reason,
            null,
            $request->ip(),
            $details,
        ));

        return self::forbidden();
    }
}
