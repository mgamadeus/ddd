# Admin error detail — runtime-error screen and drawer

How an application builds the admin screens that show what an error actually was: frames with code, causes, the
request, the console command or the failed job. Two data sources, one occurrence object:

| Source | Endpoint returns | Built by |
|---|---|---|
| Elasticsearch / OpenSearch directly (`RuntimeErrorsService`, no registry) | `RuntimeErrorGroups`, `RuntimeErrorGroupDetail` | `findErrorGroups()`, `findErrorGroupDetail()` |
| The registry (`IssueGroup`, any backend: Loki, ES, Sentry) | `IssueGroup` + its newest events | `IssueGroupsService`, `IssuesQueryBackend::searchEvents()` |

Both drawers render the same `RuntimeErrorOccurrence`. The registry endpoints themselves (list, trend, resolve) are in
SKILL.md › "Admin UI — controller, DTO, list"; this file adds the error detail.

## Contents

1. Runtime-error admin (Elasticsearch): controller and DTOs
2. Registry drawer: the newest events as occurrences
3. The occurrence — real JSON
4. Drawer layout
5. Code context — rendering
6. "Copy for agent"
7. Rules

## 1. Runtime-error admin (Elasticsearch)

The application's controller (conventions: `ddd-endpoint-specialist`). Thin: request DTO in, service call, response
DTO out. The detail's occurrences carry the new fields; the list stays lean.

```php
/**
 * Runtime errors straight from the log index: groups, trend, one group's detail.
 */
#[Route('/admin/runtime-errors')]
#[Tag(group: 'Admin', name: 'RuntimeErrors', description: 'Runtime errors from the log index')]
class AdminRuntimeErrorsController extends AdminController
{
    /**
     * @throws InternalErrorException The index is not configured or the query failed
     */
    #[Get('/groups')]
    #[Summary('RuntimeErrors Groups List')]
    public function listGroups(
        RuntimeErrorGroupsGetRequestDto &$requestDto,
        RuntimeErrorsService $runtimeErrorsService
    ): RuntimeErrorGroupsGetResponseDto {
        $responseDto = new RuntimeErrorGroupsGetResponseDto();
        $responseDto->runtimeErrorGroups = $runtimeErrorsService->findErrorGroups(
            $requestDto->hours ?? RuntimeErrorAggregationQuery::DEFAULT_HOURS,
            $requestDto->levels !== null ? RuntimeErrorAggregationQuery::parseLevels($requestDto->levels) : RuntimeErrorAggregationQuery::DEFAULT_LEVELS,
            $requestDto->limit ?? RuntimeErrorAggregationQuery::DEFAULT_SIZE,
            $requestDto->groupingDimension ?? RuntimeErrorAggregationQuery::DEFAULT_DIMENSION,
            $requestDto->logstashEnvironment ?? RuntimeErrorAggregationQuery::DEFAULT_ENVIRONMENT,
            searchTerm: $requestDto->search,
            workspace: $requestDto->workspace,
        );
        return $responseDto;
    }

    /**
     * @throws InternalErrorException The index is not configured or the query failed
     */
    #[Get('/groups/detail')]
    #[Summary('RuntimeErrors Group Details')]
    public function getGroupDetail(
        RuntimeErrorGroupDetailGetRequestDto &$requestDto,
        RuntimeErrorsService $runtimeErrorsService
    ): RuntimeErrorGroupDetailGetResponseDto {
        $responseDto = new RuntimeErrorGroupDetailGetResponseDto();
        $responseDto->runtimeErrorGroupDetail = $runtimeErrorsService->findErrorGroupDetail(
            $requestDto->groupKey,
            $requestDto->hours ?? RuntimeErrorAggregationQuery::DEFAULT_HOURS,
            $requestDto->levels !== null ? RuntimeErrorAggregationQuery::parseLevels($requestDto->levels) : RuntimeErrorAggregationQuery::DEFAULT_LEVELS,
            sampleSize: $requestDto->sampleSize ?? RuntimeErrorDetailAggregationQuery::DEFAULT_SAMPLE_SIZE,
            groupingDimension: $requestDto->groupingDimension ?? RuntimeErrorAggregationQuery::DEFAULT_DIMENSION,
            logstashEnvironment: $requestDto->logstashEnvironment ?? RuntimeErrorAggregationQuery::DEFAULT_ENVIRONMENT,
            workspace: $requestDto->workspace,
        );
        return $responseDto;
    }
}

class RuntimeErrorGroupsGetRequestDto extends RequestDto
{
    /** @var string|null PRODUCTION or DEVELOPMENT */
    #[Parameter(in: Parameter::QUERY)]
    #[Choice(choices: RuntimeErrorAggregationQuery::ENVIRONMENTS)]
    public ?string $logstashEnvironment = null;

    /** @var string|null One dev workspace, e.g. mgn */
    #[Parameter(in: Parameter::QUERY)]
    public ?string $workspace = null;

    /** @var string|null COARSE (type + throw-site file), FINGERPRINT or GROUP_HASH */
    #[Parameter(in: Parameter::QUERY)]
    public ?string $groupingDimension = null;

    #[Parameter(in: Parameter::QUERY)]
    public ?int $hours = null;

    /** @var string|null Comma list, e.g. ERROR,CRITICAL; null = ERROR,CRITICAL */
    #[Parameter(in: Parameter::QUERY)]
    public ?string $levels = null;

    /** @var string|null Matched against route and error type */
    #[Parameter(in: Parameter::QUERY)]
    public ?string $search = null;

    #[Parameter(in: Parameter::QUERY)]
    public ?int $limit = null;
}

class RuntimeErrorGroupDetailGetRequestDto extends RuntimeErrorGroupsGetRequestDto
{
    /** @var string The group key of the list row (`displayFingerprint`); COARSE: `<error type>|<throw-site file>` */
    #[Parameter(in: Parameter::QUERY, required: true)]
    public string $groupKey;

    /** @var int|null Newest occurrences returned (1 … 50, default 5) */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $sampleSize = null;
}

class RuntimeErrorGroupsGetResponseDto extends RestResponseDto
{
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public RuntimeErrorGroups $runtimeErrorGroups;
}

class RuntimeErrorGroupDetailGetResponseDto extends RestResponseDto
{
    /** @var RuntimeErrorGroupDetail Last seen, trend, newest occurrences, per-route breakdown */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public RuntimeErrorGroupDetail $runtimeErrorGroupDetail;
}
```

The group key goes in the query, not the path: a COARSE key contains `|` and a file path with `/`.

## 2. Registry drawer — the newest events as occurrences

An `IssueEvent` keeps its raw line (`rawJson`); `RuntimeErrorOccurrence::buildFromHit()` reads it the same way it
reads an Elasticsearch hit (flat dotted or nested keys). So the registry drawer shows the same object, whatever the
backend. Add one endpoint next to `getGroup()`:

```php
/**
 * @throws NotFoundException The group is not registered in that environment
 */
#[Get('/groups/{groupHash}/events')]
#[Summary('Issue Group Events')]
public function getGroupEvents(
    IssueGroupEventsGetRequestDto &$requestDto,
    IssueGroupsService $issueGroupsService,
    IssuesRegistryJobService $issuesRegistryJobService
): IssueGroupEventsGetResponseDto {
    $issueGroup = $issueGroupsService->findByGroupHash($requestDto->groupHash, $requestDto->environment)
        ?? throw new NotFoundException('Issue group not found');
    $issueEvents = $issuesRegistryJobService->getQueryBackend($issueGroup->environment)->searchEvents(new IssueEventSearch(
        DateTime::fromTimestamp(time() - ($requestDto->hours ?? 24 * 7) * 3600),
        DateTime::fromTimestamp(time()),
        groupHash: $issueGroup->groupHash,
        limit: $requestDto->limit ?? 5,
    ));
    $fieldRedactor = new RuntimeErrorFieldRedactor();
    $responseDto = new IssueGroupEventsGetResponseDto();
    $responseDto->occurrences = new RuntimeErrorOccurrences();
    foreach ($issueEvents->getElements() as $issueEvent) {
        $runtimeErrorOccurrence = RuntimeErrorOccurrence::buildFromHit(
            ['_source' => json_decode($issueEvent->rawJson, true) ?: []],
            $fieldRedactor
        );
        $responseDto->occurrences->add($runtimeErrorOccurrence);   // add() takes a variable (by reference)
    }
    return $responseDto;
}

class IssueGroupEventsGetRequestDto extends IssueGroupGetRequestDto
{
    /** @var int|null Look-back window in hours (default 168) */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $hours = null;

    /** @var int|null Newest events returned (default 5) */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $limit = null;
}

class IssueGroupEventsGetResponseDto extends RestResponseDto
{
    /** @var RuntimeErrorOccurrences The newest events, newest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public RuntimeErrorOccurrences $occurrences;
}
```

The backend call may fail (timeout, backend down): let it surface as the endpoint's error, or catch it and answer an
empty set plus a message — the group itself comes from the database and still renders.

## 3. The occurrence — real JSON

Two lines Dineful wrote with 3.12.6 (`tests/Infrastructure/Logging/Fixtures/issues-event-shapes.json`), serialised
(`objectType` and frames shortened). A failed job on its last attempt:

```json
{
  "timestamp": "2026-10-30T00:56:26.685466+00:00",
  "message": "LogicException: Probe: push payload for order 881 has no recipient",
  "errorType": "LogicException",
  "level": "CRITICAL",
  "section": "MESSENGER",
  "groupHash": "ea67c95da678549e5fcf20fb27a5d887",
  "issueHash": "5acf7e785626851d026ed16024ac4f11",
  "entryPoint": "MessageHandler:process_partner_invoice",
  "handled": false,
  "throwSiteFileName": "ZzProbeHandler.php",
  "throwSiteLine": 23,
  "environment": "dev",
  "workspace": "mgn",
  "routeParameters": {"elements": []},
  "messenger": {
    "messageClass": "App\\Domain\\ZzProbe\\ZzProbeMessage",
    "handler": "App\\Domain\\ZzProbe\\ZzProbeHandler::__invoke",
    "messageKeys": {"elements": [{"key": "orderId", "value": "881"}, {"key": "reason", "value": "order ready"}]},
    "attempt": 4, "retryCount": 3, "final": true, "willRetry": false,
    "transports": ["process_partner_invoice"],
    "originWorkspace": "mgn", "originDispatchedAt": "2026-10-30T00:56:18Z", "ageSeconds": 8
  },
  "errorFrames": {"elements": [
    {
      "file": "src/Domain/ZzProbe/ZzProbeHandler.php", "line": 23,
      "function": "App\\Domain\\ZzProbe\\ZzProbeHandler->__invoke", "inApp": true,
      "codeContextStartLine": 20,
      "codeContextLines": [
        "        $device = ['pushToken' => null];",
        "        $payload = [];",
        "        $payload['to'] = $device['pushToken']['value'];",
        "        throw new \\LogicException('Probe: push payload for order ' . $message->orderId . ' has no recipient');",
        "    }",
        "}"
      ]
    },
    {
      "file": "vendor/symfony/messenger/Middleware/HandleMessageMiddleware.php", "line": 148,
      "function": "Symfony\\Component\\Messenger\\Middleware\\HandleMessageMiddleware->callHandler",
      "inApp": false, "codeContextLines": []
    }
  ]},
  "causes": {"elements": []}
}
```

An HTTP 500 that wraps a `JsonException` (fields as above left out):

```json
{
  "message": "RuntimeException: Could not import the menu",
  "section": "HTTP",
  "route": "App\\…\\PublicZzProbeController:import__GET:/common/zz-probe/locations/{locationId}/import",
  "httpMethod": "GET",
  "httpStatus": 500,
  "routeParameters": {"elements": [{"key": "locationId", "value": "12"}]},
  "messenger": null,
  "causes": {"elements": [{
    "errorType": "JsonException",
    "message": "Syntax error",
    "throwSite": "src/Presentation/Api/Public/Common/ZzProbe/Controller/PublicZzProbeController.php:50",
    "errorFrames": {"elements": [
      {"file": "src/…/PublicZzProbeController.php", "line": 50, "function": "json_decode", "inApp": true}
    ]}
  }]}
}
```

A console error has `command` and `exitCode`, `messenger` null. A line from before 3.12.6 has only `message`,
`stackTrace`, `route`, `errorType`, the throw site, `workspace` and `locationId`; every collection is empty, never
missing.

## 4. Drawer layout

Top to bottom; render a block only when its data is there:

| Block | Fields | Notes |
|---|---|---|
| Header | `errorType`, `message`, `level`, `section`, `handled` | `handled: false` = uncaught, show it as a badge |
| Where | `entryPoint`, `throwSiteFileName:throwSiteLine` | the entry point names route, `CLI:command` or `MessageHandler:transport` |
| Request | `httpMethod`, `route`, `routeParameters`, `httpStatus` | section `HTTP` |
| Console | `command`, `exitCode` | section `CLI` |
| Job | `messenger.handler`, `messageClass`, `messageKeys`, `attempt` / `retryCount`, `final`, `willRetry`, `transports`, origin (`originRoute` or `originCommand`, `originAccountId`, `originWorkspace`, `originDispatchedAt`, `ageSeconds`) | `final: true` = the job is dropped (or in the failure transport); `willRetry: true` = it comes back |
| Frames | `errorFrames` | in-app frames open, framework frames (`inApp: false`) folded into "N framework frames"; code context under each frame that has it |
| Caused by | `causes` (outermost first): `errorType`, `message`, `throwSite`, its 3 frames | often the real error: a `RuntimeException` hiding a `JsonException` or `PDOException` |
| Meta | `release`, `hostName`, `workspace`, `environment`, `accountId`, `locationId`, `eventId`, `timestamp`, `groupHash`, `issueHash` | `eventId` finds the line in the log backend |
| Raw | `stackTrace` | folded; the fallback for lines without `errorFrames` |

Several occurrences: tabs or a pager over `recentOccurrences` (newest first). Their frames usually agree; their
request, job and meta blocks differ.

## 5. Code context — rendering

`codeContextLines[0]` is line `codeContextStartLine`; the frame's own line is at index `line - codeContextStartLine`.
Only the first in-app frames carry code context (2 by default), at most 3 lines around. Lines are already redacted and
cut at 240 characters.

```tsx
function CodeContext({ frame }: { frame: IssueErrorFrame }) {
  if (!frame.codeContextLines?.length || frame.codeContextStartLine == null) return null;
  return (
    <pre className="code-context">
      {frame.codeContextLines.map((sourceLine, index) => {
        const lineNumber = frame.codeContextStartLine! + index;
        return (
          <div key={lineNumber} className={lineNumber === frame.line ? 'line current' : 'line'}>
            <span className="line-number">{lineNumber}</span>
            <code>{sourceLine}</code>
          </div>
        );
      })}
    </pre>
  );
}
```

The file is relative to the project (`src/…`, `vendor/…`, `../public/index.php`); a link into the repository is
`<repo URL>/blob/<release>/<file>#L<line>` when `release` is a commit or tag.

## 6. "Copy for agent"

One button copies what an agent needs to reproduce and fix the error: the newest occurrence as JSON plus the command
that fetches the same group again.

```ts
const occurrence = detail.recentOccurrences.elements[0];
const command = [
  'php bin/console app:runtime-errors:extract',
  `--logstashEnv=${logstashEnvironment}`,
  workspace ? `--workspace=${workspace}` : '',
  `--group='${groupKey}'`,
  `--groupingDimension=${groupingDimension}`,
  '--sampleSize=3',
].filter(Boolean).join(' ');
await navigator.clipboard.writeText(`${command}\n\n${JSON.stringify(occurrence, null, 2)}`);
```

The registry drawer copies `php bin/console app:issues:show <groupHash> --environment=<environment> --events=3
--format=json` instead.

## 7. Rules

- Render what the occurrence carries; never re-read the raw line in the frontend — the redaction happens in
  `buildFromHit()`.
- The groups list stays lean: frames, causes and job fields come only from the detail (or the registry events
  endpoint), never from the list.
- Every collection is initialised: test `elements.length`, not presence. `messenger` is the one nullable object.
- Hashes, `eventId`, release and host are display data; group by `groupHash` only (SKILL.md › Grouping).
