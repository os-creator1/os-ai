<?php

namespace App\Library\BusinessEmail;

use App\Models\Business;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessLocation;

/**
 * "Send from this Business's default email identity."
 *
 * V1 permits exactly ONE connected email identity per Business, so the
 * default sender IS that account and nothing is stored per workflow or per
 * Location. The Location argument is accepted so the call shape will not
 * change if per-Location senders are ever authorized; today the account is
 * Business-wide and the Location is used only to refuse a Location that is
 * not this Business's own.
 *
 * Returns null (never throws, never guesses) when there is no ACTIVE account
 * with a held credential: pending, revoked and disconnected accounts do not
 * resolve.
 */
final class BusinessEmailSenderResolver
{
    public function defaultFor(Business $business, ?BusinessLocation $location = null): ?BusinessEmailAccount
    {
        if ($location !== null && (int) $location->business_id !== (int) $business->id) {
            return null;
        }

        $account = BusinessEmailAccount::query()
            ->where('business_id', $business->id)
            ->first();

        if ($account === null || ! $account->isActive() || (int) $account->business_id !== (int) $business->id) {
            return null;
        }

        return $account;
    }
}
