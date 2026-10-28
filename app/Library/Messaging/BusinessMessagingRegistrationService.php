<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Dashboard\DashboardMoney;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\BrandStatusResult;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\DTO\RegistrationStatusQuery;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\Exceptions\MessagingRegistrationImmutableException;
use App\Library\Messaging\Exceptions\MessagingRegistrationNotEligibleException;
use App\Library\Messaging\Exceptions\MessagingRegistrationNotReadyException;
use App\Library\Messaging\Exceptions\MessagingRegistrationProviderException;
use App\Models\Business;
use App\Models\BusinessMessagingRegistration;
use App\Repositories\Contracts\UsageMeterRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Library\Messaging\Exceptions\MessagingRegistrationAlreadySubmittedException;

/**
 * Text messaging setup/number/compliance hub — STATE 2's capture/submit/
 * refresh lifecycle for one Business's carrier registration.
 *
 * "Capture Business data once and reuse it where possible" is this
 * class's own responsibility: captureDetails() is idempotent per Business
 * (create-or-update the one row a Business ever has, per the table's own
 * unique constraint) so re-visiting the form after a rejection edits the
 * SAME record rather than creating a second one.
 */
class BusinessMessagingRegistrationService
{
    public function __construct(
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly UsageMeterRepository $meters,
    ) {
    }
    /**
     * @param  array<string, mixed>  $data  already-validated form input,
     *                                      keyed exactly like the model's
     *                                      own fillable fields
     *
     * @throws MessagingRegistrationImmutableException when the existing
     *                                                  record is already
     *                                                  Approved (PR #295
     *                                                  Correction Round 1,
     *                                                  item 8) — once a
     *                                                  carrier has approved
     *                                                  the exact data this
     *                                                  platform submitted,
     *                                                  the normal customer
     *                                                  edit route must
     *                                                  never silently drift
     *                                                  it out of sync
     */
    public function captureDetails(Business $business, array $data): BusinessMessagingRegistration
    {
        $registration = BusinessMessagingRegistration::query()
            ->where('business_id', $business->id)
            ->first();

        if ($registration !== null && $registration->status === MessagingRegistrationStatus::Approved) {
            throw new MessagingRegistrationImmutableException(
                'This registration has already been approved and its legal/compliance details can no longer be edited through this form.',
            );
        }

        // A submitted-and-awaiting-review registration must not be reset to
        // NotStarted by an edit: that would re-open it for a second
        // submission against the carrier.
        if ($registration !== null && $registration->status === MessagingRegistrationStatus::Pending) {
            throw new MessagingRegistrationImmutableException(
                'This registration has already been submitted and is awaiting review, so its details can no longer be edited.',
            );
        }

        $attributes = array_merge($data, ['business_id' => $business->id]);

        if ($registration === null) {
            // The schema's own DEFAULT 'not_started' only takes effect once
            // MySQL has actually written the row; the in-memory model
            // create() returns would otherwise carry a null status until
            // the next fresh() read. Set it explicitly so the object this
            // method hands back is correct immediately.
            $attributes['status'] = $attributes['status'] ?? MessagingRegistrationStatus::NotStarted->value;

            return BusinessMessagingRegistration::create($attributes);
        }

        // Editing a rejected or not-yet-submitted registration always
        // clears any previous rejection — the customer is trying again
        // with (presumably) corrected information, and a stale rejection
        // reason must never linger beside fresh data.
        $attributes['status'] = MessagingRegistrationStatus::NotStarted->value;
        $attributes['rejection_reason'] = null;

        // A brand the carrier rejected, or one created for different
        // legal details, must never be reused for the edited submission.
        // Every field the brand request carries: if any changed, the
        // existing provider brand no longer matches the edited details.
        $brandIdentityChanged = false;

        foreach (['legal_business_name', 'entity_type', 'ein', 'address_line_1', 'city', 'region', 'postal_code', 'country_code', 'website_url', 'contact_email', 'contact_phone'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $current = $registration->{$field};
            $current = $current instanceof \BackedEnum ? $current->value : $current;
            $incoming = $data[$field] instanceof \BackedEnum ? $data[$field]->value : $data[$field];

            if ((string) $incoming !== (string) $current) {
                $brandIdentityChanged = true;
                break;
            }
        }

        // A campaign already created at the provider (saved by a crashed
        // attempt that has not yet finished) cannot be re-created or
        // edited here: changing what it was submitted with would either
        // reuse an invalidated campaign or create a duplicate.
        if ($registration->status !== MessagingRegistrationStatus::Rejected
            && (($registration->provider_campaign_id !== null && $registration->provider_campaign_id !== '')
                || ($registration->provider_registration_id !== null && $registration->provider_registration_id !== ''))) {
            $campaignFields = ['number_type', 'use_case', 'campaign_description', 'sample_message_1', 'sample_message_2', 'opt_in_method', 'privacy_policy_url', 'terms_url'];

            foreach ($campaignFields as $field) {
                $currentValue = $registration->{$field};
                $currentValue = $currentValue instanceof \BackedEnum ? $currentValue->value : $currentValue;

                if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $currentValue) {
                    $brandIdentityChanged = true;
                    break;
                }
            }

            if ($brandIdentityChanged) {
                throw new MessagingRegistrationImmutableException(
                    'A submission for this registration is being finalized, so its details can\'t be edited right now. Please contact support if it does not complete.',
                );
            }
        }

        // A brand the carrier is verifying (or has verified) was created and
        // paid for with these exact details. Editing them would orphan it and
        // buy a second brand, so it is refused; only a brand the carrier
        // flagged as needing attention may be replaced by corrected details.
        $hasBrand = trim((string) $registration->provider_brand_id) !== '';

        if ($brandIdentityChanged && $hasBrand && $registration->status !== MessagingRegistrationStatus::Rejected
            && $registration->brand_status !== 'needs_attention') {
            throw new MessagingRegistrationImmutableException(
                'Your business details were already sent to the carrier for verification, so they can\'t be changed here. Contact support if something needs correcting.',
            );
        }

        $keepVerifiedBrand = $registration->status === MessagingRegistrationStatus::Rejected
            && ! $brandIdentityChanged
            && $registration->brand_status === 'verified';

        if ($registration->status === MessagingRegistrationStatus::Rejected || $brandIdentityChanged) {
            if (! $keepVerifiedBrand) {
                $attributes['provider_brand_id'] = null;
                $attributes['provider_brand_tcr_id'] = null;
                $attributes['brand_status'] = null;
                $attributes['brand_status_detail'] = null;
            }

            $attributes['provider_campaign_id'] = null;
            $attributes['provider_registration_id'] = null;
        }

        $registration->update($attributes);

        return $registration->fresh();
    }

    /**
     * @throws MessagingProviderNotConfiguredException when not configured — the
     *                                                  caller must have already
     *                                                  checked
     *                                                  ProvisioningAvailability::isConfigured()
     */
    public function submit(BusinessMessagingRegistration $registration, bool $campaignAuthorized = false): BusinessMessagingRegistration
    {
        $adapter = app(MessagingProvisioningAdapter::class);

        // Retry safety: only one submission per registration may be in
        // flight, and a registration that is already Pending/Approved must
        // never be submitted again. Status is re-read under the lock so a
        // double click or a retry after a lost response is a harmless
        // no-op. The lock is a MySQL session lock (shared by every worker
        // and host, no expiry, freed when the connection dies) rather than
        // a per-host cache file.
        $release = $this->acquireSubmitLock($registration);

        if ($release === null) {
            throw new MessagingRegistrationAlreadySubmittedException('A submission for this registration is already in progress.');
        }

        try {
            $registration = $registration->fresh();

            if ($registration->status === MessagingRegistrationStatus::Pending || $registration->status === MessagingRegistrationStatus::Approved) {
                throw new MessagingRegistrationAlreadySubmittedException('This registration has already been submitted.');
            }

            // A Rejected registration resubmitted without edits starts a fresh
            // submission: the rejected provider brand/campaign are never
            // reused. rejected_at is kept — it identifies the funding cycle.
            if ($registration->status === MessagingRegistrationStatus::Rejected) {
                // A carrier-VERIFIED brand survives a rejected campaign: it is
                // still valid and was already paid for, so correcting the
                // campaign never buys a second brand. A rejected brand is
                // never reused.
                $keepBrand = $registration->brand_status === 'verified' && trim((string) $registration->provider_campaign_id) !== '';
                $registration->update([
                    'status' => MessagingRegistrationStatus::NotStarted->value,
                    'provider_brand_id' => $keepBrand ? $registration->provider_brand_id : null,
                    'provider_brand_tcr_id' => $keepBrand ? $registration->provider_brand_tcr_id : null,
                    'brand_status' => $keepBrand ? 'verified' : null,
                    'provider_campaign_id' => null,
                    'provider_registration_id' => null,
                    'rejection_reason' => null,
                    'campaign_issue' => null,
                    'campaign_issue_permanent' => false,
                ]);
                $registration = $registration->fresh();
            }

            // The owned number decides the regime. A registration saved as
            // local before a toll-free number was bought (or the reverse)
            // must never be submitted — and charged — under the wrong
            // regime: switch the type, keep every captured field and every
            // provider id.
            $ownedType = $this->primaryNumberTypeFor($registration->business);

            if ($ownedType !== null && $ownedType !== $registration->number_type
                && ($registration->provider_campaign_id === null || $registration->provider_campaign_id === '')) {
                // Provider ids are kept (never silently deleted): each regime
                // only ever reuses its own (brand/campaign for local, the
                // verification id for toll-free).
                $registration->update(['number_type' => $ownedType->value]);
                $registration = $registration->fresh();
            }

            // Review correction — a toll-free submission's own request body
            // must name the already-owned number being verified
            // (MessagingRegistrationSubmission::$phoneNumber); a local (10DLC)
            // submission has no such requirement and may genuinely be
            // submitted before any number exists, so $phoneNumber stays null
            // there — never resolved, never required.
            $phoneNumber = $this->primaryPhoneNumberFor($registration->business);

            // 10DLC is two stages. Stage 1 creates the brand once (and charges
            // its fee once); stage 2, the campaign, starts only after the
            // carrier has verified the brand AND the customer authorized the
            // campaign fees. A campaign that already exists is only ever
            // finished, never created again.
            $phase = 'full';

            if ($registration->number_type === PhoneNumberType::Local) {
                $hasCampaign = trim((string) $registration->provider_campaign_id) !== '';
                $hasBrand = trim((string) $registration->provider_brand_id) !== '';

                if ($hasCampaign) {
                    $phase = 'campaign';
                } elseif (! $hasBrand) {
                    $phase = 'brand';
                } elseif ($registration->brand_status === 'verified') {
                    if (! $campaignAuthorized) {
                        throw new MessagingRegistrationNotReadyException('Confirm that you authorize the campaign charge shown below to submit your campaign.', MessagingRegistrationNotReadyException::REASON_NEEDS_AUTHORIZATION);
                    }

                    $phase = 'campaign';
                } else {
                    throw new MessagingRegistrationNotReadyException('Your business is still being verified by the carrier. You can submit your campaign as soon as verification completes.');
                }
            }

            try {
                $result = $adapter->submitRegistration(MessagingRegistrationSubmission::fromModel($registration, $phoneNumber, $phase));
            } catch (MessagingRegistrationNotEligibleException $e) {
                // Remember why the campaign was not created. Nothing here is a
                // charge or a provider id; a temporary issue is never final.
                $registration->update(['campaign_issue' => mb_substr($e->getMessage(), 0, 255), 'campaign_issue_permanent' => ! $e->temporary]);

                throw $e;
            } catch (MessagingRegistrationProviderException $e) {
                if ($e->step === MessagingRegistrationProviderException::STEP_CAMPAIGN) {
                    $registration->update(['campaign_issue' => 'The carrier could not accept the campaign submission yet.', 'campaign_issue_permanent' => false]);
                }

                throw $e;
            }

            if ($phase === 'brand') {
                // A brand the carrier just created is never trusted as verified.
                $registration->update(array_filter([
                    'provider_brand_id' => $result->providerBrandId,
                    'provider_brand_tcr_id' => $result->providerBrandTcrId,
                    'brand_status' => 'processing',
                    'brand_created_at' => $registration->brand_created_at ?? now(),
                ], fn ($v) => $v !== null));

                return $registration->fresh();
            }

            // A provider id the result does not carry (a toll-free result has
            // no brand/campaign, a 10DLC result no verification id) is left
            // as it is — never blanked, so references from another regime
            // are never silently deleted.
            $registration->update(array_merge(array_filter([
                'provider_brand_id' => $result->providerBrandId,
                'provider_campaign_id' => $result->providerCampaignId,
                'provider_registration_id' => $result->providerRegistrationId,
            ], fn ($id) => $id !== null), [
                'status' => $result->status->value,
                'submitted_at' => CarbonImmutable::now(),
                'rejection_reason' => null,
                'campaign_issue' => null,
                'campaign_issue_permanent' => false,
            ], $phase === 'campaign' ? ['brand_status' => 'verified'] : []));

            return $registration->fresh();
        } finally {
            $release();
        }
    }

    /** @return (callable(): void)|null null when another worker holds the lock */
    private function acquireSubmitLock(BusinessMessagingRegistration $registration): ?callable
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $name = 'msgreg-submit:' . $registration->id;
            $connection = DB::connection();

            if ((int) ($connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired ?? 0) !== 1) {
                return null;
            }

            return static function () use ($connection, $name): void {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            };
        }

        $lock = Cache::lock('messaging-registration-submit:' . $registration->id, 120);

        return $lock->get() ? static fn () => $lock->release() : null;
    }

    private function primaryNumberTypeFor(Business $business): ?PhoneNumberType
    {
        $identity = $this->identities->resolveForBusiness($business);

        if ($identity === null) {
            return null;
        }

        try {
            return $this->identities->resolvePrimaryNumber($identity)->number_type;
        } catch (MessagingIdentityConflictException) {
            return null;
        }
    }

    private function primaryPhoneNumberFor(Business $business): ?string
    {
        $identity = $this->identities->resolveForBusiness($business);

        if ($identity === null) {
            return null;
        }

        try {
            return $this->identities->resolvePrimaryNumber($identity)->phone_number;
        } catch (MessagingIdentityConflictException) {
            return null;
        }
    }

    /**
     * Polls the provider and applies whatever it says — never advances the
     * status without an explicit provider answer.
     *
     * PR #295 Correction Round 1, item 7 — routes through the type-aware
     * RegistrationStatusQuery instead of assuming every registration has a
     * brand+campaign pair.
     */
    public function refreshStatus(BusinessMessagingRegistration $registration): BusinessMessagingRegistration
    {
        // A local registration with a brand but no campaign is in stage 1:
        // only the brand's verification is tracked. It must never be polled as
        // a campaign (which would wrongly read as Pending review).
        if ($registration->number_type === PhoneNumberType::Local && trim((string) $registration->provider_campaign_id) === '') {
            return $this->refreshBrand($registration);
        }

        $query = RegistrationStatusQuery::fromModel($registration);

        if ($query->providerBrandId === null && $query->providerCampaignId === null && $query->providerRegistrationId === null) {
            return $registration;
        }

        try {
            $adapter = app(MessagingProvisioningAdapter::class);
        } catch (MessagingProviderNotConfiguredException) {
            return $registration;
        }

        $result = $adapter->refreshRegistrationStatus($query);

        if ($result->conclusive && $query->providerCampaignId !== null) {
            $registration->forceFill(['campaign_status_checked_at' => CarbonImmutable::now()])->save();
        }

        if ($result->status === $registration->status) {
            return $registration;
        }

        $attributes = ['status' => $result->status->value];

        if ($result->status === MessagingRegistrationStatus::Approved) {
            $attributes['approved_at'] = CarbonImmutable::now();
        } elseif ($result->status === MessagingRegistrationStatus::Rejected) {
            $attributes['rejected_at'] = CarbonImmutable::now();
            // Review correction — the carrier-supplied reason, when the
            // adapter's own response actually carried one; null when it
            // did not, so the customer sees an explicit "no detailed
            // reason was supplied" message rather than nothing at all.
            $attributes['rejection_reason'] = $result->rejectionReason;
        }

        $registration->update($attributes);

        return $registration->fresh();
    }

    /**
     * Stage 1 tracking: asks the carrier whether the saved brand has been
     * identity-verified. Only a conclusive answer changes anything; an
     * inconclusive lookup (rate limit, timeout, server error) leaves the saved
     * state exactly as it was. Never creates a brand and never charges.
     */
    public function refreshBrand(BusinessMessagingRegistration $registration): BusinessMessagingRegistration
    {
        $brandId = trim((string) $registration->provider_brand_id);

        if ($brandId === '' || $registration->number_type !== PhoneNumberType::Local) {
            return $registration;
        }

        try {
            $result = app(MessagingProvisioningAdapter::class)->refreshBrandStatus($brandId);
        } catch (MessagingProviderNotConfiguredException) {
            return $registration;
        }

        if ($result->state === BrandStatusResult::UNCHANGED) {
            return $registration;
        }

        $state = $result->state;
        $detail = $result->detail;
        $hours = (int) config('messaging.brand_verification_attention_hours', 72);

        // A brand still unverified long after creation is surfaced truthfully
        // as needing attention. Polling continues, so it recovers on its own
        // if the carrier verifies it later.
        if ($state === BrandStatusResult::PROCESSING && $registration->brand_created_at !== null
            && $registration->brand_created_at->lt(now()->subHours($hours))) {
            $state = BrandStatusResult::NEEDS_ATTENTION;
            $detail = 'The carrier has not finished verifying this business yet. Support has been asked to look into it; your details and brand are saved.';
        }

        $attributes = [
            'brand_status' => $state,
            'brand_status_detail' => $state === BrandStatusResult::VERIFIED ? null : ($detail !== null ? mb_substr($detail, 0, 191) : null),
            'brand_status_checked_at' => CarbonImmutable::now(),
        ];

        if ($result->tcrBrandId !== null) {
            $attributes['provider_brand_tcr_id'] = $result->tcrBrandId;
        }

        // A brand that was refused as unverified at qualification time and is
        // now verified clears the temporary eligibility issue.
        if ($state === BrandStatusResult::VERIFIED && ! $registration->campaign_issue_permanent) {
            $attributes['campaign_issue'] = null;
        }

        $registration->forceFill($attributes)->save();

        return $registration->fresh();
    }

    /** Whether a brand's next status check is due; backs off as the brand ages. */
    private function brandCheckDue(BusinessMessagingRegistration $registration): bool
    {
        $checked = $registration->brand_status_checked_at;

        if ($checked === null) {
            return true;
        }

        $age = $registration->brand_created_at;
        $interval = match (true) {
            $registration->brand_status === 'needs_attention' => 360,
            $age === null || $age->gt(now()->subHours(2)) => 10,
            default => 45,
        };

        return $checked->lte(now()->subMinutes($interval));
    }

    /**
     * PR #295 Correction Round 1, item 6 — the one canonical mechanism
     * that actually advances a submitted registration: a scheduled job
     * (RefreshPendingMessagingRegistrations) calls this, never a page
     * render. One provider-configuration failure never aborts the rest of
     * the batch.
     */
    public function refreshAllPending(): void
    {
        BusinessMessagingRegistration::query()
            ->where('status', MessagingRegistrationStatus::Pending->value)
            ->orderBy('id')
            ->chunkById(50, function ($registrations): void {
                foreach ($registrations as $registration) {
                    try {
                        $this->refreshStatus($registration);
                    } catch (\Throwable) {
                        // One Business's provider failure must never stop
                        // the sweep from reaching every other pending
                        // registration in this batch.
                        continue;
                    }
                }
            });

        // Stage 1: brands created but not yet verified. Idempotent and
        // read-only at the provider; one failure never stops the sweep.
        BusinessMessagingRegistration::query()
            ->where('status', MessagingRegistrationStatus::NotStarted->value)
            ->where('number_type', PhoneNumberType::Local->value)
            ->whereNotNull('provider_brand_id')->where('provider_brand_id', '!=', '')
            ->where(fn ($q) => $q->whereNull('provider_campaign_id')->orWhere('provider_campaign_id', ''))
            ->where(fn ($q) => $q->whereNull('brand_status')->orWhereIn('brand_status', ['processing', 'needs_attention']))
            ->orderBy('id')
            ->chunkById(50, function ($registrations): void {
                foreach ($registrations as $registration) {
                    try {
                        if ($this->brandCheckDue($registration)) {
                            $this->refreshBrand($registration);
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }
            });
    }

    /**
     * Review correction — TelnyxProvisioningAdapter reserves real wallet
     * funds for both FEATURE_TEN_DLC_REGISTRATION and
     * FEATURE_TOLL_FREE_VERIFICATION before ever submitting a
     * registration; business verification was never actually free, and
     * this platform must never claim otherwise. Reads the SAME meter/rate
     * UsageWalletManager::reserve() would itself apply — never a second,
     * invented pricing source — and states plainly when none is
     * configured, which is the true state of every environment today (no
     * owner has approved a commercial rate for either feature key yet).
     */
    public const TOLL_FREE_DISCLOSURE = 'Toll-free verification is $0 through Telnyx. Number rental and SMS usage are still charged.';

    public const LOCAL_COST_NOTICE = 'Local numbers also need carrier 10DLC registration. Besides number rental and SMS usage, that means a one-time registration charge (the carrier\'s brand fee plus the first three months of campaign fees, which the carrier bills upfront), then a monthly campaign fee after those three months. The carrier sets those amounts.';

    public function chargeDisclosureFor(PhoneNumberType $numberType): string
    {
        // Toll-free verification has no fee and no billing meter — never
        // looked up, so nothing configured elsewhere can leak into it.
        if ($numberType === PhoneNumberType::TollFree) {
            return self::TOLL_FREE_DISCLOSURE;
        }

        $meter = $this->meters->findByMeterKey(TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        if ($meter === null || ! $meter->is_metered || $meter->activeRate === null) {
            return 'No fee is currently configured for business verification in this environment.';
        }

        $rate = $meter->activeRate;
        $amount = DashboardMoney::format((string) $rate->retail_rate_micro, $rate->currency?->code);
        $unit = $rate->unit_label !== null && $rate->unit_label !== '' ? ' per ' . $rate->unit_label : '';

        return sprintf('Business verification currently costs %s%s.', $amount, $unit);
    }
}
