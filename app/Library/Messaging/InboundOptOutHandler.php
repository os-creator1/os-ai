<?php

namespace App\Library\Messaging;

use App\Library\Contacts\ContactPhone;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;

/**
 * An inbound STOP on a MANAGED Business number, handled centrally.
 *
 * Release-risk closure item 1. The managed inbound webhook used to store the
 * message in Conversations and stop there: the sender stayed `subscribe`, was
 * on no blacklist, and the next automation or reply texted them again. The
 * legacy inbound path always honoured STOP (DLRController::inboundDLR); this
 * is the same effect, for the managed path, with the same two authorities:
 *
 *   - `contacts.status = unsubscribe` — the canonical SMS subscription state
 *     every send gate reads (automation SMS, campaigns, `contact.subscribed`);
 *   - a `blacklists` row — the gate EloquentCampaignRepository::quickSend()
 *     checks before any individual send, exactly what the Blacklists
 *     repository writes for a manual opt-out (status flip + row).
 *
 * BUSINESS SCOPED. Contacts are matched inside the receiving Business only
 * (then by ContactPhone, the one normalization path), and the blacklist row
 * carries that Business's id. A STOP to Business A never touches Business B's
 * Contact or sends, even for the same phone number.
 *
 * IDEMPOTENT. A second STOP changes nothing: contacts already unsubscribed are
 * left alone and the blacklist row is only created when missing.
 *
 * RETENTION. The conversation and inbound message have already been written by
 * the caller; nothing is deleted here.
 *
 * NO RESUBSCRIBE. START/UNSTOP are deliberately not handled. Re-consent in this
 * product is explicit — the owner re-enabling a Contact (which refuses while a
 * blacklist row exists) or a keyword opt-in on a Contact group — so inventing
 * an automatic resubscribe here would silently override that contract.
 *
 * SMS ONLY. Email consent is a different record and is never read or written.
 */
final class InboundOptOutHandler
{
    /**
     * Whole-message carrier opt-out keywords. The WHOLE message must be one of
     * them: "please stop by at 3" and "cancel my appointment, thanks" are
     * ordinary replies, not withdrawals of consent.
     */
    private const KEYWORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];

    public static function isOptOutMessage(?string $body): bool
    {
        if ($body === null) {
            return false;
        }

        $normalized = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtoupper($body)));

        return in_array($normalized, self::KEYWORDS, true);
    }

    /**
     * @return bool whether the message was an opt-out command (and so applied)
     */
    public function handle(Business $business, string $fromNumber, ?string $body): bool
    {
        if (! self::isOptOutMessage($body)) {
            return false;
        }

        $region = ContactPhone::regionFor($business);
        $number = ContactPhone::canonical($fromNumber, $region);
        $forms = ContactPhone::candidates($fromNumber, $region);

        if ($number === '' || $forms === []) {
            return false;
        }

        DB::transaction(function () use ($business, $number, $forms): void {
            $contacts = Contacts::query()
                ->where('business_id', (int) $business->id)
                ->whereIn('phone', $forms)
                ->where('status', '!=', Contacts::STATUS_UNSUBSCRIBE)
                ->get(['id', 'group_id']);

            if ($contacts->isNotEmpty()) {
                Contacts::query()->whereIn('id', $contacts->pluck('id'))
                    ->update(['status' => Contacts::STATUS_UNSUBSCRIBE]);

                ContactGroups::query()->whereIn('id', $contacts->pluck('group_id')->filter()->unique())
                    ->get()
                    ->each(fn (ContactGroups $group) => $group->updateCache());
            }

            $listed = Blacklists::query()
                ->where('business_id', (int) $business->id)
                ->whereIn('number', $forms)
                ->exists();

            if (! $listed) {
                Blacklists::create([
                    'user_id' => $business->customer_id,
                    'business_id' => (int) $business->id,
                    'number' => $number,
                    'reason' => 'Optout by User',
                ]);
            }
        });

        return true;
    }
}
