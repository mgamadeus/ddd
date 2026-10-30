<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Client;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Who reported: the client runtime and app, shared by every event of one report.
 */
class ClientIssueContext extends ValueObject
{
    public const string RUNTIME_BROWSER = 'browser';
    public const string RUNTIME_NODE = 'node';
    public const string RUNTIME_REACT_NATIVE = 'react-native';
    public const array RUNTIMES = [self::RUNTIME_BROWSER, self::RUNTIME_NODE, self::RUNTIME_REACT_NATIVE];

    public const int MAX_SHORT_TEXT_LENGTH = 64;
    public const int MAX_USER_AGENT_LENGTH = 256;

    /** @var string One of {@see self::RUNTIMES} */
    public string $runtime = self::RUNTIME_BROWSER;

    /** @var string|null The client app, e.g. `web` or `pos` */
    public ?string $app = null;

    /** @var string|null The client build's release (written as `service.version` of the line) */
    public ?string $release = null;

    /** @var string|null A random id per browser tab, to tell one user's repeats from many users */
    public ?string $tabId = null;

    /** @var string|null Node / React Native only; a browser's user agent is read from the request header */
    public ?string $userAgent = null;

    public ?int $viewportWidth = null;

    public ?int $viewportHeight = null;

    /** @var string|null e.g. `de-DE` */
    public ?string $locale = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
