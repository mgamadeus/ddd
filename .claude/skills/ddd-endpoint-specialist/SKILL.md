---
name: ddd-endpoint-specialist
description: Create REST API controllers, DTOs, route attributes, error handling, and OpenAPI documentation in the mgamadeus/ddd framework. Use when creating or modifying API endpoints, request/response DTOs, or controller logic.
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# DDD Endpoint Specialist

Controllers, DTOs, routing, error handling, and OpenAPI documentation within the DDD Core framework (`mgamadeus/ddd`).

## When to Use

- Creating or modifying REST API controllers
- Creating request/response DTOs
- Configuring route attributes and OpenAPI documentation
- Implementing CRUD endpoint patterns
- Understanding error handling and controller conventions

## Namespace

Framework: `DDD\Presentation\Base\Controller` -- base controllers and DTOs.
Application: `App\Presentation\Api\{Audience}\{Domain}\Controller` -- audience-specific endpoints.

## API Audiences

The framework provides the base layer. Consuming applications define audience-specific controllers:

| Audience | Path | Base Controller | Auth |
|----------|------|-----------------|------|
| **Admin** | `/api/admin/*` | `AdminController` | Full access (ROLE_ADMIN) |
| **Client** | `/api/client/*` | `ClientController` | JWT/Bearer |
| **Public** | `/api/public/*` | `PublicController` | None |
| **Batch** | `/api/batch/*` | `HttpController` (direct) | Varies |

### Framework Directory Structure

```
src/Presentation/
+-- Base/
|   +-- Controller/
|   |   +-- BaseController.php          # Foundation
|   |   +-- HttpController.php          # HTTP-aware (extend this)
|   |   +-- DocumentationController.php # OpenAPI docs
|   +-- Dtos/
|   |   +-- RequestDto.php              # Base request DTO
|   |   +-- RestResponseDto.php         # Base REST response DTO
|   |   +-- HtmlResponseDto.php, RedirectResponseDto.php, ExcelResponseDto.php,
|   |   +-- PDFResponseDto.php, ZipResponseDto.php, FileResponseDto.php, ImageResponseDto.php
|   +-- OpenApi/Attributes/            # Summary, Tag, Parameter, etc.
|   +-- Router/Routes/                 # Get, Post, Patch, Update, Delete, Route
```

---

## Controller Template

```php
<?php
declare(strict_types=1);

namespace App\Presentation\Api\{Audience}\{Domain}\Controller;

use App\Domain\{Domain}\Entities\{Resource}\{Resource};
use App\Domain\{Domain}\Entities\{Resource}\{ResourcePlural};
use App\Domain\{Domain}\Services\{ResourcePlural}Service;
use App\Presentation\Api\{Audience}\Base\{Audience}Controller;
use DDD\Presentation\Base\OpenApi\Attributes\{Summary, Tag};
use DDD\Presentation\Base\Router\Routes\{Delete, Get, Patch, Post, Route, Update};

#[Route('/path/to/resource')]
#[Tag(group: '{DomainName}', name: '{ResourceName}', description: '{ResourceName} operations')]
class {Resource}Controller extends {Audience}Controller
{
    // CRUD methods
}
```

### Route & OpenAPI Attributes

```php
// Class-level
#[Route('/domain/resources')]
#[Tag(group: 'Common', name: 'Resources', description: 'Resource related Endpoints')]

// Method-level HTTP verbs
#[Get('/list')]              // GET collection
#[Get('/{resourceId}')]      // GET single
#[Post('/create')]           // POST create
#[Patch('/{resourceId}')]    // PATCH partial update
#[Update]                    // PUT full update
#[Delete('/{resourceId}')]   // DELETE

// Summary convention: Subject first, max 7 words
// CRUD: 'Resources List', 'Resource Details', 'Resource Creation', 'Resource Update', 'Resource Deletion'
// Custom: 'SupportTicket Create AI Resolution'
#[Summary('Resource Details')]

// Route with regex requirement
#[Get('/{accessCodeOrId}', requirements: ['accessCodeOrId' => '[^/]+'])]

// Logging (application-level)
#[LogRequest(logTemplate: LogRequest::LOG_TEMPLATE_LOG_ALL)]

// Request caching (GET endpoints)
use DDD\Symfony\EventListeners\RequestCacheSubscriber\RequestCache;
#[RequestCache(ttl: 300, considerCurrentAuthAccountForCacheKey: true)]
// Bypass cache with ?noCache=true query parameter
```

### OpenAPI Documentation Attributes

Beyond `#[Summary]` and `#[Tag]`, the framework provides:

```php
use DDD\Presentation\Base\OpenApi\Attributes\{Info, SecurityScheme, Server, Ignore, Required, Enum};

// Controller class-level
#[Info(title: 'My API', version: '1.0')]
#[SecurityScheme(securityScheme: 'Bearer', type: 'http', scheme: 'bearer', bearerFormat: 'JWT')]
#[Server(url: 'https://api.example.com')]

// Method-level
#[Ignore]                    // Hide from OpenAPI docs entirely

// Property-level (on DTOs)
#[Required]                  // Mark as required in OpenAPI spec
#[Enum('ACTIVE', 'INACTIVE')]  // Restrict values in OpenAPI spec
```

---

## CRUD Operations

> **Rules for all methods:**
> - Always set `$service->throwErrors = true;` before any service call
> - Use pass-by-reference (`&$requestDto`) for DTOs with `DtoQueryOptionsTrait`
> - Services are auto-injected via Symfony DI
> - Document all exceptions in PHPDoc (`@throws`)

### List (GET /list)

```php
#[Get('/list')]
#[Summary('Resources List')]
public function list(
    ResourcesGetRequestDto &$requestDto,
    ResourcesService $resourcesService
): ResourcesGetResponseDto {
    ResourcePlural::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);
    $resourcesService->throwErrors = true;

    $responseDto = new ResourcesGetResponseDto();
    $responseDto->resources = $resourcesService->findAll();
    $responseDto->resources->expand();
    return $responseDto;
}
```

### Get (GET /{id})

```php
#[Get('/{resourceId}')]
#[Summary('Resource Details')]
public function get(
    ResourceGetRequestDto &$requestDto,
    ResourcesService $resourcesService
): ResourceGetResponseDto {
    $resourcesService->throwErrors = true;
    Resource::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);

    $resource = $resourcesService->find($requestDto->resourceId);
    $resource->expand();

    $responseDto = new ResourceGetResponseDto();
    $responseDto->resource = $resource;
    return $responseDto;
}
```

### Create (POST /create)

```php
#[Post('/create')]
#[Summary('Resource Creation')]
public function create(
    ResourceCreateRequestDto &$requestDto,
    ResourcesService $resourcesService
): ResourceGetResponseDto {
    $resourcesService->throwErrors = true;

    $responseDto = new ResourceGetResponseDto();
    $responseDto->resource = $resourcesService->create($requestDto->resource);
    return $responseDto;
}
```

### Update (PUT -- upsert pattern)

```php
#[Update]
#[Summary('Resource Update')]
public function update(
    ResourceUpdateRequestDto $requestDto,
    ResourcesService $resourcesService
): ResourceGetResponseDto {
    $resourcesService->throwErrors = true;

    $resourceSent = $requestDto->resource;
    if (isset($resourceSent->id) && $resource = $resourcesService->find($resourceSent->id)) {
        $resource->overwritePropertiesFromOtherObject($resourceSent);
    } else {
        $resource = $resourceSent;
    }

    $responseDto = new ResourceGetResponseDto();
    $responseDto->resource = $resourcesService->update($resource);
    return $responseDto;
}
```

### Delete (DELETE /{id})

```php
#[Delete('/{resourceId}')]
#[Summary('Resource Deletion')]
public function delete(
    ResourceGetRequestDto $requestDto,
    ResourcesService $resourcesService
): DeleteResponseDto {
    $resourcesService->throwErrors = true;

    $resource = $resourcesService->find($requestDto->resourceId);
    $resource->delete();
    return new DeleteResponseDto();
}
```

---

## DTO Patterns

### Path Parameter Request (with QueryOptions)

```php
use DDD\Presentation\Base\QueryOptions\{DtoQueryOptions, DtoQueryOptionsTrait};

#[DtoQueryOptions(baseEntity: Resource::class)]
class ResourceGetRequestDto extends RequestDto
{
    use DtoQueryOptionsTrait;

    /** @var int|string The Resource ID */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int|string $resourceId;
}
```

> Single-entity GET DTOs MUST include `#[DtoQueryOptions]` + `DtoQueryOptionsTrait` for `$select`/`$expand` support.
> Use `&$requestDto` (pass-by-reference) in the controller signature.
> The referenced entity class MUST have `use QueryOptionsTrait;` (see `ddd-query-options-specialist`).

### QueryOptions Request (for list endpoints)

```php
#[DtoQueryOptions(baseEntity: ResourcePlural::class)]
class ResourcesGetRequestDto extends RequestDto
{
    use DtoQueryOptionsTrait;
}
```

### Body Payload Request

```php
class ResourceUpdateRequestDto extends RequestDto
{
    /** @var Resource The Resource to create or update */
    #[Parameter(in: Parameter::BODY, required: true)]
    public Resource $resource;
}
```

### Response DTOs

> **CRITICAL:** Response DTOs MUST extend `RestResponseDto` -- **NEVER** `ResponseDto`.

```php
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class ResourceGetResponseDto extends RestResponseDto
{
    /** @var Resource The requested Resource */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public Resource $resource;
}

class ResourcesGetResponseDto extends RestResponseDto
{
    /** @var ResourcePlural A ResourcePlural EntitySet */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ResourcePlural $resources;
}
```

**Rules:**
- Entity properties are **non-nullable** and have **no default value**
- Always include `#[Parameter(in: Parameter::RESPONSE, required: true)]`
- Always include PHPDoc `@var` type annotation

---

## Specialized Response DTOs

Beyond `RestResponseDto` (JSON), the framework provides response types for different content:

| DTO Class | Content Type | Use Case |
|-----------|-------------|----------|
| `RestResponseDto` | `application/json` | Standard REST API responses |
| `ExcelResponseDto` | `.xlsx` | Excel file downloads (`ExcelResponseDto::fromExcelDocument($doc)`) |
| `PDFResponseDto` | `application/pdf` | PDF downloads (`PDFResponseDto::fromPDFDocument($doc)`) |
| `ImageResponseDto` | `image/jpeg` (configurable) | Image serving with long-term cache |
| `ZipResponseDto` | `application/zip` | ZIP archive downloads |
| `FileResponseDto` | Any MIME type | Generic file downloads |
| `HtmlResponseDto` | `text/html` | HTML page rendering |
| `RedirectResponseDto` | 302 redirect | HTTP redirects |

```php
// Excel export example
public function export(ResourcesService $resourcesService): ExcelResponseDto
{
    $resources = $resourcesService->findAll();
    $excelDocument = $resourcesService->exportToExcel($resources);
    return ExcelResponseDto::fromExcelDocument($excelDocument);
}
```

For file uploads in request DTOs, use `FileSetsDtoTrait` with `#[Parameter(in: Parameter::FILES)]`.

---

## Error Handling

### Exception Classes

```php
use DDD\Infrastructure\Exceptions\BadRequestException;        // 400
use DDD\Infrastructure\Exceptions\UnauthorizedException;      // 401
use DDD\Infrastructure\Exceptions\ForbiddenException;          // 403
use DDD\Infrastructure\Exceptions\NotFoundException;           // 404
use DDD\Infrastructure\Exceptions\MethodNotAllowedException;   // 405
use DDD\Infrastructure\Exceptions\InternalErrorException;      // 500
```

`throwErrors = true` auto-throws `NotFoundException` from `find()`, `BadRequestException` for validation.
DTO validation is handled automatically by the framework before the controller method executes.

---

## Advanced Patterns

### Current User Context

```php
#[Get('/me')]
#[Summary('Current Account Details')]
public function me(
    MyAccountGetRequestDto $requestDto,
    AccountsService $accountsService
): AccountGetResponseDto {
    $accountsService->throwErrors = true;
    $account = AuthService::instance()->getAccount();
    Account::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);
    $account->expand();

    $responseDto = new AccountGetResponseDto();
    $responseDto->account = $account;
    return $responseDto;
}
```

### Custom Action Endpoint

```php
#[Get('/{resourceId}/recalculate')]
#[Summary('Resource Recalculate Metrics')]
public function recalculateMetrics(
    ResourceGetRequestDto $requestDto,
    ResourcesService $resourcesService
): ResourceMetricsResponseDto {
    $resourcesService->throwErrors = true;
    $resource = $resourcesService->find($requestDto->resourceId);
    $metrics = $resource->recalculateMetrics();
    $resource->update();

    $responseDto = new ResourceMetricsResponseDto();
    $responseDto->metrics = $metrics;
    return $responseDto;
}
```

### Nested Resource Endpoint

```php
#[Get('/{resourceId}/subresources')]
#[Summary('Resource SubResources List')]
public function getSubResources(
    ResourceGetRequestDto $resourceRequestDto,
    SubResourcesGetRequestDto $subResourcesRequestDto,
    ResourcesService $resourcesService,
    SubResourcesService $subResourcesService
): SubResourcesGetResponseDto {
    $resourcesService->throwErrors = true;
    $subResourcesService->throwErrors = true;

    $resource = $resourcesService->find($resourceRequestDto->resourceId);
    SubResources::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($subResourcesRequestDto);

    $responseDto = new SubResourcesGetResponseDto();
    $responseDto->subResources = $subResourcesService->findByResource($resource);
    return $responseDto;
}
```

---

## Controller Conventions

### Admin Controllers -- No Role Checks Needed

Admin API routes are behind Symfony security with `ROLE_ADMIN`. Never check for admin roles inside admin controllers.

### DDD Framework DTO Merging

The framework **merges all DTOs** from a single request. Multiple DTOs in one controller method are all populated from the same HTTP request.

```php
public function reply(
    TicketGetRequestDto $ticketDto,       // Path param: ticketId
    ReplyRequestDto $requestDto,          // Body param: body
    TicketsService $ticketsService,
): MessageGetResponseDto { /* ... */ }
```

### Controllers Are Thin

Controllers should ONLY: load entities from path parameters, delegate to services, build response DTOs.
Controllers should NEVER: contain business logic, implement complex resolution, create domain entities.

### Pass Objects Not IDs

```php
// CORRECT
$ticket = $ticketsService->find($requestDto->ticketId);
$message = $messagesService->sendReply($ticket, $requestDto->body);

// WRONG
$message = $messagesService->sendReply($requestDto->ticketId, $requestDto->body);
```

---

## Critical Rules

- **NEVER** use `private` -- always `protected`. The `private` keyword destroys extensibility. This is a DDD framework -- every class may be extended. No exceptions.
- **NEVER** extend `ResponseDto` for REST responses -- always extend `RestResponseDto`
- **ALWAYS** `declare(strict_types=1)` in every file
- **ALWAYS** set `$service->throwErrors = true;` before service calls

## Checklist

- [ ] Controller extends appropriate base controller
- [ ] `#[Route]` and `#[Tag]` class-level attributes
- [ ] Request DTOs (path params, QueryOptions, body payload)
- [ ] Response DTOs extending `RestResponseDto`
- [ ] HTTP verb attributes + `#[Summary]` on each method
- [ ] `throwErrors = true` on all services
- [ ] QueryOptions for list/get, `expand()` for detail endpoints
- [ ] Exception PHPDoc (`@throws`)
- [ ] Thin controller -- business logic in services
- [ ] Entity objects (not IDs) passed to service methods
