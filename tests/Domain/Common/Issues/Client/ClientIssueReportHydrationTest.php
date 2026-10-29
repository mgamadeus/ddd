<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Issues\Client;

use DDD\Domain\Common\Entities\Issues\Client\ClientIssueBreadcrumb;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueEvent;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrame;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueReport;
use PHPUnit\Framework\TestCase;

/** The wire contract hydrates from a client's JSON body the way an application's request DTO does it */
class ClientIssueReportHydrationTest extends TestCase
{
    public const string REPORT_JSON = '{"client":{"runtime":"browser","release":"web-1.2","tabId":"t1","viewportWidth":390},'
        . '"events":[{"occurredAt":"2026-10-29T10:00:00Z","kind":"error","level":"error","name":"TypeError",'
        . '"message":"e is not a function","frames":[{"file":"/_next/static/chunks/app/page-3f2a9c1b8e7d6f50.js","line":1,'
        . '"column":200,"function":"onClick","inApp":true}],"breadcrumbs":[{"at":"2026-10-29T09:59:00Z","category":"navigation",'
        . '"message":"nav","from":"/a","to":"/b"}],"tags":[{"key":"feature","value":"menu"}],"count":2}]}';

    public function testAReportHydratesFromTheWireJson(): void
    {
        $object = json_decode(self::REPORT_JSON);
        $clientIssueReport = new ClientIssueReport();
        $clientIssueReport->setPropertiesFromObject($object);

        $this->assertSame('t1', $clientIssueReport->client->tabId);
        $this->assertSame(390, $clientIssueReport->client->viewportWidth);
        $this->assertSame(1, $clientIssueReport->events->count());
        $clientIssueEvent = $clientIssueReport->events->first();
        $this->assertInstanceOf(ClientIssueEvent::class, $clientIssueEvent);
        $this->assertSame(2, $clientIssueEvent->count);
        $this->assertInstanceOf(ClientIssueFrame::class, $clientIssueEvent->frames->first());
        $this->assertSame(200, $clientIssueEvent->frames->first()->column);
        $this->assertTrue($clientIssueEvent->frames->first()->inApp);
        $this->assertInstanceOf(ClientIssueBreadcrumb::class, $clientIssueEvent->breadcrumbs->first());
        $this->assertSame('/b', $clientIssueEvent->breadcrumbs->first()->to);
        $this->assertSame('menu', $clientIssueEvent->tags->first()->value);
    }
}
