<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Sentry;

/**
 * Turns one issues line (ECS JSON, dotted or nested keys) into a Sentry event (JSON): exception type and message, the
 * stack trace as frames, level, release, environment, server, request, and the issues hashes. Sentry groups by
 * `fingerprint = [error.group_hash]`, so its issues are the registry's groups; `error.issue_hash`,
 * `error.entry_point`, `section`, `route_name` and `account.id` become tags. The event id is `event.id`, so a line
 * forwarded twice is one event in Sentry.
 */
class SentryEventBuilder
{
    public const string PLATFORM = 'php';
    public const string LOGGER = 'issues';

    /** PSR-3 level → Sentry level */
    public const array LEVELS = [
        'debug' => 'debug',
        'info' => 'info',
        'notice' => 'info',
        'warning' => 'warning',
        'error' => 'error',
        'critical' => 'fatal',
        'alert' => 'fatal',
        'emergency' => 'fatal',
    ];

    /** Context fields that become Sentry tags */
    public const array TAG_FIELDS = [
        'error.issue_hash' => 'issue_hash',
        'error.group_hash' => 'group_hash',
        'error.entry_point' => 'entry_point',
        'error.handled' => 'handled',
        'section' => 'section',
        'route_name' => 'route',
        'account.id' => 'account_id',
    ];

    /** Fields already mapped elsewhere, not repeated in `extra` */
    public const array MAPPED_FIELDS = [
        '@timestamp', 'message', 'log.level', 'event.id', 'error.type', 'error.stack_trace', 'error.full_stack_trace',
        'service.version', 'service.environment', 'host.hostname', 'http.request.method', 'url.full', 'url.path',
        'log.origin.file.name', 'log.origin.file.line', 'ecs.version',
    ];

    /**
     * The Sentry event JSON of an issues line; null when the line is not JSON.
     */
    public function buildEventJson(string $jsonLine): ?string
    {
        $fields = $this->flattenFields(json_decode($jsonLine, true));
        if ($fields === null) {
            return null;
        }
        $event = [
            'event_id' => $this->buildEventId($fields),
            'timestamp' => isset($fields['@timestamp']) ? (string)$fields['@timestamp'] : gmdate('Y-m-d\TH:i:s\Z'),
            'platform' => self::PLATFORM,
            'logger' => self::LOGGER,
            'level' => self::LEVELS[strtolower((string)($fields['log.level'] ?? 'error'))] ?? 'error',
            'message' => ['formatted' => (string)($fields['message'] ?? '')],
        ];
        foreach (['release' => 'service.version', 'environment' => 'service.environment', 'server_name' => 'host.hostname'] as $eventKey => $field) {
            if (isset($fields[$field]) && $fields[$field] !== '') {
                $event[$eventKey] = (string)$fields[$field];
            }
        }
        if (isset($fields['error.group_hash'])) {
            $event['fingerprint'] = [(string)$fields['error.group_hash']];
        }
        if (isset($fields['error.type'])) {
            $exception = [
                'type' => (string)$fields['error.type'],
                'value' => (string)($fields['message'] ?? ''),
                'mechanism' => ['type' => 'generic', 'handled' => ($fields['error.handled'] ?? true) !== false],
            ];
            $frames = $this->buildFrames($fields);
            if ($frames !== []) {
                $exception['stacktrace'] = ['frames' => $frames];
            }
            $event['exception'] = ['values' => [$exception]];
        }
        $tags = [];
        foreach (self::TAG_FIELDS as $field => $tag) {
            if (isset($fields[$field]) && is_scalar($fields[$field]) && $fields[$field] !== '') {
                $tags[$tag] = is_bool($fields[$field]) ? ($fields[$field] ? 'true' : 'false') : mb_substr((string)$fields[$field], 0, 200);
            }
        }
        if ($tags !== []) {
            $event['tags'] = $tags;
        }
        if (isset($fields['http.request.method']) || isset($fields['url.full']) || isset($fields['url.path'])) {
            $event['request'] = array_filter([
                'method' => $fields['http.request.method'] ?? null,
                'url' => $fields['url.full'] ?? $fields['url.path'] ?? null,
            ]);
        }
        $extra = array_diff_key($fields, array_flip(self::MAPPED_FIELDS), self::TAG_FIELDS);
        if ($extra !== []) {
            $event['extra'] = $extra;
        }
        return (string)json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** The event's id: `event.id` without dashes (Sentry: 32 hex), else a random one */
    public function buildEventIdFromJsonLine(string $jsonLine): string
    {
        return $this->buildEventId($this->flattenFields(json_decode($jsonLine, true)) ?? []);
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function buildEventId(array $fields): string
    {
        $eventId = strtolower(str_replace('-', '', (string)($fields['event.id'] ?? '')));
        return preg_match('/^[0-9a-f]{32}$/', $eventId) ? $eventId : bin2hex(random_bytes(16));
    }

    /**
     * Dotted keys from both shapes: `{"log.level": …}` and `{"log": {"level": …}}`.
     *
     * @return array<string, mixed>|null
     */
    protected function flattenFields(mixed $decoded, string $prefix = ''): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }
        $fields = [];
        foreach ($decoded as $key => $value) {
            $fieldName = $prefix . $key;
            // nested objects of the ECS shape flatten; lists (e.g. tags) and the context keys with dots stay values
            if (is_array($value) && $value !== [] && !array_is_list($value) && in_array($key, ['log', 'error', 'service', 'host', 'http', 'url', 'event', 'account'], true)) {
                $fields += $this->flattenFields($value, $fieldName . '.') ?? [];
                continue;
            }
            $fields[$fieldName] = $value;
        }
        return $fields;
    }

    /**
     * Sentry frames, oldest first: the PHP trace lines (`#0 /file.php(12): Class->method()`) reversed, the throw site
     * last.
     *
     * @param array<string, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    protected function buildFrames(array $fields): array
    {
        $traceString = (string)($fields['error.full_stack_trace'] ?? $fields['error.stack_trace'] ?? '');
        $frames = [];
        foreach (explode("\n", $traceString) as $traceLine) {
            if (preg_match('/^#\d+\s+(.+?)\((\d+)\):\s+(.+)$/', trim($traceLine), $matches)) {
                $frames[] = [
                    'filename' => $matches[1],
                    'lineno' => (int)$matches[2],
                    'function' => preg_replace('/\(.*\)$/s', '', $matches[3]),
                    'in_app' => !str_contains($matches[1], '/vendor/'),
                ];
            } elseif (preg_match('/^#\d+\s+(\[internal function\]|\{main\}):?\s*(.*)$/', trim($traceLine), $matches)) {
                $frames[] = ['function' => $matches[2] !== '' ? preg_replace('/\(.*\)$/s', '', $matches[2]) : $matches[1], 'in_app' => false];
            }
        }
        $frames = array_reverse($frames);
        if (isset($fields['log.origin.file.name'])) {
            $frames[] = array_filter([
                'filename' => (string)$fields['log.origin.file.name'],
                'lineno' => isset($fields['log.origin.file.line']) ? (int)$fields['log.origin.file.line'] : null,
                'in_app' => true,
            ], static fn(mixed $value): bool => $value !== null);
        }
        return $frames;
    }
}
