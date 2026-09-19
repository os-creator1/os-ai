<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoReviewRequestChannel;
use App\Enums\Seo\SeoReviewRequestStatus;
use App\Exceptions\Seo\SeoReviewException;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\SeoReviewRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Contract 18 §8.6 / §15.F — the review-request LEDGER: "we asked this
 * person", and how it turned out.
 *
 * SEO SENDS NOTHING. This class records a fact the user reports. It has no
 * dependency on Messaging, Conversations, Automations, mail, notifications,
 * queues or any HTTP client, adds no trigger/node/merge field, and infers
 * nothing from message traffic. Communication happens in the existing
 * Conversations/Automations product, reached by read-only deep links.
 *
 * NO REVIEW GATING, NO INCENTIVE, NO QUOTA. Nothing here accepts, stores or
 * branches on a rating, sentiment, satisfaction, reward, target or goal.
 * Eligibility is only the two objective, contracted rules below.
 *
 * THE RULES (one transaction, one Business row lock — so two simultaneous
 * requests for the same person serialize and the second sees the first):
 *  1. Business access is re-derived under the lock; Location access goes
 *     through LocationAccessGuard and the Location must be operational.
 *  2. A Contact must belong to THIS Business (re-read from persistence,
 *     never trusted from the caller), the actor must hold `view_contact` —
 *     the existing Contacts authority, no second PII mechanism — and the
 *     Contact's own location_id must EQUAL the request's Location.
 *  3. COOLDOWN: at most one non-declined request per (Contact, Location)
 *     inside seo.reviews.request_cooldown_days (default 90). A declined
 *     request does not count. A request with no Contact (e.g. in person)
 *     has nobody to cool down.
 */
final class SeoReviewRequestManager
{
    public function __construct(
        private readonly SeoReviewWriteGate $gate,
        private readonly SeoConfig $config,
    ) {
    }

    /**
     * Records that a Contact (or nobody in particular) was asked for a review.
     *
     * @throws SeoReviewException
     */
    public function record(
        User $actor,
        Business $business,
        string $locationUid,
        string $channel,
        ?string $contactUid = null,
        ?string $opportunityUid = null,
    ): SeoReviewRequest {
        $channelCase = SeoReviewRequestChannel::tryFrom($channel);

        return DB::transaction(function () use ($actor, $business, $locationUid, $channelCase, $contactUid, $opportunityUid) {
            $locked = $this->gate->lockAuthorizedBusiness((int) $actor->id, $business);
            $location = $this->gate->activeLocationByUid((int) $actor->id, $locked, $locationUid);

            if ($channelCase === null) {
                throw SeoReviewException::invalidChannel();
            }

            $contact = $contactUid === null ? null : $this->authorizedContact($actor, $locked, $location->id, $contactUid);
            $opportunity = $opportunityUid === null ? null : $this->matchingOpportunity($locked, $location->id, $contact, $opportunityUid);

            if ($contact !== null) {
                $this->assertOutsideCooldown((int) $locked->id, (int) $location->id, (int) $contact->id);
            }

            $now = now();

            $request = new SeoReviewRequest([
                'business_id' => $locked->id,
                'business_location_id' => $location->id,
                'contact_id' => $contact?->id,
                'crm_opportunity_id' => $opportunity?->id,
                'channel' => $channelCase->value,
                'status' => SeoReviewRequestStatus::Requested->value,
                'requested_at' => $now,
                'created_by_user_id' => (int) $actor->id,
            ]);
            $request->save();

            return $request;
        });
    }

    /**
     * Records the user's own report of the outcome. Only a `requested` row
     * can be resolved, once.
     *
     * @throws SeoReviewException
     */
    public function resolve(int $actorUserId, Business $business, string $requestUid, string $outcome): SeoReviewRequest
    {
        $outcomeCase = SeoReviewRequestStatus::tryFrom($outcome);

        return DB::transaction(function () use ($actorUserId, $business, $requestUid, $outcomeCase) {
            $locked = $this->gate->lockAuthorizedBusiness($actorUserId, $business);

            $request = SeoReviewRequest::query()
                ->where('business_id', $locked->id)
                ->where('uid', $requestUid)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw SeoReviewException::accessDenied();
            }

            // A request is only reachable through a Location the actor may
            // access; otherwise it is indistinguishable from a missing one.
            $this->gate->activeLocationById($actorUserId, $locked, (int) $request->business_location_id);

            if ($outcomeCase === null || $outcomeCase === SeoReviewRequestStatus::Requested) {
                throw SeoReviewException::invalidOutcome();
            }

            if ($request->status !== SeoReviewRequestStatus::Requested) {
                throw SeoReviewException::alreadyResolved();
            }

            $request->forceFill(['status' => $outcomeCase->value, 'resolved_at' => now()])->save();

            return $request;
        });
    }

    /**
     * The ledger for a set of ALREADY-AUTHORIZED Location ids, newest first.
     * A Location not in the list is never read or counted.
     *
     * @param  array<int, int>  $accessibleLocationIds
     * @return Collection<int, SeoReviewRequest>
     */
    public function forAccessibleLocations(Business $business, array $accessibleLocationIds, int $limit = 200): Collection
    {
        if ($accessibleLocationIds === []) {
            return new Collection();
        }

        return SeoReviewRequest::query()
            ->where('business_id', $business->id)
            ->whereIn('business_location_id', $accessibleLocationIds)
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @throws SeoReviewException
     */
    private function authorizedContact(User $actor, Business $locked, int $locationId, string $contactUid): Contacts
    {
        // The existing Contacts authority. SEO adds no PII mechanism: a user
        // who cannot open Contacts cannot pick one here either.
        if (! Gate::forUser($actor)->allows('view_contact')) {
            throw SeoReviewException::contactForbidden();
        }

        $contact = Contacts::query()
            ->where('business_id', $locked->id)
            ->where('uid', $contactUid)
            ->first();

        if ($contact === null) {
            throw SeoReviewException::contactInvalid();
        }

        // Null never equals a Location: a Contact with no proven Location
        // cannot be tied to one here.
        if ($contact->location_id === null || (int) $contact->location_id !== $locationId) {
            throw SeoReviewException::contactLocationMismatch();
        }

        return $contact;
    }

    /**
     * The optional objective trigger. It must belong to this Business, and
     * agree with the Contact and Location where it names them.
     *
     * @throws SeoReviewException
     */
    private function matchingOpportunity(Business $locked, int $locationId, ?Contacts $contact, string $opportunityUid): CrmOpportunity
    {
        $opportunity = CrmOpportunity::query()
            ->where('business_id', $locked->id)
            ->where('uid', $opportunityUid)
            ->first();

        if ($opportunity === null
            || ($opportunity->location_id !== null && (int) $opportunity->location_id !== $locationId)
            || ($contact !== null && $opportunity->contact_id !== null && (int) $opportunity->contact_id !== (int) $contact->id)) {
            throw SeoReviewException::opportunityInvalid();
        }

        return $opportunity;
    }

    /**
     * Contract 18 §8.6 — at most one NON-DECLINED request per (Contact,
     * Location) inside the window. Runs under the Business row lock.
     *
     * @throws SeoReviewException
     */
    private function assertOutsideCooldown(int $businessId, int $locationId, int $contactId): void
    {
        $windowStart = now()->subDays($this->config->reviewRequestCooldownDays());

        $inside = SeoReviewRequest::query()
            ->where('business_id', $businessId)
            ->where('business_location_id', $locationId)
            ->where('contact_id', $contactId)
            ->where('status', '!=', SeoReviewRequestStatus::Declined->value)
            ->where('requested_at', '>', $windowStart)
            ->exists();

        if ($inside) {
            throw SeoReviewException::cooldown();
        }
    }
}
