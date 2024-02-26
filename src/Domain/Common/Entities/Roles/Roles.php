<?php

namespace DDD\Domain\Common\Entities\Roles;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Domain\Common\Repo\DB\Roles\DBRoles;
use DDD\Domain\Common\Services\RolesService;

/**
 * @property Role[] $elements;
 * @method Role getByUniqueKey(string $uniqueKey)
 * @method Role[] getElements()
 * @method Role first()
 * @method static RolesService getService()
 * @method static DBRoles getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBRoles::class)]
class Roles extends EntitySet
{
    /** @var bool|null Whether the client is admin or not */
    public ?bool $isAdmin;

    public function isAdmin(): bool
    {
        if (isset($this->isAdmin)) {
            return $this->isAdmin;
        }
        foreach ($this->getElements() as $role) {
            if ($role->isAdminRole) {
                $this->isAdmin = true;
                return true;
            }
        }
        $this->isAdmin = false;
        return $this->isAdmin;
    }

    /**
     * verifies if all roles are present or not
     * @param string ...$roles
     * @return bool
     */
    public function hasRoles(string ...$roles)
    {
        // superadmins have all roles
        if ($this->getByUniqueKey(Role::SUPERADMIN)) {
            return true;
        }

        foreach ($roles as $role) {
            if (!$this->getByUniqueKey($role)) {
                return false;
            }
        }
        return true;
    }
}