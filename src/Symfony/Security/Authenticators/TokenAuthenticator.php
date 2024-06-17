<?php

declare(strict_types=1);

namespace DDD\Symfony\Security\Authenticators;

use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Infrastructure\Libs\JWTPayload;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class TokenAuthenticator extends AbstractAuthenticator
{
    protected Security $security;
    private AccessMapInterface $accessMap;

    public const string TOKEN_BEARER = 'Bearer';
    public const string TOKEN_BASIC = 'Basic';

    public function __construct(Security $security, AccessMapInterface $accessMap)
    {
        $this->security = $security;
        $this->accessMap = $accessMap;
    }

    public function isPublicAccess(Request $request): bool
    {
        [$config, $context] = $this->accessMap->getPatterns($request);
        return in_array(AuthenticatedVoter::PUBLIC_ACCESS, $config);
    }

    /**
     * Called on every request to decide if this authenticator should be
     * used for the request. Returning `false` will cause this authenticator
     * to be skipped.
     */
    public function supports(Request $request): ?bool
    {
        // if the URL is public access, we skip authentication
        if ($this->isPublicAccess($request)) {
            return false;
        }
        // if there is already an authenticated user (likely due to the session)
        // then return false and skip authentication: there is no need.
        if ($this->security->getUser()) {
            return false;
        }
        if (!$authorizationHeader = $request->headers->get('Authorization')) {
            return false;
        }
        if (str_starts_with($authorizationHeader, self::TOKEN_BEARER)) {
            return true;
        }
        if (str_starts_with($authorizationHeader, self::TOKEN_BASIC)) {
            return true;
        }
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        $authorizationHeader = $request->headers->get('Authorization');
        $accountId = null;
        if (str_starts_with($authorizationHeader, self::TOKEN_BEARER)) {
            $token = str_replace(self::TOKEN_BEARER . ' ', '', $authorizationHeader);
            $payload = JWTPayload::getParametersFromJWT($token);
            // refresh tokens cannot be used for login
            if (isset($payload['refreshToken'])) {
                throw new UnauthorizedException('Refresh token cannot be used for login');
            }
            $accountId = $payload['accountId'] ?? null;
            if ($accountId) {
                $accountId = (string)$accountId;
            }
        } elseif (str_starts_with($authorizationHeader, self::TOKEN_BASIC)) {
            $token = str_replace(self::TOKEN_BASIC . ' ', '', $authorizationHeader);
            $authCredentialsString = base64_decode($token);
            if ($authCredentialsString) {
                [$username, $password] = explode(':', $authCredentialsString);
                if ($username && $password) {
                    // we send a json to the accountProvider, so that it knows that it operates in the context of an ApiAccount
                    // instead of an Account
                    $accountId = json_encode(['apiUsername' => $username, 'password' => $password]);
                }
            }
        }
        if (!$accountId) {
            throw new UnauthorizedException('Token authentication failed');
        }
        return new SelfValidatingPassport(new UserBadge($accountId));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // on success, let the request continue
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $exception = new UnauthorizedException('Unauthorized');
        return new RestResponseDto($exception->toJSON(), $exception->getCode(), [], true);
    }
}