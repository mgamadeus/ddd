<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use DDD\Infrastructure\Exceptions\Exception;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * Custom Marshaller implementation used for serialization/unserialization of PHP values
 *
 * Contains method for session data unserialization
 */
class Marshaller implements MarshallerInterface
{
    /**
     * @param array $values
     * @param array|null $failed
     * @return array
     */
    public function marshall(array $values, ?array &$failed): array
    {
        $serialized = $failed = [];

        foreach ($values as $id => $value) {
            try {
                $serialized[$id] = serialize($value);
            } catch (\Exception) {
                $failed[] = $id;
            }
        }

        return $serialized;
    }

    /**
     * Unserialize value
     * @param string $value
     * @return mixed
     * @throws Exception
     */
    public function unmarshall(string $value): mixed
    {
        if ('b:0;' === $value || 'N;' === $value) {
            return null;
        }
        if (':' === ($value[1] ?? ':')) {
            return unserialize($value) ?: null;
        }
        if (str_contains($value, '|') && str_contains($value, ':')) {
            return $this->unserializeSessionData($value);
        }
        return null;
    }

    /**
     * Unserialize session data
     * @param string $sessionSerializedData
     * @param array $unserializedData
     * @return array
     * @throws Exception
     */
    protected function unserializeSessionData(string $sessionSerializedData, array $unserializedData = []): array
    {
        if (!str_contains($sessionSerializedData, '|')) {
            throw new Exception('Error while unserializing session data: ' . $sessionSerializedData);
        }
        $separatorPosition = strpos($sessionSerializedData, '|');
        $key = substr($sessionSerializedData, 0, $separatorPosition);
        $value = unserialize(substr($sessionSerializedData, ++$separatorPosition));
        $unserializedData[$key] = $value;
        $sessionSerializedData = substr($sessionSerializedData, strlen(serialize($value)) + $separatorPosition);

        if ($sessionSerializedData !== '') {
            return $this->unserializeSessionData($sessionSerializedData, $unserializedData);
        }
        return $unserializedData;
    }
}
