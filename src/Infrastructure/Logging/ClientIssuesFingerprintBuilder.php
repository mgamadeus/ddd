<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Common\Entities\Issues\Client\ClientIssueEvent;

/**
 * The stable hashes of a client error, the counterpart of {@see IssuesFingerprintBuilder} for errors without a PHP
 * Throwable:
 *
 * - group hash (root cause) = section | name | normalised message | kind | call site. The section is the one the
 *   application passed, never a client field. The call site is the innermost in-app frame's file with its origin,
 *   query, build directory, content hash and extension stripped (`app/[lang]/menu/[locationSlug]/page`), empty
 *   without an in-app frame — never its line or column, which change with every build.
 * - issue hash (manifestation) = group hash | route pattern.
 * - entry point = `<SECTION>:<route pattern or URL path>`.
 *
 * Trade-off: the same error thrown in two places of one chunk is one group (no line), and a chunk renamed by a refactor
 * starts a new group. Minified messages are made build-independent: short identifiers (≤ 3 characters) before
 * `is not a function|defined|iterable|a constructor` become `{ident}`; numbers, UUIDs, hex runs ≥ 8, URLs and ISO
 * timestamps become placeholders. Quoted literals stay (JavaScript messages carry property names in them) and so do
 * React error codes (`#418`).
 */
class ClientIssuesFingerprintBuilder
{
    /** Prefixes of build output directories stripped from a call site */
    public const array BUILD_DIRECTORY_PREFIXES = ['_next/static/chunks/', '_next/static/', '_next/server/', 'static/js/', 'assets/'];

    public function buildGroupHash(string $section, ClientIssueEvent $clientIssueEvent): string
    {
        return $this->hash(implode('|', [
            $section,
            $clientIssueEvent->name,
            $this->normaliseMessage($clientIssueEvent->message),
            $clientIssueEvent->kind,
            $this->getCallSite($clientIssueEvent),
        ]));
    }

    public function buildIssueHash(string $groupHash, ClientIssueEvent $clientIssueEvent): string
    {
        return $this->hash($groupHash . '|' . ($clientIssueEvent->routePattern ?? ''));
    }

    public function buildEntryPoint(string $section, ClientIssueEvent $clientIssueEvent): string
    {
        return $section . ':' . ($clientIssueEvent->routePattern ?? $this->getUrlPath($clientIssueEvent->url) ?? '');
    }

    /** The build-independent form of a client error message */
    public function normaliseMessage(string $message): string
    {
        $patterns = [
            // minified identifiers: `e is not a function`, `n.map is not a function`
            '/(?<![\w$.])[A-Za-z_$][\w$]{0,2}(?= is not (?:a function|defined|iterable|a constructor)\b)/' => '{ident}',
            '/(?<![\w$.])[A-Za-z_$][\w$]{0,2}(?=\.[\w$]+ is not a function\b)/' => '{ident}',
            '/\bhttps?:\/\/[^\s"\'<>)]+/i' => '{url}',
            '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?\b/' => '{timestamp}',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
            '/\b0x[0-9a-f]+\b|\b(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{8,}\b/i' => '{hex}',
            // a number not part of an identifier and not a React error code (#418)
            '/(?<![#\w$])\d+(\.\d+)?(?![\w$])/' => '{number}',
        ];
        return (string)preg_replace(array_keys($patterns), array_values($patterns), $message);
    }

    /** The innermost in-app frame's file, build-independent; '' without an in-app frame */
    public function getCallSite(ClientIssueEvent $clientIssueEvent): string
    {
        $clientIssueFrame = $clientIssueEvent->frames->getFirstInAppFrame();
        return $clientIssueFrame !== null ? $this->normaliseFilePath($clientIssueFrame->file) : '';
    }

    /**
     * A script path without origin, query, build directory, content hash and extension:
     * `https://x.com/_next/static/chunks/app/[lang]/page-3f2a9c1b8e7d6f50.js?v=1` → `app/[lang]/page`
     */
    public function normaliseFilePath(string $file): string
    {
        $path = (string)preg_replace('/^[a-z][a-z0-9+.-]*:\/\/[^\/]*/i', '', $file);
        $path = (string)preg_replace('/[?#].*$/', '', $path);
        $path = ltrim($path, '/');
        foreach (self::BUILD_DIRECTORY_PREFIXES as $buildDirectoryPrefix) {
            if (str_starts_with($path, $buildDirectoryPrefix)) {
                $path = substr($path, strlen($buildDirectoryPrefix));
                break;
            }
        }
        $path = (string)preg_replace('/\.(m?js|cjs|jsx|tsx?|bundle)$/i', '', $path);
        // content hash: page-3f2a9c1b8e7d6f50, main.3f2a9c1b, _.3f2a9c1b
        return (string)preg_replace('/[-.~][0-9a-f]{8,}$/i', '', $path);
    }

    /** The path of a URL; null without one */
    public function getUrlPath(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : null;
    }

    protected function hash(string $value): string
    {
        return hash(in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'md5', $value);
    }
}
