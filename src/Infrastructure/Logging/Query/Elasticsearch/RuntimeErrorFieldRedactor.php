<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Read-side redaction of the text fields a runtime-error view shows (message, stack trace, route): bearer tokens,
 * JWTs, Anthropic keys, DSN credentials, secret key=value pairs and e-mail addresses. Lines written before the issues
 * log redacted on write still pass through it.
 */
class RuntimeErrorFieldRedactor extends ValueObject
{
    public const string REDACTED = '[REDACTED]';
    public const string REDACTED_EMAIL = '[REDACTED_EMAIL]';
    public const string REDACTED_TOKEN = '[REDACTED_TOKEN]';

    protected const string EMAIL_PATTERN = '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i';
    protected const string JWT_PATTERN = '/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\b/';
    protected const string BEARER_PATTERN = '/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/i';
    protected const string ANTHROPIC_KEY_PATTERN = '/sk-ant-[A-Za-z0-9\-]{20,}/';
    protected const string DSN_CREDENTIALS_PATTERN = '/\b(mysql|postgres|postgresql|redis|amqp):\/\/[^\s@\/]+@/i';
    protected const string SECRET_KEY_VALUE_PATTERN =
        '/\b(token|signature|sig|password|passwd|secret|api[_\-]?key|apikey|auth|jwt|session)'
        . '\s*[:=]\s*([^\s,;&]+)/i';

    public function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $redacted = (string)preg_replace(self::BEARER_PATTERN, 'Bearer ' . self::REDACTED_TOKEN, $text);
        $redacted = (string)preg_replace(self::JWT_PATTERN, self::REDACTED_TOKEN, $redacted);
        $redacted = (string)preg_replace(self::ANTHROPIC_KEY_PATTERN, self::REDACTED_TOKEN, $redacted);
        $redacted = (string)preg_replace(self::DSN_CREDENTIALS_PATTERN, '$1://' . self::REDACTED . '@', $redacted);
        $redacted = (string)preg_replace(self::SECRET_KEY_VALUE_PATTERN, '$1=' . self::REDACTED, $redacted);
        return (string)preg_replace(self::EMAIL_PATTERN, self::REDACTED_EMAIL, $redacted);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
