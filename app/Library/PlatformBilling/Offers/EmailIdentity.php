<?php

namespace App\Library\PlatformBilling\Offers;

/**
 * Two notions of "the same email", deliberately kept apart.
 *
 *   normalize()  case- and whitespace-insensitive equality. This is what an
 *                email-BOUND offer is matched on: strict, so an offer for
 *                jane@x.com is not redeemable by jane+other@x.com.
 *
 *   key()        the "same mailbox" key used ONLY by the free-trial ledger.
 *                It also folds plus-addressing (jane+a@x.com) and Gmail's
 *                ignored dots, so opening a second account on an alias of the
 *                same inbox does not earn a second trial.
 */
final class EmailIdentity
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function key(string $email): string
    {
        $normalized = self::normalize($email);

        if (! str_contains($normalized, '@')) {
            return $normalized;
        }

        [$local, $domain] = explode('@', $normalized, 2);

        if ($domain === 'googlemail.com') {
            $domain = 'gmail.com';
        }

        $local = explode('+', $local, 2)[0];

        if ($domain === 'gmail.com') {
            $local = str_replace('.', '', $local);
        }

        return $local . '@' . $domain;
    }
}
