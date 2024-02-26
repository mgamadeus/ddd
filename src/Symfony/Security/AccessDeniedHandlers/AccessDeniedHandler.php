<?php

declare(strict_types=1);
// src/Security/AccessDeniedHandler.php
namespace DDD\Symfony\Security\AccessDeniedHandlers;

use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

class AccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        $exception = new UnauthorizedException('Unauthorized');
        return new RestResponseDto($exception->toJSON(), $exception->getCode(), [], true);
    }
}