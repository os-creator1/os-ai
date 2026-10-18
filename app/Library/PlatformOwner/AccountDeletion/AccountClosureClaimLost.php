<?php

namespace App\Library\PlatformOwner\AccountDeletion;

use RuntimeException;

/**
 * The run no longer holds the closure's lease (it stalled past the lease and
 * another worker took over). It must stop at once and write nothing further.
 */
final class AccountClosureClaimLost extends RuntimeException
{
}
