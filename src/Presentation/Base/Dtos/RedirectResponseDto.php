<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * RedirectResponse represents an HTTP response doing a redirect.
 */
class RedirectResponseDto extends RedirectResponse
{
    public const DEFAULT_HTTP_CODE = 302;

    /**
     * Creates a redirect response so that it conforms to the rules defined for a redirect status code.
     *
     * @param string $url     The URL to redirect to. The URL should be a full URL, with schema etc.,
     *                        but practically every browser redirects on paths only as well
     * @param int    $status  The status code (302 by default)
     * @param array  $headers The headers (Location is always set to the given URL)
     *
     * @throws InvalidArgumentException
     *
     * @see https://tools.ietf.org/html/rfc2616#section-10.3
     */
    public function __construct(string $url, int $status = self::DEFAULT_HTTP_CODE, array $headers = [])
    {
        parent::__construct($url, $status, $headers);
    }
}