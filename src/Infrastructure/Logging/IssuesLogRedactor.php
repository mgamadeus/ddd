<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

/**
 * Privacy and size of an issues event, applied before any sink sees it:
 *
 * - context values under a secret-looking key (password, secret, token, authorization, cookie, API key, …) become
 *   `[REDACTED]`, also nested (e.g. a messenger `message.payload`);
 * - free text (the exception message) loses e-mail addresses, bearer/basic credentials and long digit runs;
 * - the event is capped: the full stack trace is dropped first, then the short trace, then the message is cut.
 */
class IssuesLogRedactor
{
    public const string REDACTED = '[REDACTED]';

    /** Key fragments (lower case) whose value is never logged */
    public const array SECRET_KEY_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'apikey', 'api_key', 'api-key',
        'credential', 'private_key', 'privatekey', 'session',
    ];

    /** Context keys holding stack traces: free text, sanitised like the message (string arguments carry e-mails) */
    public const array STACK_TRACE_KEYS = ['error.stack_trace', 'error.full_stack_trace'];

    /**
     * Context keys cut in this order when the event is too large: a client event's breadcrumbs (oldest dropped first)
     * and frames (the innermost {@see self::CAPPED_CLIENT_FRAMES} kept) before the traces
     */
    public const array CAP_ORDER = ['client.breadcrumbs', 'client.frames', 'error.full_stack_trace', 'error.stack_trace', 'error.fingerprint'];

    /** The frames a too large client event keeps */
    public const int CAPPED_CLIENT_FRAMES = 10;

    public const string REDACTED_EMAIL = '[EMAIL]';
    public const string REDACTED_PHONE = '[PHONE]';
    public const string REDACTED_JWT = '[JWT]';
    public const string REDACTED_UUID = '[UUID]';
    public const string REDACTED_HEX = '[HEX]';

    /**
     * @param int $numberMinDigits Digit runs of at least this length become `[NUMBER]`; 0 = digits are kept (ids and
     * millisecond timestamps stay readable)
     */
    public function __construct(
        protected int $numberMinDigits = IssuesLogSettings::DEFAULT_SANITIZE_NUMBER_MIN_DIGITS,
        /** @var string[] URL path segments whose next segment is a credential, see {@see self::redactUrl()} */
        protected array $redactPathSegments = IssuesLogSettings::DEFAULT_REDACT_PATH_SEGMENTS,
    ) {
    }

    /**
     * A URL of a client line: the value of every query parameter whose name contains `token`, the segment after a
     * configured path segment (`/invitations/<token>`), UUID and long hex path segments (guest reservation ids are
     * credentials), then the value rules of {@see self::sanitizeClientText()}.
     */
    public function redactUrl(string $url): string
    {
        $url = (string)preg_replace('/([?&][^=&#]*token[^=&#]*=)[^&#]*/i', '$1' . self::REDACTED, $url);
        if ($this->redactPathSegments !== []) {
            $segmentAlternatives = implode('|', array_map(static fn(string $segment): string => preg_quote($segment, '/'), $this->redactPathSegments));
            $url = (string)preg_replace('/(\/(?:' . $segmentAlternatives . ')\/)[^\/?#]+/i', '$1' . self::REDACTED, $url);
        }
        $url = (string)preg_replace('/(?<=\/)[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=[\/?#]|$)/i', self::REDACTED_UUID, $url);
        $url = (string)preg_replace('/(?<=\/)[0-9a-f]{16,}(?=[\/?#]|$)/i', self::REDACTED_HEX, $url);
        return $this->sanitizeClientText($url);
    }

    /**
     * Free text of a client line (message, breadcrumb message): e-mail addresses, bearer/basic credentials, JWTs,
     * phone numbers and UUIDs become placeholders, plus the rules of {@see self::sanitizeText()}. A phone number is a
     * run of 9 to 15 digits with a leading `+` or separators — a bare digit run is left to the number rule, a date is
     * not a phone number.
     */
    public function sanitizeClientText(string $text): string
    {
        $text = (string)preg_replace('/\beyJ[\w-]{5,}\.[\w-]{5,}\.[\w-]{5,}/', self::REDACTED_JWT, $text);
        $text = (string)preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', self::REDACTED_UUID, $text);
        $text = (string)preg_replace_callback(
            '/(?<![\w.\/-])\+?\d[\d ().\/-]{6,}\d(?![\w\/-])/',
            static function (array $matches): string {
                $candidate = $matches[0];
                $digitCount = strlen((string)preg_replace('/\D/', '', $candidate));
                $hasPhoneShape = str_starts_with($candidate, '+') || preg_match('/[ ().\/-]/', $candidate);
                $isDate = preg_match('/^\d{4}[-.\/]\d{1,2}[-.\/]\d{1,2}$|^\d{1,2}[-.\/]\d{1,2}[-.\/]\d{2,4}$/', $candidate);
                return $digitCount >= 9 && $digitCount <= 15 && $hasPhoneShape && !$isDate ? self::REDACTED_PHONE : $candidate;
            },
            $text
        );
        return $this->sanitizeText($text);
    }

    /**
     * Secret-looking keys become `[REDACTED]` (also nested); the stack traces are sanitised like the message.
     *
     * @param array<string|int, mixed> $context
     * @return array<string|int, mixed>
     */
    public function redactContext(array $context): array
    {
        foreach (self::STACK_TRACE_KEYS as $stackTraceKey) {
            if (isset($context[$stackTraceKey]) && is_string($context[$stackTraceKey])) {
                $context[$stackTraceKey] = $this->sanitizeText($context[$stackTraceKey]);
            }
        }
        return $this->redactSecretKeys($context);
    }

    /**
     * @param array<string|int, mixed> $context
     * @return array<string|int, mixed>
     */
    protected function redactSecretKeys(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                $context[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $context[$key] = $this->redactSecretKeys($value);
            }
        }
        return $context;
    }

    public function isSecretKey(string $key): bool
    {
        $lowerKey = strtolower($key);
        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lowerKey, $fragment)) {
                return true;
            }
        }
        return false;
    }

    /** E-mail addresses, bearer/basic credentials and long digit runs (see the constructor) become placeholders */
    public function sanitizeText(string $text): string
    {
        $patterns = [
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i' => '$1 [REDACTED]',
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[EMAIL]',
        ];
        if ($this->numberMinDigits > 0) {
            $patterns['/\d{' . $this->numberMinDigits . ',}/'] = '[NUMBER]';
        }
        return (string)preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * Cuts the event below $maxBytes (message + JSON-encoded context): first the traces (CAP_ORDER), then the message.
     */
    public function capEvent(IssuesLogEvent $event, int $maxBytes): void
    {
        if ($maxBytes <= 0) {
            return;
        }
        foreach (self::CAP_ORDER as $key) {
            if ($this->getEventBytes($event->message, $event->context) <= $maxBytes) {
                return;
            }
            if (!isset($event->context[$key])) {
                continue;
            }
            if (is_array($event->context[$key])) {
                $this->capClientList($event, $key, $maxBytes);
                continue;
            }
            $overflow = $this->getEventBytes($event->message, $event->context) - $maxBytes;
            $value = (string)$event->context[$key];
            $event->context[$key] = strlen($value) > $overflow + 20
                ? substr($value, 0, strlen($value) - $overflow - 20) . '…[cut]'
                : '[cut]';
        }
        $overflow = $this->getEventBytes($event->message, $event->context) - $maxBytes;
        if ($overflow > 0) {
            $event->message = substr($event->message, 0, max(0, strlen($event->message) - $overflow - 20)) . '…[cut]';
        }
    }

    /**
     * A structured client list: breadcrumbs lose their oldest entries until the event fits (all of them if need be),
     * frames are cut to the innermost {@see self::CAPPED_CLIENT_FRAMES}.
     */
    protected function capClientList(IssuesLogEvent $event, string $key, int $maxBytes): void
    {
        if ($key === 'client.frames') {
            $event->context[$key] = array_slice($event->context[$key], 0, self::CAPPED_CLIENT_FRAMES);
            return;
        }
        while ($event->context[$key] !== [] && $this->getEventBytes($event->message, $event->context) > $maxBytes) {
            array_shift($event->context[$key]);
        }
    }

    /** @param array<string|int, mixed> $context */
    protected function getEventBytes(string $message, array $context): int
    {
        return strlen($message) + strlen((string)json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
