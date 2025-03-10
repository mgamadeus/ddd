<?php

declare (strict_types=1);

namespace DDD\Symfony\Http\ValueResolvers;

use DDD\Presentation\Base\Dtos\RequestDto;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Resolves RequestDto Values, we use this value resolver in order to avoid symfony storing RequestDto Instiances in Container
 * In Worker Modes (e.g. FrankenPHP worker Mode), otherwise they are not recreated and have old data inside them from previous
 * requests
 */
class DtoValueResolver implements ValueResolverInterface
{
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        if (is_a($argument->getType(),RequestDto::class,true) === false) {
            return [];
        }
        /** @var RequestDto $dto */
        $dto = new ($argument->getType())();
        $dto->setPropertiesFromRequest($request);
        return [$dto];
    }
}