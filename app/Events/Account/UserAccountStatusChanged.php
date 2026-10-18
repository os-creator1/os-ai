<?php

namespace App\Events\Account;

use Illuminate\Foundation\Events\Dispatchable;

/** A user account was enabled or disabled. Raised only by EloquentCustomerRepository::batchEnable / batchDisable, the single write authority for users.status. */
final class UserAccountStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly int $userId, public readonly bool $enabled)
    {
    }
}
