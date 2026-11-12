<?php

namespace App\Library\Conversations;

use App\Library\BusinessEmail\BusinessEmailAccountManager;
use App\Library\BusinessEmail\BusinessEmailContactResolver;
use App\Library\BusinessEmail\BusinessEmailLocationScope;
use App\Enums\Business\BusinessLocationLifecycleState;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessEmailMessage;
use App\Models\Contacts;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Which channels the reply composer offers for the open conversation, and the
 * existing endpoint each one posts to. It decides what to SHOW only: sending
 * stays with the existing SMS reply and the existing Settings → Email
 * send/reply endpoints, each of which re-checks tenancy, permission, Location
 * reach, plan entitlement and the connected mailbox on its own. A channel that
 * is unavailable is never silently replaced by the other one.
 */
class ConversationComposerOptions
{
    public function __construct(
        private readonly BusinessEmailAccountManager $accounts,
        private readonly BusinessEmailContactResolver $addresses,
        private readonly BusinessEmailLocationScope $locations,
    ) {
    }

    /**
     * @param  bool  $sms  an SMS conversation exists for this person
     * @param  BusinessEmailMessage|null  $replyTo  the thread's message an email reply answers
     * @return array<string, mixed>
     */
    public function for(Business $business, string $workspaceUid, string $businessUid, int $userId, bool $sms, ?Contacts $contact, ?BusinessEmailMessage $replyTo): array
    {
        $connected = $this->accounts->findForBusiness($business)?->isActive() === true;
        $mayEmail = Gate::allows('chat_box') && ($replyTo?->contact_id === null && $contact === null || Gate::allows('view_contact'));

        $recipient = $replyTo !== null
            ? ($replyTo->direction === 'inbound' ? $replyTo->from_email : $replyTo->to_email)
            : ($contact !== null ? $this->addresses->singleAddressFor($contact) : null);

        $canReply = $replyTo !== null && $replyTo->status->value !== 'failed';
        // A new email to a Contact needs the Contact; a reply needs only the thread.
        $canCompose = $canReply || ($contact !== null && $recipient !== null);
        $email = $mayEmail && $connected && $canCompose;

        // The sender needs a Location: a reply keeps its thread's, a new email uses the Contact's own, else the Business's single active
        // one. When none of those is provable and there is a real choice, the actor must pick one of THEIR accessible active Locations
        // (the same resolver the send endpoints validate against); with nothing they may use, Email is not offered at all.
        $choices = collect();

        if ($email && ! $this->locationIsProvable($business, $canReply ? $replyTo->location_id : $contact?->location_id)) {
            $active = $this->locations->activeLocations($business, $this->locations->accessibleLocationIds($userId, $business));

            if ($active['activeTotal'] > 1) {
                $choices = $active['selectable'];
                $email = $choices->isNotEmpty();
            }
        }

        $subject = $replyTo !== null
            ? (Str::startsWith(strtolower((string) $replyTo->subject), 're:') ? (string) $replyTo->subject : 'Re: ' . $replyTo->subject)
            : '';

        return [
            'sms' => $sms,
            'email' => $email,
            'default' => $sms ? 'sms' : 'email',
            'email_connected' => $connected,
            // Guidance is only worth showing where there is somebody to email.
            'email_guidance' => ! $connected && $mayEmail && ($recipient !== null || $replyTo !== null),
            'settings_url' => route('customer.workspaces.businesses.email.show', [$workspaceUid, $businessUid]),
            'recipient' => $recipient,
            'subject' => $subject,
            'url' => $email
                ? ($canReply
                    ? route('customer.workspaces.businesses.email.reply', [$workspaceUid, $businessUid, $replyTo->uid])
                    : route('customer.workspaces.businesses.email.send', [$workspaceUid, $businessUid]))
                : null,
            'contact_uid' => $email && ! $canReply ? $contact->uid : null,
            'locations' => $choices->map(fn ($l) => ['uid' => $l->uid, 'name' => $l->name])->values()->all(),
        ];
    }

    /** Is this a valid ACTIVE Location of the Business, so the sender needs no explicit choice? */
    private function locationIsProvable(Business $business, ?int $locationId): bool
    {
        if ($locationId !== null && BusinessLocation::query()
            ->where('id', $locationId)
            ->where('business_id', $business->id)
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
            ->exists()) {
            return true;
        }

        // The Business's single active Location is the sender's own fallback.
        return Contacts::singleActiveLocationIdFor((int) $business->id) !== null;
    }
}
