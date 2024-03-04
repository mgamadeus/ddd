<?php

declare(strict_types=1);

namespace DDD\Symfony\Security\AccountProviders;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class AccountProvider implements UserProviderInterface
{
    protected AccountsService $accountsService;

    public function __construct(AccountsService $accountsService)
    {
        $this->accountsService = $accountsService;
    }

    /**
     * Symfony calls this method if you use features like switch_user
     * or remember_me. If you're not using these features, you do not
     * need to implement this method.
     *
     * @throws UserNotFoundException if the user is not found
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // api user basic auth comes json encoded from TokenAuthenticator
        // in case we have a valid json, we assume we have an api basic auth user
        $decodedIdentifier = json_decode($identifier);
        $account = null;
        // ApiAccount context
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $account = $this->accountsService->find($identifier);
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        if (!$account) {
            throw new UserNotFoundException('Account with id ' . $identifier . ' not found');
        }
        AuthService::instance()->setAccount($account);
        return $account;
    }

    /**
     * Refreshes the user after being reloaded from the session.
     *
     * When a user is logged in, at the beginning of each request, the
     * User object is loaded from the session and then this method is
     * called. Your job is to make sure the user's data is still fresh by,
     * for example, re-querying for fresh User data.
     *
     * If your firewall is "stateless: true" (for a pure API), this
     * method is not called.
     *
     * @return UserInterface
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof Account) {
            throw new UnsupportedUserException(sprintf('Invalid user class "%s".', get_class($user)));
        }
        return $this->loadUserByIdentifier((string)$user->id);
    }

    /**
     * Tells Symfony to use this provider for this User class.
     */
    public function supportsClass(string $class)
    {
        return Account::class === $class || is_subclass_of($class, Account::class);
    }
}