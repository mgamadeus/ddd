<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Datafilter;
use DDD\Infrastructure\Libs\Encrypt;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Infrastructure\Traits\ValidatorTrait;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Symfony\Extended\EncryptedCookie;
use ReflectionException;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class RequestDto
{
    use SerializerTrait, ValidatorTrait;

    /** @var string If set to true, no EntityRegistry Argus Caching will be used */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public bool $noCache = false;

    protected RequestStack $requestStack;

    protected array $propertiesSetFromBody = [];

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
        $this->setPropertiesFromRequest($this->requestStack->getCurrentRequest());
    }


    /**
     * Populates data from current request to dto
     * @param Request $request
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function setPropertiesFromRequest(Request $request): void
    {
        $reflection = ReflectionClass::instance(static::class);

        $callObject = (object)[];
        $body = $request->getContent();
        $bodyDecoded = $body ? json_decode($body) : null;

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            /** @var Parameter $oaParameter */
            $oaParameter = null;
            foreach ($property->getAttributes(Parameter::class) as $oaParameterAttribute) {
                $oaParameter = $oaParameterAttribute->newInstance();
            }
            // we apply values only to parameters that have proper attribute
            if (!$oaParameter) {
                continue;
            }
            $propertyIsPresentInRequest = false;
            if ($oaParameter->in == Parameter::QUERY && $request->query->has($propertyName)) {
                $propertyIsPresentInRequest = true;
                $callObject->$propertyName = Datafilter::sanitizeInput($request->query->get($propertyName));
            }
            if ($oaParameter->in == Parameter::PATH && $request->attributes->has($propertyName)) {
                $propertyIsPresentInRequest = true;
                $callObject->$propertyName = Datafilter::sanitizeInput($request->attributes->get($propertyName));
            }
            if ($oaParameter->in == Parameter::HEADER && $request->headers->has($propertyName)) {
                $propertyIsPresentInRequest = true;
                $callObject->$propertyName = Datafilter::sanitizeInput($request->headers->get($propertyName));
            }
            if ($oaParameter->in == Parameter::COOKIE && $request->cookies->has($propertyName)) {
                $propertyIsPresentInRequest = true;
                $callObject->$propertyName = Datafilter::sanitizeInput(
                    EncryptedCookie::getEncryptedCookie($request, $propertyName)
                );
            }
            if ($oaParameter->in == Parameter::POST && $request->request->has($propertyName)) {
                $propertyIsPresentInRequest = true;
                $callObject->$propertyName = Datafilter::sanitizeInput($request->request->get($propertyName));
            }
            if ($oaParameter->in == Parameter::BODY && isset($bodyDecoded->$propertyName)) {
                $propertyIsPresentInRequest = true;
            }
            if ($oaParameter->in == Parameter::FILES && $request->files->count()) {
                $propertyIsPresentInRequest = true;
                $callObject->$propertyName = $request->files->all();
            }
            if (!$propertyIsPresentInRequest && $oaParameter->isRequired()) {
                throw new BadRequestException('Property "' . $propertyName . '" is missing in ' . static::class);
            }
        }
        $this->setPropertiesFromObject($callObject);

        // if an encryption password is present, e.g. in header or cookie, we store it statically in Encrypt class
        if (isset($this->encryptionPassword)) {
            Encrypt::$password = $this->encryptionPassword;
        }

        if ($bodyDecoded) {
            foreach ($bodyDecoded as $propertyName => $value) {
                $this->propertiesSetFromBody[$propertyName] = true;
            }
            $this->setPropertiesFromObject($bodyDecoded, sanitizeInput: true);
        }
        $validationResults = $this->validate(depth: 1);
        if ($validationResults !== true) {
            $badRequestException = new BadRequestException('Request contains invalid data');
            $badRequestException->validationErrors = $validationResults;
            throw $badRequestException;
        }
        if ($this->noCache) {
            DDDService::instance()->deactivateCaches();
        }
    }

    /**
     * @return void Usefull for eliminating body from appearing e.g. on Logs in DTO again
     */
    public function unsetPropertiesFromBody(): void
    {
        foreach ($this->propertiesSetFromBody as $propertyName => $true) {
            unset($this->$propertyName);
        }
    }

    public function uniqueKey(): string
    {
        return 'requestDto';
    }

    public function equals(DefaultObject &$other): bool
    {
        return true;
    }
}
