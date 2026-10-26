<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use PHPUnit\Framework\TestCase;

/**
 * find() with an empty id loads the AUTHENTICATED account. With nobody authenticated it must answer null (or
 * NotFoundException with throwErrors) — up to 2.70.4 it dereferenced the missing account and crashed.
 */
class AccountsServiceFindAuthFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
    }

    public function testAnEmptyIdWithoutAnAuthenticatedAccountIsNull(): void
    {
        $accountsService = new AccountsService();

        $this->assertNull($accountsService->find(null));
        $this->assertNull($accountsService->find(0));
    }

    public function testWithThrowErrorsItIsNotFound(): void
    {
        $accountsService = new AccountsService();
        $accountsService->throwErrors = true;

        $this->expectException(NotFoundException::class);
        $accountsService->find(null);
    }
}
