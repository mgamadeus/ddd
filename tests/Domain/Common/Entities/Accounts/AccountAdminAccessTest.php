<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Accounts;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccount;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\AuthService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Admin access to all resources: the roles ADMIN and SUPERADMIN, for the authenticated account or the admin account
 * acting behind it — never another role that merely counts as an admin role.
 */
class AccountAdminAccessTest extends TestCase
{
    protected function setUp(): void
    {
        // an application sets it through LAZYLOAD_DEFAULT_REPO_TYPE; the unit suite has no environment
        if (!isset(LazyLoadRepo::$defaultRepoType)) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    protected function tearDown(): void
    {
        $noAccount = null;
        $authService = new AuthService();
        $authService->setAccount($noAccount);
        (new ReflectionProperty(AuthService::class, 'adminAccount'))->setValue(null, null);
    }

    protected function createAccountWithRoles(string ...$roleNames): Account
    {
        $account = new Account();
        $account->roles = new Roles();
        foreach ($roleNames as $roleName) {
            $role = new Role();
            $role->setName($roleName);
            if ($roleName === 'agencyAdmin') {
                $role->isAdminRole = true;
            }
            $account->roles->add($role);
        }
        return $account;
    }

    public function testOnlyAdminAndSuperadminHaveAccessToAllResources(): void
    {
        $this->assertTrue($this->createAccountWithRoles(Role::LOGIN, Role::ADMIN)->isAdminWithAccessToAllResources());
        $this->assertTrue($this->createAccountWithRoles(Role::SUPERADMIN)->isAdminWithAccessToAllResources());
        $this->assertFalse($this->createAccountWithRoles(Role::LOGIN)->isAdminWithAccessToAllResources());
        $agencyAdminAccount = $this->createAccountWithRoles(Role::LOGIN, 'agencyAdmin');
        $this->assertTrue($agencyAdminAccount->roles->isAdmin(), 'the role counts as an admin role');
        $this->assertFalse($agencyAdminAccount->isAdminWithAccessToAllResources());
    }

    public function testTheSessionIsAdminThroughTheAccountOrTheAdminAccountBehindIt(): void
    {
        $authService = new AuthService();
        $loginAccount = $this->createAccountWithRoles(Role::LOGIN);
        $adminAccount = $this->createAccountWithRoles(Role::LOGIN, Role::ADMIN);

        $authService->setAccount($loginAccount);
        $this->assertNull($authService->getAdminAccountOrNull());
        $this->assertFalse($authService->isCurrentSessionAdmin());

        $authService->setAdminAccount($adminAccount);
        $this->assertSame($adminAccount, $authService->getAdminAccountOrNull());
        $this->assertTrue($authService->isCurrentSessionAdmin());

        (new ReflectionProperty(AuthService::class, 'adminAccount'))->setValue(null, null);
        $authService->setAccount($adminAccount);
        $this->assertTrue($authService->isCurrentSessionAdmin());
    }

    public function testANameOfTheAdminRolesMakesTheRoleAnAdminRoleAndAnotherNameKeepsTheFlag(): void
    {
        $adminRole = new Role();
        $adminRole->setName(Role::ADMIN);
        $this->assertTrue($adminRole->isAdminRole);

        $loginRole = new Role();
        $loginRole->setName(Role::LOGIN);
        $this->assertFalse($loginRole->isAdminRole);

        $storedAdminRole = new Role();
        $storedAdminRole->isAdminRole = true;
        $storedAdminRole->setName('agencyAdmin');
        $this->assertTrue($storedAdminRole->isAdminRole);
    }

    public function testTheAccountRepositoryNamesItsDoctrineModel(): void
    {
        $this->assertSame(DBAccountModel::class, DBAccount::BASE_ORM_MODEL);
        $this->assertSame(Account::class, DBAccount::BASE_ENTITY_CLASS);
    }
}
