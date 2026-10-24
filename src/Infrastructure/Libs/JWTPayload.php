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
 *
 * KEYS: {@see self::createJWTFromParameters()} signs with `JWT_HASH_KEY` / `JWT_HASH_METHOD`, while
 * {@see self::getParametersFromJWT()} verifies with `AUTH_JWT_HASH_KEY` / `AUTH_JWT_HASH_METHOD`. The two env pairs
 * MUST hold the same values — tokens minted here only verify because every deployment sets them equal.
 *
 * Every JWT minted here is signed with the same key, so a token's PURPOSE has to be stated in a claim: a login token
 * carries `tokenType: access` ({@see \DDD\Infrastructure\Services\AuthService::TOKEN_TYPE_ACCESS}) and the bearer
 * authenticators accept nothing else. Never add that claim to a purpose token (image URL, deep link).
 */
class JWTPayload
{
    /**
     * Days the url is available
     * @var int
     */
    public const int LIFTETIME_IN_MONTHS = 2;

    public static function getPeriodEndTimestamp(
        DateTime $currentDate,
        int $periodInMonths = self::LIFTETIME_IN_MONTHS
    ): DateTime {
        $endOfMonth = (clone $currentDate)->modify('last day of this month')->setTime(23, 59, 59);
        $monthDifference = ((int)$endOfMonth->format('Y') - (int)$currentDate->format('Y')) * 12
            + ((int)$endOfMonth->format('n') - (int)$currentDate->format('n'));

        while ($monthDifference < $periodInMonths) {
            $endOfMonth->modify('+1 month')->modify('last day of this month');
            $monthDifference = ((int)$endOfMonth->format('Y') - (int)$currentDate->format('Y')) * 12
                + ((int)$endOfMonth->format('n') - (int)$currentDate->format('n'));
        }

        return $endOfMonth;
    }

    /**
     * Encodes an array of associative parameters into a JWT.
     *
     * Expiry precedence: an explicit `$expiresAt` (a fixed boundary — e.g. the end of the current hour — which makes
     * the token byte-stable for every call inside that window, so a URL carrying it stays cacheable) wins; else
     * `$validityInSeconds` from now; else the default period end ({@see getPeriodEndTimestamp()}).
     *
     * @param array $parameters
     * @param int|null $validityInSeconds
     * @param DateTime|null $expiresAt An explicit expiry timestamp; takes precedence over `$validityInSeconds`.
     * @return string
     */
    public static function createJWTFromParameters(
        array $parameters,
        ?int $validityInSeconds = null,
        ?DateTime $expiresAt = null
    ): string {
        if ($expiresAt !== null) {
            $expirationDate = $expiresAt;
        } elseif ($validityInSeconds) {
            $date = new DateTime();
            $date->modify("+$validityInSeconds seconds");
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
        } catch (Throwable) {
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
