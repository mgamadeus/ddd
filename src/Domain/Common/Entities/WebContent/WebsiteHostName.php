<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\WebContent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The host of a web address as two addresses of one site share it: lower-cased, without `www.`, '' when the address
 * carries none. A bare domain ("example.com") reads as the host of https://example.com.
 */
class WebsiteHostName extends ValueObject
{
    /** The lower-cased host without `www.`; '' when the address carries none. */
    public string $host = '';

    public static function fromUrl(string $url): static
    {
        $websiteHostName = new static();
        $url = trim($url);
        if ($url === '') {
            return $websiteHostName;
        }
        $host = parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST);
        $host = mb_strtolower(trim((string)$host));
        $websiteHostName->host = preg_replace('/^www\./', '', $host) ?? $host;
        return $websiteHostName;
    }
}
