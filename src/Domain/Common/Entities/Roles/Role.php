<?php

namespace DDD\Domain\Common\Entities\Roles;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Repo\DB\Roles\DBRole;
use DDD\Domain\Common\Repo\DB\Roles\DBRoleModel;
use DDD\Domain\Common\Services\RolesService;

/**
 * @method Roles getParent()
 * @property Roles $parent
 * @method static RolesService getService()
 * @method static DBRoleModel getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBRole::class)]
class Role extends Entity
{
    /** @var string Regular login permitted */
    public const LOGIN = 'login';

    /** @var string Default admin role */
    public const ADMIN = 'admin';

    /** @var string Superadmin role, includes all possible roles */
    public const SUPERADMIN = 'superadmin';

    public const ADMIN_ROLES = [self::ADMIN => true, self::SUPERADMIN => true];

    /** @var string|null The name of the role */
    public ?string $name;

    /** @var string|null The description of the role */
    public ?string $description;

    /** @var bool Whether the role is an admin role or not */
    public bool $isAdminRole = false;

    public function uniqueKey(): string
    {
        return $this->name;
    }

    public function setName(string $name)
    {
        $this->name = $name;
        $this->isAdminRole = isset($this->adminRoles[$name]);
    }
}