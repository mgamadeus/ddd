<?php

declare(strict_types=1);

namespace DDD\Symfony\Security\Authenticators;

use DDD\Domain\Common\Services\LoginTokensService;
use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class LoginTokenAuthenticator extends AbstractAuthenticator
{
    private Security $security;
    private LoginTokensService $loginTokenService;

    public function __construct(Security $security, LoginTokensService $loginTokenService)
    {
        $this->security = $security;
        $this->loginTokenService = $loginTokenService;
    }

    /**
     * Called on every request to decide if this authenticator should be
     * used for the request. Returning `false` will cause this authenticator
     * to be skipped.
     */
    public function supports(Request $request): ?bool
    {
        // if there is already an authenticated user (likely due to the session)
        // then return false and skip authentication: there is no need.
        if ($this->security->getUser()){
            return false;
        }
        if (!$loginToken = $request->query->get('loginToken')){
            return false;
        }
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $loginToken = $request->query->get('loginToken');

        $authorizationHeader = $request->headers->get('Authorization');
        $accountId = (string) $this->loginTokenService->getAccountIdFromToken($loginToken);
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
