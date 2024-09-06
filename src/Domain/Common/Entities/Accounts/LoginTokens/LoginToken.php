<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Accounts\LoginTokens;

use DDD\Domain\Base\Entities\Attributes\NoRecursiveUpdate;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Repo\DB\Accounts\LoginTokens\DBLoginToken;
use DDD\Domain\Common\Services\LoginTokensService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A LoginToken can be used for one or multiple Logins and can be configured to be time or usage limited or both
 * @method static DBLoginToken getRepoClassInstance(string $repoType = null)
 * @method static LoginTokensService getService()
 */
#[NoRecursiveUpdate]
#[LazyLoadRepo(LazyLoadRepo::DB, DBLoginToken::class)]
class LoginToken extends Entity
{
    use ChangeHistoryTrait;

    /** @var Account The Account which to login */
    #[LazyLoad]
    public Account $account;

    /** @var int The Account's id which to login */
    public ?int $accountId;

    /** @var string The login token string */
    public string $token;

    /** @var int If set, the token can be used for usageLimit times, the counter will be decremented on each usage until the token becomes invalid */
    public ?int $usageLimit;

    /** @var DateTime|null If set, the token is time limited and will be unsuable after expiration */
    public ?DateTime $validUntil;
}