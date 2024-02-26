<?php

namespace DDD\Domain\Common\Interfaces;


use DDD\Domain\Common\Entities\Accounts\Account;

interface AccountDependentEntityInterface
{
    public function getAccount(): ?Account;
}