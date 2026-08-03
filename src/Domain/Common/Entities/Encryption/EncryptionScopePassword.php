<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Encryption;

use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Repo\DB\Encryption\DBEncryptionScopePassword;
use DDD\Domain\Common\Services\EncryptionScopesService;
use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Infrastructure\Libs\Encrypt;
use Symfony\Component\Validator\Constraints\Length;

/**
 * @method EncryptionScopes getParent()
 * @property EncryptionScopes $parent
 * @method static EncryptionScopesService getService()
 * @method static DBEncryptionScopePassword getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBEncryptionScopePassword::class)]
#[QueryOptions]
#[RolesRequiredForUpdate(Role::ADMIN)]
class EncryptionScopePassword extends Entity
{
    use QueryOptionsTrait, ChangeHistoryTrait;

    public const string COOKIE_NAME = 'encryptionPassword';

    /** @var int The id of the EncryptionScope */
    public int $encryptionScopeId;

    /** @var EncryptionScope The associated EncryptedScope */
    #[LazyLoad(LazyLoadRepo::DB, addAsParent: true)]
    public EncryptionScope $encryptionScope;

    /** @var string The hash of the password for easy retrieval */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX)]
    public string $passwordHash;

    /** @var string The Password of the Encrypted Scope, encrypted with the password */
    public string $encryptionScopePassword;

    /**
     * @var string|null Who this holder is, in plain words — "Marius' browser", "voice webhook (environment)".
     *
     * A holder is otherwise unidentifiable: a row carries a salted hash and a ciphertext, and nothing about
     * either says whose password it is. Without a name, the only safe operation on a holder list is adding to
     * it — nobody can tell which row may be revoked. Nullable because rows created before this field exist.
     */
    #[Length(max: 255)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $holderName = null;

    /**
     * Whether this holder's password is the given one. Compares the salted hashes, never the passwords.
     * @param string $password
     * @return bool
     */
    public function matchesPassword(string $password): bool
    {
        if (!isset($this->passwordHash)) {
            return false;
        }
        return hash_equals($this->passwordHash, Encrypt::hashWithSalt($password));
    }

    /**
     * Whether this holder IS the headless one: the password in `ENCRYPTION_SCOPE_PASSWORD_<SCOPE>` that a webhook
     * or worker unlocks the scope with ({@see Encrypt::getEnvironmentPasswordForScope()}).
     *
     * Takes the scope rather than reading `$this->encryptionScope`, which would lazy-load it from the database on
     * every row of a list.
     *
     * @param EncryptionScope $encryptionScope
     * @return bool
     */
    public function isEnvironmentPasswordForScope(EncryptionScope $encryptionScope): bool
    {
        $environmentPassword = Encrypt::getEnvironmentPasswordForScope($encryptionScope->scope);
        if ($environmentPassword === null) {
            return false;
        }
        return $this->matchesPassword($environmentPassword);
    }

    /**
     * Updates the EncryptionScopePassword, saves passwordHash and encrypts its encryptionScopePassword using $encryptionPassword
     * @param string $encryptionPassword
     * @param string $scopePassword
     * @return $this|null
     */
    public function updateUsingPassword(string $encryptionPassword, string $scopePassword): ?static
    {
        $this->passwordHash = Encrypt::hashWithSalt($encryptionPassword);
        $this->encryptionScopePassword = Encrypt::encrypt($scopePassword, $encryptionPassword);
        return parent::update();
    }

    /**
     * Sets encryptionScopePassword to decrypted version using $decryptionPassword
     * @param string $decryptionPassword
     * @return void
     * @throws UnauthorizedException
     */
    public function decryptScopePassword(string $decryptionPassword): void
    {
        $encryptionScopePassword = Encrypt::decrypt($this->encryptionScopePassword, $decryptionPassword);
        if (!$encryptionScopePassword) {
            throw new UnauthorizedException('Invalid decryption password');
        }
        $this->encryptionScopePassword = $encryptionScopePassword;
    }
}
