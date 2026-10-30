<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Client;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Something the client did before the error: a navigation, a fetch, a console line, a UI action.
 */
class ClientIssueBreadcrumb extends ValueObject
{
    public const string CATEGORY_NAVIGATION = 'navigation';
    public const string CATEGORY_FETCH = 'fetch';
    public const string CATEGORY_CONSOLE = 'console';
    public const string CATEGORY_UI = 'ui';
    public const string CATEGORY_CUSTOM = 'custom';
    public const array CATEGORIES = [
        self::CATEGORY_NAVIGATION, self::CATEGORY_FETCH, self::CATEGORY_CONSOLE, self::CATEGORY_UI, self::CATEGORY_CUSTOM,
    ];

    public const int MAX_MESSAGE_LENGTH = 256;
    public const int MAX_URL_LENGTH = 1024;

    /** @var string When it happened (ISO 8601, client clock) */
    public string $at = '';

    /** @var string One of {@see self::CATEGORIES}; anything else becomes `custom` */
    public string $category = self::CATEGORY_CUSTOM;

    public string $message = '';

    /** @var string|null Fetch: the requested URL */
    public ?string $url = null;

    /** @var string|null Navigation: the URL before */
    public ?string $from = null;

    /** @var string|null Navigation: the URL after */
    public ?string $to = null;

    /** @var int|null Fetch: the HTTP status */
    public ?int $status = null;

    public function uniqueKey(): string
    {
        // identical breadcrumbs may legitimately repeat: order matters, duplicates stay
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
