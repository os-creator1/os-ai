<?php

namespace App\Library\PlatformOwner\AccountDeletion;

use RuntimeException;

/**
 * The payment provider has confirmed a subscription is cancelled, but the
 * authoritative finalizer could not record that locally. The closure stays
 * pending; the retry re-reads the provider (no second cancel) and reconciles.
 */
final class AccountClosureReconciliationPending extends RuntimeException
{
}
