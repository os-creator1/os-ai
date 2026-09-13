<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * Owner product decision — the customer should never need to understand
 * or configure a "messaging channel". This is the ONE customer-facing
 * Settings surface every tier (Core, Growth, and an Agency Business using
 * managed transport) sees for text messaging: a plain phone
 * number/status/capability summary, never a provider name, credential, or
 * channel-selection concept.
 *
 * Read-only, and deliberately thin: every fact below is read straight off
 * BusinessMessagingIdentityResolver's already-hardened Slice 3 read paths
 * (resolveForBusiness()/resolvePrimaryNumber()), which fail closed to null
 * or a caught exception rather than a guess — this controller never
 * queries business_messaging_identities/business_messaging_numbers
 * directly, and never writes either table. The Agency-only Advanced (BYO)
 * surface (MessagingChannelsController, Settings -> Advanced -> Messaging
 * provider) is a completely separate controller/route/permission set;
 * this one does not gate, replace, or depend on it.
 *
 * "Ready" / "Setup needed" / "Issue" is DERIVED, not read off one stored
 * column — Slice 3's data model has no such status of its own (only a
 * per-identity lifecycle and a per-number lifecycle, see
 * BusinessMessagingIdentityStatus/BusinessMessagingNumberStatus). Media
 * (MMS) availability is likewise derived, and is honestly identical to
 * "texting works": ManagedDispatchDelegate::SUPPORTED_MESSAGE_TYPES
 * unconditionally includes 'mms' for any Business with a working managed
 * identity — there is no finer-grained, per-number media capability signal
 * in today's data model to show instead.
 */
class TextMessagingController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly BusinessMessagingIdentityResolver $identities,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('view_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        $identity = $this->identities->resolveForBusiness($business);
        $phoneNumber = null;
        $status = 'setup_needed';

        if ($identity !== null && $identity->isActive()) {
            try {
                $number = $this->identities->resolvePrimaryNumber($identity);
                $status = $number->isActive() ? 'ready' : 'issue';
                $phoneNumber = $number->phone_number;
            } catch (MessagingIdentityConflictException) {
                // No single active primary number — something exists but
                // is not currently usable. Never guess a number to show.
                $status = 'issue';
            }
        } elseif ($identity !== null) {
            // A Pending/Suspended/Archived identity exists — no longer
            // "nothing set up yet", but not usable either.
            $status = 'issue';
        }

        return view('customer.settings.text-messaging.show', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'status' => $status,
            'phoneNumber' => $phoneNumber,
            'textingAvailable' => $status === 'ready',
            'mediaAvailable' => $status === 'ready',
        ]);
    }
}
