<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Encryption\EncryptionScope;
use DDD\Domain\Common\Entities\Encryption\EncryptionScopePassword;
use DDD\Domain\Common\Entities\Encryption\EncryptionScopePasswords;
use DDD\Domain\Common\Repo\DB\Encryption\DBEncryptionScope;
use DDD\Domain\Common\Repo\DB\Encryption\DBEncryptionScopePassword;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Infrastructure\Libs\Encrypt;
use DDD\Infrastructure\Services\Service;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

class EncryptionScopesService extends EntitiesService
{
    /** @var string[] */
    public static $encryptionScopePasswordCache = [];

    public function getEncryptionScopeByIdOrName(int|string $idOrName): ?EncryptionScope
    {
        if ((int)$idOrName) {
            return $this->getEncryptionScopeById((int)$idOrName);
        }
        return $this->getEncryptionScopeByName($idOrName);
    }

    public function getEncryptionScopeById(int $id): ?EncryptionScope
    {
        $dbEncryptionScope = new DBEncryptionScope();
        $encryptionScope = $dbEncryptionScope->find($id);
        if (!$encryptionScope && $this->throwErrors) {
            throw new NotFoundException('EncryptionScope not found');
        }
        return $encryptionScope;
    }

    /**
     * Returns EncryptionScope by scope name
     * @param string $scopeName
     * @return EncryptionScope|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function getEncryptionScopeByName(string $scopeName): ?EncryptionScope
    {
        $dbEncryptionScope = new DBEncryptionScope();
        $queryBuilder = $dbEncryptionScope::createQueryBuilder();
        $queryBuilder
            ->andWhere($dbEncryptionScope::getBaseModelAlias() . '.scope = :scopeName')
            ->setParameter('scopeName', $scopeName);

        $encryptionScope = $dbEncryptionScope->find($queryBuilder);
        if (!$encryptionScope && $this->throwErrors) {
            throw new NotFoundException('EncryptionScope not found');
        }
        return $encryptionScope;
    }

    /**
     * Updates EncryptionScope
     * @param EncryptionScope $encryptedScope
     * @return EncryptionScope|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function updateEncryptionScope(
        EncryptionScope &$encryptedScope,
    ): ?EncryptionScope {
        $dbEncryptionScope = EncryptionScope::getRepoClassInstance();
        return $dbEncryptionScope->update($encryptedScope);
    }

    /**
     * Updates EncryptionScopePassword
     * @param EncryptionScopePassword $encryptionScopePassword
     * @return EncryptionScopePassword|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function updateEncryptionScopePassword(
        EncryptionScopePassword &$encryptionScopePassword,
    ): ?EncryptionScopePassword {
        $dbEncryptionScopePassword = new DBEncryptionScopePassword();
        return $dbEncryptionScopePassword->update($encryptionScopePassword);
    }

    /**
     * Retruns decrypted scope password (decrypted using given password) for given EncryptionScope name
     * @param string $password
     * @param string $scopeName
     * @return string|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws UnauthorizedException
     */
    public function getScopePassword(string $password, string $scopeName): ?string
    {
        if (array_key_exists($password . '_' . $scopeName, self::$encryptionScopePasswordCache)) {
            return self::$encryptionScopePasswordCache[$password . '_' . $scopeName];
        }
        $encryptionScope = self::getEncryptionScopeByName($scopeName);
        if (!$encryptionScope && $this->throwErrors) {
            throw new NotFoundException('EncryptionScope not found');
        } elseif (!$encryptionScope) {
            self::$encryptionScopePasswordCache[$password . '_' . $scopeName] = null;
            return null;
        }

        $passwordHash = Encrypt::hashWithSalt($password);
        $dbEncryptionScopePassword = new DBEncryptionScopePassword();
        $queryBuilder = $dbEncryptionScopePassword::createQueryBuilder();
        $alias = $dbEncryptionScopePassword::getBaseModelAlias();
        $queryBuilder
            ->andWhere("{$alias}.encryptionScopeId = :encryptionScopeId and {$alias}.passwordHash = :passwordHash ")
            ->setParameter('encryptionScopeId', $encryptionScope->id)
            ->setParameter('passwordHash', $passwordHash);
        $encryptionScopePassword = $dbEncryptionScopePassword->find($queryBuilder);
        if (!$encryptionScopePassword && $this->throwErrors) {
            throw new NotFoundException('No EncryptionScopePassword found for given password');
        } elseif (!$encryptionScopePassword) {
            self::$encryptionScopePasswordCache[$password . '_' . $scopeName] = null;
            return null;
        }
        $decryptedEncryptionScopePassword = Encrypt::decrypt(
            $encryptionScopePassword->encryptionScopePassword,
            $password
        );
        if (!$decryptedEncryptionScopePassword && $this->throwErrors) {
            throw new UnauthorizedException('Password is wrong, decryption failed');
        } elseif (!$encryptionScopePassword) {
            self::$encryptionScopePasswordCache[$password . '_' . $scopeName] = null;
            return null;
        }
        self::$encryptionScopePasswordCache[$password . '_' . $scopeName] = $decryptedEncryptionScopePassword;
        return $decryptedEncryptionScopePassword;
    }

    /**
     * Creates for each passed scopeName an EncryptionScopePassword using masterPassword to decrypt the EncryptionScope's
     * password and the encryptionPassword to encrypt the EncryptionScope again in the EncyptionScopePassword
     * @param array $scopeNames
     * @param string $masterPassword
     * @param string $encryptionPassword
     * @return EncryptionScopePasswords
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function createEncryptionScopePasswordsUsingMasterPassword(
        array $scopeNames,
        string $masterPassword,
        string $encryptionPassword
    ): EncryptionScopePasswords {
        $encryptionScopepasswords = new EncryptionScopePasswords();
        if (!count($scopeNames)) {
            $scopeNames = EncryptionScope::getScopes();
        }
        foreach ($scopeNames as $scopeName) {
            $encryptionScope = $this->getEncryptionScopeByName($scopeName);
            if (!$encryptionScope && $this->throwErrors) {
                throw new NotFoundException('EncryptionScope not found for name: ' . $scopeName);
            }
            if (!$encryptionScope) {
                continue;
            }
            $encryptionScope->decryptScopePassword($masterPassword);
            $encryptionScopePassword = new EncryptionScopePassword();
            $encryptionScopePassword->encryptionScopeId = $encryptionScope->id;
            $encryptionScopePassword->updateUsingPassword($encryptionPassword, $encryptionScope->scopePassword);
            $encryptionScopepasswords->add($encryptionScopePassword);
        }
        return $encryptionScopepasswords;
    }
}