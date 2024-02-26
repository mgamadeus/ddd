<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Accounts;

use DDD\Domain\Base\Entities\Attributes\NoRecursiveUpdate;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\Lazyload\LazyLoad;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Domain\Common\Interfaces\AccountDependentEntityInterface;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccount;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints\Email;

/**
 * @method static AccountsService getService()
 * @method static DBAccount getRepoClassInstance(string $repoType = null)
 */
#[QueryOptions]
#[NoRecursiveUpdate]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAccount::class)]
class Account extends Entity implements UserInterface, AccountDependentEntityInterface
{
    use QueryOptionsTrait;

    /** @var string Agency account that can only view reports without rights to operate or change the location */
    public const ROLE_ASSOCIATIONS = [
        Role::LOGIN => 'ROLE_USER',
        Role::ADMIN => 'ROLE_ADMIN',
        Role::SUPERADMIN => 'ROLE_SUPER_ADMIN'
    ];

    /** @var string|null Account's password */
    #[HideProperty]
    public ?string $password;

    /** @var string|null Account's email */
    #[Email]
    public ?string $email;

    /** @var Roles|null Account's roles, determines priviledges of this account */
    #[LazyLoad]
    public ?Roles $roles;

    public function getUserIdentifier(): string
    {
        return (string)$this->id;
    }

    public function eraseCredentials()
    {
    }

    /**
     * Return symfony conform security roles
     * @return array|string[]
     */
    public function getRoles(): array
    {
        $roles = [];
        foreach ($this->roles->elements as $role) {
            if (isset(self::ROLE_ASSOCIATIONS[$role->name])) {
                $roles[] = self::ROLE_ASSOCIATIONS[$role->name];
            }
        }
        return $roles;
    }


    public function getAccount(): ?Account
    {
        return $this;
    }
}
