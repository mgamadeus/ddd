<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Http;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SSRF guard for OUTBOUND server-side requests to a caller-supplied URL (a webhook delivery, a link preview, a
 * client-metadata fetch, any "we call a URL the user gave us" surface). It answers
 * ONE question: is this URL safe for OUR server to call — i.e. does it point at the PUBLIC internet and not at our own
 * internal network?
 *
 * Why this exists: a URL the customer chooses is fetched BY OUR SERVER, FROM INSIDE our network — so our server can
 * reach internal, unauthenticated services (Elasticsearch on localhost:9200, cloud metadata on 169.254.169.254, …)
 * that the customer never could. Rights/scope do NOT protect this: the customer accesses no data of theirs, they use
 * our server as a proxy into our network. So EVERY such URL must be checked at TWO points (defense in depth):
 *   1. at REGISTRATION ({@see assertUrlIsPublic}) — reject an internal URL before it is ever stored, and
 *   2. at the FETCH ({@see resolvePublicIps}) — resolve once, check every address, and CONNECT TO A CHECKED ADDRESS.
 *
 * DNS rebinding: a check that resolves the host and then lets the HTTP client resolve it AGAIN on connect proves
 * nothing — a host with a TTL-0 record answers a public address to the check and an internal one to the connect.
 * {@see resolvedHostsAreAllPublic} is such a check-only answer and does NOT protect a fetch. A fetch therefore pins
 * the connect to the address the guard checked, with redirects off (a redirect is a new host: guard and pin it again):
 *
 *     $publicIps = PublicUrlGuard::resolvePublicIps($url);
 *     if ($publicIps === null) {
 *         // refused: malformed, not http(s), unresolvable, or at least one non-public address
 *     }
 *     $response = $httpClient->request('GET', $url, [
 *         'max_redirects' => 0,
 *         'resolve' => [(string)parse_url($url, PHP_URL_HOST) => $publicIps[0]],
 *     ]);
 *
 * The check resolves the host to ALL its IPs and rejects private / loopback / link-local / reserved ranges
 * (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` and Symfony's private subnets), plus a
 * literal-IP fast path (an IP host is checked directly, no DNS). Scheme is restricted to http/https.
 */
class PublicUrlGuard
{
    /** @var string[] The only schemes an outbound request may use. */
    protected const array ALLOWED_SCHEMES = ['http', 'https'];

    /** {@see classifyHost()} verdicts. */
    public const string HOST_PUBLIC = 'PUBLIC';
    public const string HOST_PRIVATE = 'PRIVATE';
    public const string HOST_UNRESOLVABLE = 'UNRESOLVABLE';
    public const string HOST_UNKNOWN = 'UNKNOWN';

    /**
     * Throws {@see PublicUrlGuardException} when `$url` is not a public http(s) URL our server may safely call:
     * malformed, non-http(s) scheme, no host, or a host that resolves to a private / loopback / link-local /
     * reserved address. Call at the REGISTRATION gate so an internal target is never stored.
     */
    public static function assertUrlIsPublic(string $url, string $fieldName = 'url'): void
    {
        static::resolvePublicIpsOrThrow($url, $fieldName);
    }

    /**
     * The FETCH gate: every address `$url`'s host resolves to, when ALL of them are public and the URL is a valid
     * http(s) URL — null otherwise (refused: malformed, non-http(s), no host, unresolvable, or at least one private /
     * loopback / link-local / reserved address). A literal-IP host answers itself. The caller pins its connect to
     * one of the returned addresses (`'resolve' => [$host => $publicIps[0]]`, redirects off) so the HTTP client
     * never resolves the host a second time — see the class docblock.
     *
     * @return string[]|null non-empty list of public IPs, or null when the URL is refused
     */
    public static function resolvePublicIps(string $url): ?array
    {
        try {
            return static::resolvePublicIpsOrThrow($url, 'url');
        } catch (PublicUrlGuardException) {
            return null;
        }
    }

    /**
     * Check-only answer: true when the URL's host resolves exclusively to public IPs right now. It does NOT protect a
     * fetch against DNS rebinding — the HTTP client resolves again on connect. A fetch uses {@see resolvePublicIps}
     * and pins the connect to the checked address.
     */
    public static function resolvedHostsAreAllPublic(string $url): bool
    {
        return static::resolvePublicIps($url) !== null;
    }

    /**
     * Validates `$url` and resolves its host to ALL its IPs, each of them public.
     *
     * @return string[] non-empty
     * @throws PublicUrlGuardException
     */
    protected static function resolvePublicIpsOrThrow(string $url, string $fieldName): array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new PublicUrlGuardException("{$fieldName} must be a valid http(s) URL.");
        }
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
        if (!in_array($scheme, static::ALLOWED_SCHEMES, true)) {
            throw new PublicUrlGuardException("{$fieldName} must use http or https.");
        }
        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            throw new PublicUrlGuardException("{$fieldName} must contain a host.");
        }
        $resolvedIps = static::resolveHostToIps($host);
        if ($resolvedIps === []) {
            throw new PublicUrlGuardException("{$fieldName} host could not be resolved.");
        }
        foreach ($resolvedIps as $ip) {
            if (!static::isPublicIp($ip)) {
                throw new PublicUrlGuardException(
                    "{$fieldName} points to a private/internal address, which is not allowed."
                );
            }
        }
        return $resolvedIps;
    }

    /**
     * Non-throwing classification of a URL's host for callers that must ANSWER honestly rather than refuse:
     * PUBLIC (every resolved address is routable), PRIVATE (at least one private / loopback / link-local address),
     * UNRESOLVABLE (the resolver answered and holds NO A/AAAA record — no such website, or a typo), UNKNOWN (no
     * usable host, or the resolver itself failed — a timeout/SERVFAIL is not "no such domain"). An IDN host is
     * punycode-encoded before the lookup when the intl extension is available.
     *
     * @return self::HOST_*
     */
    public static function classifyHost(string $url): string
    {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            // a bare host without a scheme parses as a path — retry with a scheme
            $host = (string)(parse_url('https://' . ltrim($url, '/'), PHP_URL_HOST) ?? '');
        }
        // parse_url() keeps the brackets of an IPv6 literal
        $host = trim(rtrim($host, '.'), '[]');
        // Not a hostname at all (spaces, …) → unknown, never a DNS lookup on garbage.
        if ($host === '' || (filter_var($host, FILTER_VALIDATE_IP) === false && !preg_match('/^[\p{L}\p{N}._-]+$/u', $host))) {
            return static::HOST_UNKNOWN;
        }
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $host)) {
            $asciiHost = idn_to_ascii($host);
            if (is_string($asciiHost) && $asciiHost !== '') {
                $host = $asciiHost;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return static::isPublicIp($host) ? self::HOST_PUBLIC : self::HOST_PRIVATE;
        }
        $dnsRecords = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($dnsRecords === false) {
            return static::HOST_UNKNOWN;
        }
        $ips = [];
        foreach ($dnsRecords as $dnsRecord) {
            if (isset($dnsRecord['ip'])) {
                $ips[] = $dnsRecord['ip'];
            } elseif (isset($dnsRecord['ipv6'])) {
                $ips[] = $dnsRecord['ipv6'];
            }
        }
        if ($ips === []) {
            // dns_get_record answers [] for NXDOMAIN and for a CNAME-only answer alike on some resolvers — the
            // gethostbyname fallback follows the CNAME chain before the host is called unresolvable.
            $resolved = gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $resolved;
            }
        }
        if ($ips === []) {
            return static::HOST_UNRESOLVABLE;
        }
        foreach ($ips as $ip) {
            if (!static::isPublicIp($ip)) {
                return static::HOST_PRIVATE;
            }
        }
        return static::HOST_PUBLIC;
    }

    /**
     * Whether one IP (v4 or v6) is a routable PUBLIC address — NOT private, loopback, link-local, or reserved. The PHP
     * range flags miss some internal ranges (carrier-grade NAT 100.64.0.0/10, IPv4-mapped / 6to4 / Teredo IPv6, …),
     * so the address must also lie outside Symfony's {@see IpUtils::PRIVATE_SUBNETS}.
     */
    protected static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && !IpUtils::checkIp($ip, IpUtils::PRIVATE_SUBNETS);
    }

    /**
     * Resolve a host to ALL its IPs. A literal IP host returns itself (no DNS). A DNS name is resolved to every A /
     * AAAA record so a name that maps to one public + one private IP is still rejected (an attacker cannot hide an
     * internal address behind a name with a public alias).
     *
     * @return string[]
     */
    protected static function resolveHostToIps(string $host): array
    {
        $literalIp = trim($host, '[]');
        if (filter_var($literalIp, FILTER_VALIDATE_IP) !== false) {
            return [$literalIp];
        }
        // RFC 6761: a localhost name is loopback, whatever a resolver search domain makes of it
        $lowerCaseHost = strtolower(rtrim($host, '.'));
        if ($lowerCaseHost === 'localhost' || str_ends_with($lowerCaseHost, '.localhost')) {
            return ['127.0.0.1'];
        }
        $ips = [];
        $dnsRecords = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($dnsRecords as $dnsRecord) {
            if (isset($dnsRecord['ip'])) {
                $ips[] = $dnsRecord['ip'];
            } elseif (isset($dnsRecord['ipv6'])) {
                $ips[] = $dnsRecord['ipv6'];
            }
        }
        if ($ips === []) {
            // Fallback for environments where dns_get_record is restricted: gethostbyname (A only).
            $resolved = gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $resolved;
            }
        }
        return $ips;
    }
}
