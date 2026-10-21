<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Auth;

use Illuminate\Http\Request;
use SinaSaeedi\RuntimeMcp\Audit\AuditEntry;
use SinaSaeedi\RuntimeMcp\Audit\AuditLogger;
use SinaSaeedi\RuntimeMcp\Audit\Reason;
use SinaSaeedi\RuntimeMcp\Security\Environment;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 5.3, 5.7: one rejection at the auth step, for every driver. One warning audit entry, then
 * AuthFailed (AuditLogger::rejectedRequest()), then the one 401 (Unauthenticated). The reason reaches
 * the audit only, so a caller cannot probe which keys or tokens exist.
 */
final class AuthRejection
{
    public function __construct(
        private readonly Environment $environment,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, string>  $details  never the credential, a part of it, or its hash */
    public function respond(Request $request, Reason $reason, ?AuthMethod $method, array $details = []): Response
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
