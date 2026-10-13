<?php

namespace App\Library\PlatformOwner\AccountDeletion;

use RuntimeException;

/**
 * A deletion/closure request that was refused before anything changed. The
 * message is safe to show the Platform Owner.
 */
final class AccountClosureRefused extends RuntimeException
{
}
