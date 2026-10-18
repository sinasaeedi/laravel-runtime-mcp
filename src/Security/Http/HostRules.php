<?php

declare(strict_types=1);

namespace SinaSaeedi\RuntimeMcp\Security\Http;

use Illuminate\Contracts\Config\Repository;

/**
 * Spec 5.4: the hosts and the browser origins that may reach the MCP route. The middleware
 * (EnsureAllowedHostAndOrigin) and doctor (01-12) read the same rules from here.
 *
 * Built from config for each request (rule 14). Every malformed value fails closed: a bad
 * allowed_hosts value refuses every request, a bad allowed_origins value refuses every browser.
 */
final class HostRules
{
    /** A host entry: a name or a bracketed IPv6 literal, then an optional port. Lower case. */
    private const HOST_ENTRY = '/^(\[[0-9a-f:.]+\]|[a-z0-9_.-]+)(?::([0-9]{1,5}))?$/D';

    /** An origin: scheme://host[:port], with at most one trailing slash. Lower case. */
    private const ORIGIN = '~^([a-z][a-z0-9+.-]*)://(\[[0-9a-f:.]+\]|[a-z0-9_.-]+)(?::([0-9]{1,5}))?/?$~D';

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * @param  list<array{host: string, port: int|null}>  $hosts
     * @param  list<string>  $origins  normalised
     */
    private function __construct(
        private readonly array $hosts,
        private readonly array $origins,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $configured = $config->get('runtime-mcp.allowed_hosts');

        $entries = $configured === null
            ? self::appUrlHost($config->get('app.url'))
            : self::stringList($configured);

        $hosts = [];

        foreach ($entries as $entry) {
            $host = self::parseHost($entry);

            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        $origins = [];

        foreach (self::stringList($config->get('runtime-mcp.allowed_origins')) as $entry) {
            $origin = self::normaliseOrigin($entry);

            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        return new self($hosts, $origins);
    }

    /**
     * The resolved host entries, "host" or "host:port". Empty = every request is refused (01-12
     * reports it: "Every request is refused. Set APP_URL or runtime-mcp.allowed_hosts.").
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return array_map(
            fn (array $entry): string => $entry['port'] === null ? $entry['host'] : $entry['host'].':'.$entry['port'],
            $this->hosts,
        );
    }

    /** @return list<string> the normalised allowed origins; a malformed entry is left out */
    public function origins(): array
    {
        return $this->origins;
    }

    /**
     * $host comes from Request::getHost() (lower case, no port; '' when Symfony found it invalid),
     * $port from Request::getPort(). An entry without a port matches any port. '' never matches,
     * because no entry is empty.
     */
    public function allowsHost(string $host, int|string|null $port): bool
    {
        $host = strtolower($host);
        $port = is_int($port) || (is_string($port) && ctype_digit($port)) ? (int) $port : null;

        foreach ($this->hosts as $entry) {
            if ($entry['host'] === $host && ($entry['port'] === null || $entry['port'] === $port)) {
                return true;
            }
        }

        return false;
    }

    /** The raw Origin header value. An empty value or a value that is not an origin never matches. */
    public function allowsOrigin(string $origin): bool
    {
        $normalised = self::normaliseOrigin($origin);

        return $normalised !== null && in_array($normalised, $this->origins, true);
    }

    /**
     * "scheme://host[:port]" in lower case, the default port of http and https dropped, one trailing
     * slash dropped. "null" (a sandboxed page) stays "null". Null when the value is not an origin:
     * a path, a query, user info, no scheme, or a port outside 1-65535 (01-12 reports such entries).
     */
    public static function normaliseOrigin(string $value): ?string
    {
        $value = strtolower(trim($value));

        if ($value === 'null') {
            return 'null';
        }

        if (preg_match(self::ORIGIN, $value, $match) !== 1) {
            return null;
        }

        $origin = $match[1].'://'.$match[2];

        if (! isset($match[3])) {
            return $origin;
        }

        $port = (int) $match[3];

        if ($port < 1 || $port > 65535) {
            return null;
        }

        return $port === (self::DEFAULT_PORTS[$match[1]] ?? null) ? $origin : $origin.':'.$port;
    }

    /** @return array{host: string, port: int|null}|null null for an entry that can never match */
    private static function parseHost(string $entry): ?array
    {
        if (preg_match(self::HOST_ENTRY, strtolower(trim($entry)), $match) !== 1) {
            return null;
        }

        if (! isset($match[2])) {
            return ['host' => $match[1], 'port' => null];
        }

        $port = (int) $match[2];

        return $port >= 1 && $port <= 65535 ? ['host' => $match[1], 'port' => $port] : null;
    }

    /**
     * The default: the host of app.url, with no port, so `php artisan serve` on :8000 still works.
     * An app.url with no scheme has no host, so the list is empty.
     *
     * @return list<string>
     */
    private static function appUrlHost(mixed $url): array
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? [$host] : [];
    }

    /**
     * A list of strings; a non-string entry is skipped. Anything that is not a list gives [].
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
