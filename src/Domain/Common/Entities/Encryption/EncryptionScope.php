<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Encryption;

use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Repo\DB\Encryption\DBEncryptionScope;
use DDD\Domain\Common\Services\EncryptionScopesService;
use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Infrastructure\Libs\Encrypt;
use DDD\Infrastructure\Validation\Constraints\Choice;
use ReflectionClassConstant;
use ReflectionException;

/**
 * @method EncryptionScopes getParent()
 * @property EncryptionScopes $parent
 * @method static EncryptionScopesService getService()
 * @method static DBEncryptionScope getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBEncryptionScope::class)]
#[QueryOptions]
#[RolesRequiredForUpdate(Role::ADMIN)]
class EncryptionScope extends Entity
{
    use QueryOptionsTrait, ChangeHistoryTrait;

    /** @var string Access to Google Workspace Service Account data */
    public const SCOPE_INTERNAL_GOOGLE_WORKSPACE_SERVICE_ACCOUNT = 'INTERNAL.GOOGLE_WORKSPACE.SERVICE_ACCOUNT';

    /** @var string Access to Contractor base data */
    public const SCOPE_INTERNAL_CONTRACTORS_DATA = 'INTERNAL.CONTRACTORS.DATA';

    /** @var string Access to Contractor invoices */
    public const SCOPE_INTERNAL_CONTRACTORS_INVOICES = 'INTERNAL.CONTRACTORS.INVOICES';

    /** @var string Access to Contractor contracts */
    public const SCOPE_INTERNAL_CONTRACTORS_CONTRACTS = 'INTERNAL.CONTRACTORS.CONTRACTS';

    /** @var string Access to Contractor remuneration */
    public const SCOPE_INTERNAL_CONTRACTORS_REMUNERATION = 'INTERNAL.CONTRACTORS.REMUNERATION';

    /** @var string The Scope's name */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    #[Choice(callback: [self::class, 'getScopes'])]
    public string $scope;

    /** @var string The description of the EncryptionScope */
    public string $description;

    /** @var string The main encryption password, encrypted with the master password */
    public string $scopePassword;


    public static function getScopes()
    {
        $reflectionClass = static::getReflectionClass();
        return array_values($reflectionClass->getConstants(ReflectionClassConstant::IS_PUBLIC));
    }

    /**
     * Updates the EncryptionScope and encrypts its password using $encryptionPassword
     * @param string $encryptionPassword
     * @return $this|null
     * @throws ReflectionException
     */
    public function updateUsingPassword(string $encryptionPassword): ?static
    {
        $this->scopePassword = Encrypt::encrypt($this->scopePassword, $encryptionPassword);
        $this->description = self::getReflectionClass()->getConstantDescriptionForConstantValue($this->scope);
        return parent::update();
    }

    /**
     * Sets scopePassword to decrypted version using $decryptionPassword
     * @param string $decryptionPassword
     * @return void
     * @throws UnauthorizedException
     */
    public function decryptScopePassword(string $decryptionPassword): void
    {
        $decryptionPassword = Encrypt::decrypt($this->scopePassword, $decryptionPassword);
        if (!$decryptionPassword) {
            throw new UnauthorizedException('Invalid decryption password');
        }
        $this->scopePassword = $decryptionPassword;
    }
}
