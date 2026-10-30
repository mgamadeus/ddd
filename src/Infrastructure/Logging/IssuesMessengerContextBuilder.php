<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Infrastructure\Base\DateTime\DateTime;
use ReflectionObject;
use ReflectionProperty;
use Throwable;

/**
 * The issues-log context of a Messenger message, the worker counterpart of a request's route parameters: what the
 * message is about (`messenger.message_keys` — its scalar public properties, ids and short strings) and where it came
 * from (`messenger.origin.*`, stamped by {@see AppMessage::dispatch()}) with its queue delay (`messenger.age_seconds`).
 * Display-only, never a hash input. Stateless; the redactor still runs over the result (secret-looking keys).
 */
class IssuesMessengerContextBuilder
{
    /** At most this many message keys */
    public const int MAX_MESSAGE_KEYS = 20;

    /** Framework properties of every message, not about what it carries */
    public const array FRAMEWORK_PROPERTIES = ['objectType'];

    /** A string message key is cut here */
    public const int MAX_MESSAGE_KEY_LENGTH = 128;

    /**
     * @return array<string, mixed> Issues-log context keys (PSR-3 context)
     */
    public function buildMessageContext(object $message): array
    {
        $context = ['messenger.message_class' => get_debug_type($message)];
        $messageKeys = $this->getMessageKeys($message);
        if ($messageKeys !== []) {
            $context['messenger.message_keys'] = $messageKeys;
        }
        if ($message instanceof AppMessage) {
            $appMessageDispatchOrigin = $message->dispatchOrigin ?? null;
            $dispatchedAt = $appMessageDispatchOrigin?->dispatchedAt;
            $context += array_filter([
                'messenger.origin.route' => $appMessageDispatchOrigin?->route,
                'messenger.origin.command' => $appMessageDispatchOrigin?->command,
                'messenger.origin.account_id' => $message->accountId ?? null,
                'messenger.origin.workspace' => $message->dispatchedFromEnvironment ?? null,
                'messenger.origin.dispatched_at' => $dispatchedAt !== null ? gmdate(DateTime::DATE_RFC3339_ZULU, $dispatchedAt->getTimestamp()) : null,
                'messenger.age_seconds' => $dispatchedAt !== null ? max(0, time() - $dispatchedAt->getTimestamp()) : null,
            ], static fn(mixed $value): bool => $value !== null);
        }
        return $context;
    }

    /**
     * The message's scalar public properties (not the dispatch origin), strings capped.
     *
     * @return array<string, int|float|bool|string>
     */
    public function getMessageKeys(object $message): array
    {
        $messageKeys = [];
        try {
            foreach ((new ReflectionObject($message))->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
                if ($reflectionProperty->isStatic() || !$reflectionProperty->isInitialized($message)
                    || in_array($reflectionProperty->getName(), AppMessage::DISPATCH_ORIGIN_PROPERTIES, true)
                    || in_array($reflectionProperty->getName(), self::FRAMEWORK_PROPERTIES, true)) {
                    continue;
                }
                $value = $reflectionProperty->getValue($message);
                if ($value === null || !is_scalar($value)) {
                    continue;
                }
                $messageKeys[$reflectionProperty->getName()] = is_string($value) ? mb_substr($value, 0, self::MAX_MESSAGE_KEY_LENGTH) : $value;
                if (count($messageKeys) >= self::MAX_MESSAGE_KEYS) {
                    break;
                }
            }
        } catch (Throwable) {
        }
        return $messageKeys;
    }
}
