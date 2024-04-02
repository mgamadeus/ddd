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
        $offset = 0;
        try {
            while ($offset < strlen($sessionSerializedData)) {
                if (!strstr(substr($sessionSerializedData, $offset), '|')) {
                    throw new Exception("Invalid session data at offset $offset.");
                }
                $pos = strpos($sessionSerializedData, '|', $offset);
                $num = $pos - $offset;
                $key = substr($sessionSerializedData, $offset, $num);
                $offset += $num + 1;

                // Der folgende Teil ist entscheidend:
                // Ermittele den Typ und die Länge des folgenden serialisierten Wertes
                $serializedValue = substr($sessionSerializedData, $offset);
                $value = @unserialize($serializedValue, ['allowed_classes' => false]);
                if ($value === false) {
                    throw new Exception("Unserialize failed at offset $offset.");
                }
                $unserializedData[$key] = $value;

                // Update des Offsets nach der korrekten Länge des unserialisierten Wertes
                $lengthOfSerializedValue = strlen(serialize($value));
                $offset += $lengthOfSerializedValue;
            }
        } catch (\Exception $e) {
            echo 'Caught exception: ', $e->getMessage(), "\n";
        }

        return $unserializedData;
    }

}
