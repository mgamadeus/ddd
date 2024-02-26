<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs;

use DDD\Infrastructure\Base\DateTime\DateTime;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * This class encodes in JWT parameters and decodes them. It attaches an expiration timestamp that keeps the jwt valid for a slice of time,
 * e.g. during a 2 month period, e.g. january and february
 */
class JWTPayload
{
    /**
     * Days the url is available
     * @var int
     */
    public const LIFTETIME_IN_MONTHS = 2;

    public static function getPeriodEndTimestamp(
        DateTime $currentDate,
        int $periodInMonths = self::LIFTETIME_IN_MONTHS
    ): DateTime {
        $endOfMonth = (clone $currentDate)->modify('last day of this month')->setTime(23, 59, 59);
        $monthDifference = (($endOfMonth->format('Y') - $currentDate->format('Y')) * 12) + ($endOfMonth->format(
                    'n'
                ) - $currentDate->format('n'));

        while ($monthDifference < $periodInMonths) {
            $endOfMonth->modify('+1 month')->modify('last day of this month');
            $monthDifference = (($endOfMonth->format('Y') - $currentDate->format('Y')) * 12) + ($endOfMonth->format(
                        'n'
                    ) - $currentDate->format('n'));
        }

        return $endOfMonth;
    }

    /**
     * Encodes an array of associative parameters into a JWT
     * @param array $parameters
     * @param int|null $validityInSeconds
     * @return string
     */
    public static function createJWTFromParameters(array $parameters, ?int $validityInSeconds = null): string
    {
        if ($validityInSeconds) {
            $date = new DateTime();
            $date->modify("+{$validityInSeconds} seconds");
            $expirationDate = $date;
        } else {
            $expirationDate = self::getPeriodEndTimestamp(new DateTime());
        }
        $parameters['expiresAt'] = $expirationDate->getTimestamp();
        return JWT::encode(
            $parameters,
            Config::getEnv('JWT_HASH_KEY'),
            Config::getEnv('JWT_HASH_METHOD')
        );
    }

    /**
     * Decodes JWT into associative parameters, if JWT is valid and if JWT expiration parameter is in the future
     * @param string $jwt
     * @return array|bool
     */
    public static function getParametersFromJWT(string $jwt): array|bool
    {
        try {
            $decoded = JWT::decode(
                $jwt,
                new Key(Config::getEnv('AUTH_JWT_HASH_KEY'),Config::getEnv('AUTH_JWT_HASH_METHOD'))
            );
        } catch (Throwable $t) {
            return false;
        }
        $expiresAt = $decoded->expiresAt;
        if (!$expiresAt || $expiresAt < time()) {
            return false;
        }
        unset($decoded->expiresAt);
        return (array)$decoded;
    }
}
