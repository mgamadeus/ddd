<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Datafilter;
use DDD\Infrastructure\Libs\Encrypt;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Infrastructure\Traits\ValidatorTrait;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Symfony\Extended\EncryptedCookie;
use ReflectionAttribute;
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

    protected ?RequestStack $requestStack = null;

    protected array $propertiesSetFromBody = [];

    public function __construct(?RequestStack $requestStack = null)
    {
        $this->requestStack = $requestStack;
        if ($this->requestStack) {
            $this->setPropertiesFromRequest($this->requestStack->getMainRequest());
        }
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

        // Body decoding — supports two transport shapes on the same endpoint:
        //
        //   1. application/json (classical): JSON document IS the request body. Read via getContent().
        //   2. multipart/form-data (binary uploads): the JSON envelope is carried in a form field named "body",
        //      and Parameter::FILES properties are populated from $request->files (already handled below at
        //      Parameter::FILES branch). getContent() in this case returns the raw multipart-encoded bytes,
        //      which json_decode cannot parse — so we read the JSON envelope from the named form field instead.
        //
        // Why this matters: it lets endpoints accept binary file uploads alongside their normal JSON DTO body
        // (e.g. chat messages with image attachments) without splitting into two endpoints, without changing
        // the DTO contract, and without any per-endpoint dispatching. Endpoints that don't use FILES are
        // entirely unaffected — they only ever see Content-Type: application/json and take the existing branch.
        //
        // Convention: clients sending multipart MUST place the JSON envelope under form field name "body".
        $contentType = (string) $request->headers->get('Content-Type');
        if (str_starts_with($contentType, 'multipart/')) {
            $jsonEnvelope = $request->request->get('body');
            $bodyDecoded = $jsonEnvelope ? json_decode($jsonEnvelope) : null;
        } else {
            $body = $request->getContent();
            $bodyDecoded = $body ? json_decode($body) : null;
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            /** @var Parameter $oaParameter */
            $oaParameter = null;
            foreach ($property->getAttributes(Parameter::class, ReflectionAttribute::IS_INSTANCEOF) as $oaParameterAttribute) {
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
                // Assign Parameter::FILES directly on $this and bypass setPropertiesFromObject entirely.
                //
                // Rationale: $request->files->all() returns already-instantiated UploadedFile objects, often
                // nested by form field name (e.g. ['attachmentFiles' => [UploadedFile, UploadedFile, ...]]).
                // The generic serializer's array-handling path assumes every object-typed array entry is a
                // plain data hash to be hydrated via `new $type()` followed by setPropertiesFromObject() —
                // which fails for UploadedFile because its constructor requires arguments
                // ("Too few arguments to function UploadedFile::__construct()"). Routing FILES through the
                // serializer is also pointless: the values are already strongly-typed objects, there is
                // nothing to hydrate.
                //
                // We also flatten the structure into a single list. Symfony's $request->files->all() can
                // return either a flat list or a nested array depending on field naming
                // (e.g. `attachmentFiles[]` vs separate `file1`, `file2` fields). Callers expect a flat
                // UploadedFile[] regardless of how the client structured the multipart parts.
                $flattened = [];
                array_walk_recursive($request->files->all(), function ($v) use (&$flattened) {
                    if ($v instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                        $flattened[] = $v;
                    }
                });
                $this->$propertyName = $flattened;
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