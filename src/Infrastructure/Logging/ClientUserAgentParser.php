<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

/**
 * A small user agent parser for client errors — browser family, major version and operating system, enough to tell
 * "only Safari on iOS" apart. No dependency; an unknown agent leaves the fields null.
 */
class ClientUserAgentParser
{
    /** Browser patterns, most specific first (Edge and Opera carry `Chrome/` as well, Chrome carries `Safari/`) */
    public const array BROWSER_PATTERNS = [
        'Edge' => '/\bEdg(?:e|A|iOS)?\/(\d+)/',
        'Opera' => '/\b(?:OPR|Opera)\/(\d+)/',
        'Samsung Internet' => '/\bSamsungBrowser\/(\d+)/',
        'Firefox' => '/\b(?:Firefox|FxiOS)\/(\d+)/',
        'Chrome' => '/\b(?:Chrome|CriOS)\/(\d+)/',
        'Safari' => '/\bVersion\/(\d+)(?:\.\d+)*.*\bSafari\//',
        'node' => '/^node\/v?(\d+)/i',
        'React Native' => '/\b(?:okhttp|CFNetwork|ReactNative)\/(\d+)/i',
    ];

    /** Operating system patterns, most specific first (iPadOS and Android carry `Linux` / `Mac OS X` as well) */
    public const array OS_PATTERNS = [
        'iOS' => '/\b(?:iPhone|iPad|iPod)\b/',
        'Android' => '/\bAndroid\b/',
        'ChromeOS' => '/\bCrOS\b/',
        'Windows' => '/\bWindows\b/',
        'macOS' => '/\bMac OS X\b|\bMacintosh\b/',
        'Linux' => '/\bLinux\b/',
    ];

    public function parseUserAgent(?string $userAgent): ClientUserAgent
    {
        $clientUserAgent = new ClientUserAgent();
        if ($userAgent === null || $userAgent === '') {
            return $clientUserAgent;
        }
        foreach (self::BROWSER_PATTERNS as $browserName => $browserPattern) {
            if (preg_match($browserPattern, $userAgent, $matches)) {
                $clientUserAgent->name = $browserName;
                $clientUserAgent->version = $matches[1];
                break;
            }
        }
        foreach (self::OS_PATTERNS as $osName => $osPattern) {
            if (preg_match($osPattern, $userAgent)) {
                $clientUserAgent->osName = $osName;
                break;
            }
        }
        return $clientUserAgent;
    }
}
