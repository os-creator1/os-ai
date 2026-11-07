<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Messaging\CampaignAssignmentOutcome;
use App\Enums\Messaging\MessagingEntityType;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use App\Library\AgencyOutreach\MessagingReadinessReader;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\ProvisioningPilot;
use App\Library\Messaging\BusinessMessagingProvisioningService;
use App\Library\Messaging\BusinessMessagingRegistrationService;
use App\Library\Messaging\CandidateToken;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\Exceptions\InvalidCandidateTokenException;
use App\Library\Messaging\Exceptions\MessagingFundingUnavailableException;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\MessagingInsufficientFundsException;
use App\Library\Messaging\Exceptions\MessagingRegistrationAlreadySubmittedException;
use App\Library\Messaging\Exceptions\MessagingRegistrationRecoveryRequiredException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\Exceptions\MessagingRegistrationImmutableException;
use App\Library\Messaging\Exceptions\PortOutRequestAlreadyActiveException;
use App\Library\Messaging\Exceptions\PortOutRequestNumberNotPortableException;
use App\Library\Messaging\PortOutRequestManager;
use App\Library\Messaging\ProvisioningAvailability;
use App\Library\Messaging\Registration\RegistrationDefaultsResolver;
use App\Library\Messaging\Registration\RegistrationSubmissionValidator;
use App\Library\Messaging\Registration\RegistrationTemplateCatalog;
use App\Library\Navigation\CustomerShellComposer;
use App\Models\Business;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingNumberPortOutRequest;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Owner product decision — the customer should never need to understand
 * or configure a "messaging channel". Settings -> Text messaging is the
 * entire messaging setup/health hub for every tier (Core, Growth, and an
 * Agency Business using managed transport): plain, three-state UI —
 *
 *   STATE 1 (no number)              -> "Get a phone number"
 *   STATE 2 (number acquired,        -> "Messaging registration"
 *            registration required)     status/next-action
 *   STATE 3 (ready)                  -> number, status, capabilities,
 *                                        Delivery & usage, billing link
 *
 * Never Telnyx/Twilio, never "10DLC", never an API key or Messaging
 * Profile id. The Agency-only Advanced (BYO) surface
 * (MessagingChannelsController, Settings -> Advanced -> Messaging
 * provider) is a completely separate controller/route/permission set;
 * this one does not gate, replace, or depend on it, and this controller
 * never writes a CustomerBasedSendingServer/SendingServer row.
 *
 * Every write here goes through BusinessMessagingProvisioningService /
 * BusinessMessagingRegistrationService, which resolve the provisioning
 * adapter lazily and structurally cannot produce a persisted number
 * without a genuine (or explicitly-faked-in-a-test) provider round trip
 * — "never fake a successful purchase" is enforced there, not here.
 */
class TextMessagingController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    private const US_USE_CASES = [
        'customer_care' => 'Customer care and support',
        'marketing' => 'Marketing and promotions',
        'account_notifications' => 'Account notifications',
        'appointment_reminders' => 'Appointment reminders',
        'two_factor' => 'Two-factor / one-time passcodes',
        'mixed' => 'Both service and promotional messages',
        'low_volume_mixed' => 'Both service and promotional messages (Low Volume Mixed)',
    ];

    public function __construct(
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly BusinessMessagingProvisioningService $provisioning,
        private readonly BusinessMessagingRegistrationService $registrations,
        private readonly BusinessAnalyticsPresenter $analyticsPresenter,
        private readonly CustomerShellComposer $shell,
        private readonly PortOutRequestManager $portOutRequests,
        private readonly MessagingReadinessReader $readiness,
        private readonly RegistrationDefaultsResolver $registrationDefaults,
        private readonly RegistrationSubmissionValidator $registrationValidator,
        private readonly \App\Library\Messaging\MessagingChargePreview $charges,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('view_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        // A purchase that is still being finished at the carrier: show its progress (never
        // another purchase form). Completed orders fall through to the normal state below.
        $openOrder = $this->provisioning->openOrderFor($business);

        if ($openOrder !== null && $this->identities->resolveForBusiness($business) === null) {
            return view('customer.settings.text-messaging.states.number-processing', [
                'workspaceUid' => $workspaceUid,
                'businessUid' => $businessUid,
                'order' => $openOrder,
                'statusUrl' => route('customer.workspaces.businesses.text-messaging.number.order-status', [$workspaceUid, $businessUid]),
                'showUrl' => route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid]),
            ]);
        }

        $situation = $this->situation($business);

        return match ($situation['state']) {
            'ready' => $this->renderReady($workspaceUid, $businessUid, $situation),
            'registration_required' => $this->renderRegistration($workspaceUid, $businessUid, $situation, $business),
            // Review correction — a Business whose local (10DLC) business
            // verification is already Approved but has no number yet
            // (Telnyx genuinely supports completing brand+campaign
            // registration before any number is purchased): STATE 1's own
            // search/order view, restricted to local and carrying a
            // verified banner, rather than the initial number-type choice.
            'number_required' => $this->renderNoNumber($workspaceUid, $businessUid, $situation, numberTypeLocked: PhoneNumberType::Local),
            default => $this->renderNoNumber($workspaceUid, $businessUid, $situation),
        };
    }

    /** The purchase is being finished in the background: land on the progress page, no error. */
    private function numberBeingSetUp(string $workspaceUid, string $businessUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid]);
    }

    /**
     * Polled by the "Setting up your number…" page. Business-authorized and tenant-scoped (the order
     * is looked up by THIS Business only). Each poll may nudge a read-only carrier check, throttled
     * per order, so the page completes promptly even before the next scheduler tick.
     */
    public function numberOrderStatus(string $workspaceUid, string $businessUid): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        $order = \App\Models\BusinessMessagingNumberOrder::query()->where('business_id', (int) $business->id)->orderByDesc('id')->first();

        if ($order !== null && $order->isOpen()) {
            $order = app(\App\Library\Messaging\NumberOrderReconciler::class)->nudge($order);
        }

        $showUrl = route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid]);
        $hasNumber = $this->identities->resolveForBusiness($business) !== null;

        $payload = match (true) {
            $order !== null && $order->isOpen() && ! $hasNumber => [
                'state' => $order->status === \App\Models\BusinessMessagingNumberOrder::NEEDS_ATTENTION ? 'delayed' : 'processing',
                'phone_number' => $order->phone_number,
                'message' => $order->status === \App\Models\BusinessMessagingNumberOrder::NEEDS_ATTENTION
                    ? 'This is taking longer than usual. Our team has been alerted and your number will be finished automatically. You have not been charged twice, and you do not need to buy it again.'
                    : 'Setting up your number…',
            ],
            $hasNumber || ($order !== null && $order->status === \App\Models\BusinessMessagingNumberOrder::COMPLETED) => [
                'state' => 'completed',
                'phone_number' => $order?->phone_number,
                'message' => 'Your number is ready.',
                'redirect_url' => $showUrl,
            ],
            $order !== null && $order->status === \App\Models\BusinessMessagingNumberOrder::FAILED => [
                'state' => 'failed',
                'phone_number' => $order->phone_number,
                'message' => 'The carrier could not complete this number. Our team has been alerted to review the charge for this order. Please contact support before choosing another number.',
                'redirect_url' => $showUrl,
            ],
            default => ['state' => 'none', 'phone_number' => null, 'message' => '', 'redirect_url' => $showUrl],
        };

        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    // -----------------------------------------------------------------
    // STATE 1 — no number.
    // -----------------------------------------------------------------

    /**
     * PR #295 Correction Round 1, item 1 — searching/ordering a number is
     * a cost-incurring number-acquisition action, so it reuses the
     * canonical buy_numbers permission rather than the read-only
     * view_numbers used by show()/deliveryUsage().
     */
    public function searchNumber(Request $request, string $workspaceUid, string $businessUid): View|Factory|Application|RedirectResponse
    {
        $this->authorize('buy_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->guardNoExistingNumber($business);

        $validated = $request->validate([
            'number_type' => ['required', 'in:local,toll_free'],
            'area_code' => ['nullable', 'digits:3'],
            'toll_free_prefix' => ['nullable', 'in:800,888,877,866,855,844,833'],
        ]);

        if ($validated['number_type'] === 'local' && ! $this->hasApprovedLocalRegistration($business)) {
            // Telnyx genuinely supports (and this platform now requires)
            // completing 10DLC business verification BEFORE a local number
            // is searched for or purchased — toll-free is unaffected: its
            // own carrier verification requires an already-owned number,
            // so it is never subject to this gate.
            return $this->textMessagingError($workspaceUid, $businessUid, 'Verify your business first, then choose your local number.');
        }

        $criteria = new NumberSearchCriteria(
            countryCode: 'US',
            numberType: PhoneNumberType::from($validated['number_type']),
            areaCode: $validated['number_type'] === 'local' ? ($validated['area_code'] ?? null) : null,
            tollFreePrefix: $validated['number_type'] === 'toll_free' ? ($validated['toll_free_prefix'] ?? null) : null,
        );

        if (! ProvisioningPilot::allows($business)) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Number setup is not available for this Business yet.');
        }

        // Telnyx may return the same leading inventory on every request. Fetch a
        // larger pool in the adapter, but show only unseen candidates in batches
        // of five. Keep history in the current session, scoped to this Business
        // and search criteria; it never authorizes a purchase.
        $searchScope = implode('|', [
            (string) $business->id,
            $criteria->numberType->value,
            $criteria->areaCode ?? '',
            $criteria->tollFreePrefix ?? '',
        ]);
        $seenKey = 'messaging.number_search.seen.' . sha1($searchScope);
        $seen = $request->session()->get($seenKey, []);
        $seen = is_array($seen) ? array_values(array_filter($seen, 'is_string')) : [];

        $inventory = $this->provisioning->searchNumbers($criteria);
        $unseen = [];
        $seenLookup = array_fill_keys($seen, true);

        foreach ($inventory as $candidate) {
            if (isset($seenLookup[$candidate->phoneNumber])) {
                continue;
            }

            $unseen[] = $candidate;
            $seenLookup[$candidate->phoneNumber] = true;
        }

        $candidates = array_slice($unseen, 0, 5);
        $newlyShown = array_map(fn ($candidate) => $candidate->phoneNumber, $candidates);
        $request->session()->put($seenKey, array_slice(array_values(array_unique([...$seen, ...$newlyShown])), -100));
        $numberOptionsExhausted = count($inventory) > 0 && count($candidates) === 0;

        // Every offered choice retains its own Business-bound token and quoted cost.
        $candidateOffers = array_map(fn ($candidate) => [
            'candidate' => $candidate,
            'token' => CandidateToken::encode($business, $candidate),
            'preview' => $this->charges->forNumberQuote($business, $candidate),
        ], $candidates);
        $candidate = $candidates[0] ?? null;

        return view('customer.settings.text-messaging.states.no-number', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'available' => $this->provisioningOpenFor($business),
            'searched' => true,
            'criteria' => $validated,
            'candidate' => $candidate,
            'candidateOffers' => $candidateOffers,
            'numberOptionsExhausted' => $numberOptionsExhausted,
            'verificationChargeDisclosure' => $this->registrations->chargeDisclosureFor(PhoneNumberType::Local),
            'numberPreview' => $candidate !== null ? $this->charges->forNumberQuote($business, $candidate) : $this->charges->forNumberPurchase($business),
            'localRegistrationPreview' => $this->charges->forLocalRegistration($business),
            'lowVolumeRegistrationPreview' => $this->charges->forLocalRegistration($business, 'low_volume_mixed'),
            // PR #295 Correction Round 1, item 2 — the browser never gets
            // the raw phone_number/provider_candidate_reference/number_type
            // fields back as independently-editable form inputs; it only
            // ever gets this one opaque, short-lived, Business-bound token.
            'candidateToken' => $candidate !== null ? CandidateToken::encode($business, $candidate) : null,
            'numberTypeLocked' => $this->hasApprovedLocalRegistration($business) ? PhoneNumberType::Local : null,
            'registration' => $this->registrationFor($business),
            ...$this->portOutContextFor($business),
        ]);
    }

    /**
     * PR #295 Correction Round 1, items 1, 2, 3, 4:
     *  - buy_numbers, not view_numbers (item 1).
     *  - the order is bound to a candidate this platform itself verified a
     *    moment earlier via CandidateToken, never to raw posted fields
     *    (item 2).
     *  - BusinessMessagingProvisioningService now reserves the identity
     *    slot before any provider call (item 3) and the real adapter
     *    refuses an unfunded cost-incurring call before it ever reaches
     *    Telnyx (item 4) — both surface here as ordinary, user-facing
     *    refusals, never a purchase.
     */
    public function orderNumber(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('buy_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->guardNoExistingNumber($business);

        // A purchase is already being set up (second click, refresh, retry): never place another
        // order — just show its progress.
        if ($this->provisioning->openOrderFor($business) !== null) {
            return $this->numberBeingSetUp($workspaceUid, $businessUid);
        }

        if (! $this->provisioningOpenFor($business)) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Number setup is not available for this Business yet.');
        }

        $validated = $request->validate([
            'candidate_token' => ['required', 'string'],
        ]);

        try {
            $candidate = CandidateToken::decode($validated['candidate_token'], $business);
        } catch (InvalidCandidateTokenException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'This number is no longer available. Please search again.');
        }

        if ($candidate->numberType === PhoneNumberType::Local && ! $this->hasApprovedLocalRegistration($business)) {
            // Same guard as searchNumber(), re-checked here independently
            // at the mutation boundary — a candidate token alone must never
            // be trusted to imply the sequence requirement was honored.
            return $this->textMessagingError($workspaceUid, $businessUid, 'Verify your business first, then choose your local number.');
        }

        try {
            $number = $this->provisioning->provisionNumber($business, $candidate);

            if ($number instanceof \App\Models\BusinessMessagingNumberOrder) {
                return $this->numberBeingSetUp($workspaceUid, $businessUid);
            }
        } catch (MessagingProviderNotConfiguredException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Number setup is not available in this environment yet.');
        } catch (MessagingIdentityConflictException) {
            // Lost a race with a purchase that is already in progress: show it, do not error.
            if ($this->provisioning->openOrderFor($business) !== null) {
                return $this->numberBeingSetUp($workspaceUid, $businessUid);
            }

            return $this->textMessagingError($workspaceUid, $businessUid, 'This Business already has a number set up.');
        } catch (\App\Library\Messaging\Exceptions\MessagingPlatformFundsException) {
            // Our own carrier account, not the customer's wallet: nothing was ordered or charged.
            return $this->textMessagingError($workspaceUid, $businessUid, 'Number purchases are temporarily unavailable on our side. Nothing was charged. Our team has been alerted; please try again later rather than choosing a different number.');
        } catch (MessagingRegistrationRecoveryRequiredException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'We couldn\'t confirm this number order with our carrier partner. Please don\'t try again; our team has been alerted and will follow up. The charge for this order is awaiting confirmation from the carrier: if the carrier confirms no number was bought it is refunded in full, and you will not be charged twice.');
        } catch (MessagingFundingUnavailableException $e) {
            \Illuminate\Support\Facades\Log::warning('Messaging number funding unavailable.', ['business_id' => $business->id, 'reason' => $e->reason]);

            return $this->textMessagingError($workspaceUid, $businessUid, $e->reason === MessagingFundingUnavailableException::REASON_WALLET_CONFIGURATION
                ? 'There is a problem with this Business\'s billing setup, so a number can\'t be bought yet. Nothing was charged. Please contact support.'
                : 'Number pricing hasn\'t been set up on our side yet, so a number can\'t be bought right now. Nothing was charged and your balance is not the issue. Please contact support and we\'ll finish setup.');
        } catch (\App\Library\Messaging\Exceptions\NumberPriceChangedException $e) {
            return $this->textMessagingError($workspaceUid, $businessUid, $e->getMessage());
        } catch (MessagingInsufficientFundsException $e) {
            // A rate IS configured and the wallet refused: a plain shortfall
            // keeps its Add-funds wording; any other refusal (spending
            // limit, paused activity, an Agency payer without consent, debt)
            // says what actually happened instead of blaming the balance.
            return $this->textMessagingError($workspaceUid, $businessUid, $this->fundingRefusalMessage($e, 'to add this number'));
        } catch (\RuntimeException) {
            // The carrier refused the order outright (funds were released,
            // nothing bought): a retry, or a different number, is safe.
            return $this->textMessagingError($workspaceUid, $businessUid, 'The carrier couldn\'t complete this number order right now. You have not been charged. Please try again, or search for a different number.');
        }

        $message = 'Phone number added. Next, complete messaging registration so texts deliver reliably.';

        if ($candidate->numberType === PhoneNumberType::Local) {
            $registration = $this->registrationFor($business);

            if ($registration !== null && $registration->isApproved()) {
                // The verify-first sequence: business verification already
                // Approved before this number existed. The last remaining
                // step is linking this number's own, freshly created
                // Messaging Profile to that already-approved campaign —
                // never itself a claim that the link is confirmed live
                // (see CampaignAssignmentOutcome's own docblock).
                $message = $this->composeLocalOrderMessage($business, $number, $registration);
            }
        }

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => $message,
        ]);
    }

    /**
     * Never blocks the number purchase's own success — the number is
     * genuinely, already purchased and charged by the time this runs.
     * BusinessMessagingProvisioningService::assignToApprovedCampaign()
     * itself persists the durable outcome and records a provisioning
     * incident on a failed (or unconfirmable — missing profile/campaign
     * id) result; this only composes the honest customer-facing message.
     * Never claims the number can already send — Requested is the
     * strongest honest claim this message ever makes; see
     * CampaignAssignmentOutcome's own docblock.
     */
    private function composeLocalOrderMessage(Business $business, BusinessMessagingNumber $number, BusinessMessagingRegistration $registration): string
    {
        $result = $this->provisioning->assignToApprovedCampaign($business, $number, $registration->provider_campaign_id);

        if ($result->outcome !== CampaignAssignmentOutcome::Requested) {
            return 'Phone number added. We could not confirm your number was linked to your approved campaign — our team has been notified and will follow up.';
        }

        return 'Phone number added. Your business is already verified. We have asked the carrier to enable this number for messaging — this is usually quick, but texting will not be available on this number until that is confirmed.';
    }

    // -----------------------------------------------------------------
    // STATE 2 — registration required.
    // -----------------------------------------------------------------

    /**
     * Review correction — the entry point into the verify-first sequence:
     * Telnyx genuinely supports completing 10DLC business verification
     * before any local number exists, so this creates the one (business-
     * scoped, create-or-update) registration row with number_type=local
     * and no number yet, rather than requiring a number first. Never
     * reachable for toll-free — its own carrier verification always
     * requires an already-owned number, so it has no equivalent "start
     * verification first" entry point; guardNoExistingNumber() below
     * still applies here, since this must never be reachable once a
     * number already exists either.
     */
    public function startLocalVerification(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('buy_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->guardNoExistingNumber($business);
        $this->authorizeRegistrationMutation($business);

        try {
            $this->registrations->captureDetails($business, [
                'number_type' => PhoneNumberType::Local->value,
                'country_code' => 'US',
            ]);
        } catch (MessagingRegistrationImmutableException $e) {
            // An Approved registration with no number belongs to the
            // 'number_required' state, which never links back to this
            // action; a Pending or finalizing one must not be switched.
            return $this->textMessagingError($workspaceUid, $businessUid, $e->getMessage());
        }

        // Mark local setup as active (the screen the customer is held on) —
        // a plain timestamp; nothing about the saved details changes.
        BusinessMessagingRegistration::query()->where('business_id', $business->id)->update(['local_setup_started_at' => now()]);

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Let\'s verify your business. Once approved, you\'ll choose your local number.',
        ]);
    }

    /**
     * PR #295 Correction Round 2, item 1 — legal/compliance registration is
     * a normal managed-messaging setup step for every Core/Growth Business,
     * not an Agency/BYO configuration action. manage_advanced_provider was
     * WRONG here (it defaults false and is reserved for the Advanced/BYO
     * provider surface — see MessagingChannelsController); requiring it
     * would lock ordinary customers out of finishing their own managed
     * setup. This now requires buy_numbers (the same normal number-
     * acquisition capability search/order already use) AND the canonical
     * Workspace/Account management authority (owner-or-active-admin,
     * CustomerContext::canManageWorkspace() — the exact rule already
     * gating Settings -> Team/Billing/Plan), checked by
     * authorizeRegistrationMutation() below.
     */
    public function updateRegistration(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('buy_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->authorizeRegistrationMutation($business);
        $numberType = $this->numberTypeFor($business);

        if ($numberType === null) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Get a phone number before completing registration.');
        }

        $validated = $request->validate([
            'legal_business_name' => ['required', 'string', 'max:191'],
            'doing_business_as' => ['nullable', 'string', 'max:191'],
            'additional_information' => ['nullable', 'string', 'max:300'],
            'entity_type' => ['required', 'in:sole_proprietor,ein'],
            'ein' => ['required_if:entity_type,ein', 'nullable', 'string', 'max:20'],
            'address_line_1' => ['required', 'string', 'max:191'],
            'address_line_2' => ['nullable', 'string', 'max:191'],
            'city' => ['required', 'string', 'max:120'],
            'region' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:20'],
            'website_url' => ['required', 'url', 'max:255'],
            'contact_email' => ['required', 'email', 'max:191'],
            'contact_phone' => ['required', 'string', 'max:32'],
            'support_contact' => ['nullable', 'string', 'max:191'],
            'support_contact_confirmed' => ['nullable', 'boolean'],
            'support_contact_confirmed_for' => ['nullable', 'string', 'max:191'],
            'authorize_brand_replacement' => ['nullable', 'boolean'],
            'use_case' => ['required', 'string', 'in:' . implode(',', array_keys(self::US_USE_CASES))],
            // Telnyx limits toll-free `optInWorkflow` and `useCaseSummary` to 500 characters each;
            // they are refused here rather than ever being cut off on the way to the carrier.
            'opt_in_method' => ['required', 'string', 'max:' . ($numberType === PhoneNumberType::TollFree ? RegistrationSubmissionValidator::TOLL_FREE_TEXT_LIMIT : 2000)],
            'campaign_description' => ['required', 'string', 'max:' . ($numberType === PhoneNumberType::TollFree ? RegistrationSubmissionValidator::TOLL_FREE_TEXT_LIMIT : 2000)],
            'consent_confirmations' => ['nullable', 'array'],
            'consent_confirmations.*' => ['string', \Illuminate\Validation\Rule::in(array_keys(RegistrationTemplateCatalog::CONSENT_ITEMS + RegistrationTemplateCatalog::LOW_VOLUME_CONSENT_ITEMS))],
            'message_purposes' => ['nullable', 'array'],
            'message_purposes.*' => ['string', \Illuminate\Validation\Rule::in(array_keys(\App\Library\Messaging\Registration\CarrierRegistrationOptions::LOW_VOLUME_PURPOSES))],
            // Toll-free sends only sample 1 (Telnyx allows 1,000 characters) and sample 2 is optional there.
            'sample_message_1' => ['required', 'string', 'max:' . ($numberType === PhoneNumberType::TollFree ? RegistrationSubmissionValidator::TOLL_FREE_SAMPLE_LIMIT : 500)],
            'sample_message_2' => [$numberType === PhoneNumberType::TollFree ? 'nullable' : 'required', 'string', 'max:500'],
            'privacy_policy_url' => ['required', 'url', 'max:255'],
            'terms_url' => ['required', 'url', 'max:255'],
            'contact_first_name' => ['nullable', 'string', 'max:100'],
            'contact_last_name' => ['nullable', 'string', 'max:100'],
            'message_volume' => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_map('strval', array_keys(\App\Library\Messaging\Registration\CarrierRegistrationOptions::MESSAGE_VOLUMES)))],
            'opt_in_image_url' => ['nullable', 'url', 'max:2083'],
            'brand_vertical' => ['nullable', 'string', 'in:' . implode(',', array_keys(\App\Library\Messaging\Registration\CarrierRegistrationOptions::BRAND_VERTICALS))],
        ]);

        // The SMS help contact counts only when the Business typed it AND
        // ticked the confirmation on this save; changing it un-confirms it.
        $supportContact = trim((string) ($validated['support_contact'] ?? ''));
        $previous = $this->registrationFor($business);
        $stillConfirmed = $previous?->support_contact_confirmed_at !== null && trim((string) $previous->support_contact) === $supportContact;
        $validated['support_contact'] = $supportContact === '' ? null : $supportContact;
        // A tick only counts for the exact contact it was given against
        // (the page records that value when the box is ticked). A changed
        // contact submitted with a stale tick is saved but left unconfirmed.
        $tickedFor = trim((string) ($validated['support_contact_confirmed_for'] ?? ''));
        $validated['support_contact_confirmed_at'] = $supportContact !== '' && ! empty($validated['support_contact_confirmed']) && $tickedFor === $supportContact
            ? ($stillConfirmed ? $previous->support_contact_confirmed_at : now())
            : null;
        unset($validated['support_contact_confirmed'], $validated['support_contact_confirmed_for']);

        // An unchecked box is simply absent from the POST, so the stored
        // list is always exactly what the customer ticked on this save.
        $validated['consent_confirmations'] = array_values(array_unique($validated['consent_confirmations'] ?? []));
        $validated['message_purposes'] = $validated['use_case'] === 'low_volume_mixed' ? array_values(array_unique($validated['message_purposes'] ?? [])) : null;

        // Consent attestations are about the opt-in wording on the customer's own
        // website. Moving to a type that adds promotional messages needs them
        // re-confirmed (they were made for service messages); moving to a
        // service-only type keeps the common ticks and drops only the
        // Low Volume Mixed extras that no longer apply. Nothing is ever ticked for them.
        $existing = $this->registrationFor($business);
        $useCaseChanged = $existing !== null && $existing->use_case !== null && $existing->use_case !== $validated['use_case'];
        $consentReset = false;

        if ($useCaseChanged) {
            if (! RegistrationTemplateCatalog::isCareOnly($validated['use_case'])) {
                $consentReset = $validated['consent_confirmations'] !== [];
                $validated['consent_confirmations'] = [];
            } else {
                $validated['consent_confirmations'] = array_values(array_intersect(
                    $validated['consent_confirmations'],
                    array_keys(RegistrationTemplateCatalog::CONSENT_ITEMS),
                ));
            }
        }
        $validated['number_type'] = $numberType->value;
        $validated['country_code'] = 'US';

        try {
            $this->registrations->captureDetails($business, $validated);
        } catch (MessagingRegistrationImmutableException $e) {
            return $this->textMessagingError($workspaceUid, $businessUid, $e->getMessage());
        }

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => match (true) {
                $consentReset => 'Draft saved. You changed to a messaging type that includes promotions, so the consent checklist was cleared — please re-confirm each item on your website and review your sample messages.',
                $useCaseChanged => 'Draft saved. You changed the messaging type, so please review your sample messages and campaign description.',
                default => 'Business details saved.',
            },
        ]);
    }

    /**
     * Steps back from local registration to the Local / Toll-free choice.
     * A CSRF-protected, authorized state change that only clears the
     * "in local setup" marker: every saved detail and carrier identifier is
     * kept, so reopening local setup restores it. Refused (with an
     * explanation) once the registration has a carrier commitment — it is
     * Pending or Approved — because that cannot be abandoned from here.
     */
    public function leaveLocalSetup(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('buy_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->guardNoExistingNumber($business);
        $this->authorizeRegistrationMutation($business);
        $registration = $this->registrationFor($business);

        if ($registration !== null && ($registration->status === MessagingRegistrationStatus::Pending || $registration->isApproved())) {
            return $this->textMessagingError($workspaceUid, $businessUid, $registration->isApproved()
                ? 'Your local registration is approved and was paid for, so it stays on file — continue by choosing your local number. Contact support if you need to change course.'
                : 'Your local registration has been submitted to the carrier and was paid for, so it can\'t be cancelled here. It is waiting for review; contact support if you need to change course.');
        }

        BusinessMessagingRegistration::query()->where('business_id', $business->id)->update(['local_setup_started_at' => null]);

        $toTollFree = $request->input('then') === 'toll_free';
        $carrierStarted = $registration !== null && filled($registration->provider_brand_id);

        return redirect()
            ->route('customer.workspaces.businesses.text-messaging.show', array_filter([$workspaceUid, $businessUid, 'focus' => $toTollFree ? 'toll_free' : null]))
            ->with([
                'status' => 'success',
                'message' => ($toTollFree ? 'Local setup paused — let\'s get your toll-free number instead. ' : 'Local setup paused. ')
                    . 'Your business details are saved and will be there if you come back.'
                    . ($carrierStarted ? ' Note: a carrier brand registration was already started for this business, and it stays on file.' : ''),
            ]);
    }

    public function submitRegistration(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('buy_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->authorizeRegistrationMutation($business);
        $registration = $this->registrationFor($business);

        if ($registration === null || $registration->legal_business_name === null) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Complete the business details below before submitting.');
        }

        if (! $this->provisioningOpenFor($business) || ! ProvisioningAvailability::isConfigured()) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Messaging registration is not available in this environment yet.');
        }

        $issues = $this->registrationValidator->issues($registration);

        if ($issues !== []) {
            // Local validation only: the carrier was never contacted, no fee was
            // charged and the saved draft and registration status are untouched.
            // The exact items are re-derived from the saved draft and listed on
            // the page under "Before you submit".
            return redirect(route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid]) . '#pm-submit')->with([
                'status' => 'error',
                'registration_blocked' => true,
                'message' => 'Not submitted — ' . count($issues) . ' item(s) in your saved draft need attention before this can go to the carrier (listed under "Before you submit"). Nothing was sent to the carrier and nothing was charged. Your saved details are unchanged.',
            ]);
        }

        try {
            $registration = $this->registrations->submit($registration, $request->boolean('authorize_campaign'));
        } catch (\App\Library\Messaging\Exceptions\MessagingRegistrationNotReadyException $e) {
            // Not an error: the next stage is simply not available yet. The
            // customer lands on the progress screen, which says why.
            return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
                'status' => $e->reason === \App\Library\Messaging\Exceptions\MessagingRegistrationNotReadyException::REASON_NEEDS_AUTHORIZATION ? 'error' : 'info',
                'message' => $e->getMessage(),
            ]);
        } catch (MessagingRegistrationAlreadySubmittedException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'This registration has already been submitted and is awaiting review.');
        } catch (\App\Library\Messaging\Exceptions\MessagingRegistrationNotEligibleException $e) {
            return $this->textMessagingError($workspaceUid, $businessUid, $e->getMessage());
        } catch (MessagingRegistrationRecoveryRequiredException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'We couldn\'t confirm the status of your registration with our carrier partner. Please don\'t resubmit; our team has been alerted and will follow up. Any registration fee is awaiting confirmation from the carrier: if the carrier confirms nothing was created it is refunded in full, and you will not be charged twice.');
        } catch (MessagingFundingUnavailableException $e) {
            // Platform-side configuration, never the customer's balance —
            // logged without customer data so the platform owner can act.
            \Illuminate\Support\Facades\Log::warning('Messaging registration funding unavailable.', [
                'business_id' => $business->id,
                'reason' => $e->reason,
            ]);

            return $this->textMessagingError($workspaceUid, $businessUid, $e->reason === MessagingFundingUnavailableException::REASON_WALLET_CONFIGURATION
                ? 'There is a problem with this Business\'s billing setup, so registration can\'t be submitted yet. Nothing was charged. Please contact support.'
                : 'Registration pricing hasn\'t been set up on our side yet, so this can\'t be submitted right now. Nothing was charged and your balance is not the issue. Please contact support and we\'ll finish setup.');
        } catch (MessagingInsufficientFundsException $e) {
            return $this->textMessagingError($workspaceUid, $businessUid, $this->fundingRefusalMessage($e, 'to submit this registration'));
        } catch (\App\Library\Messaging\Exceptions\MessagingRegistrationPreflightException $e) {
            // Our own check refused it before any carrier request: nothing was sent or charged.
            \Illuminate\Support\Facades\Log::warning('Messaging registration preflight refused.', [
                'business_id' => (int) $business->id,
                'failed_fields' => $e->fields,
            ]);

            return $this->textMessagingError($workspaceUid, $businessUid, 'Not submitted — we couldn\'t use these saved details: ' . implode(', ', $e->labels()) . '. Nothing was sent to the carrier and nothing was charged. Correct them, press Save draft, then submit again.');
        } catch (\App\Library\Messaging\Exceptions\MessagingRegistrationProviderException $e) {
            if ($e->step === \App\Library\Messaging\Exceptions\MessagingRegistrationProviderException::STEP_TOLL_FREE) {
                // The carrier definitively refused (HTTP 4xx): no verification request exists
                // and there is no toll-free fee. Status, error codes and rejected field names
                // are in the sanitized provider log for the operator.
                \Illuminate\Support\Facades\Log::warning('Messaging registration submission failed.', [
                    'business_id' => (int) $business->id,
                    'exception' => $e::class,
                    'step' => $e->step,
                    'http_status' => $e->httpStatus,
                    'rejected_fields' => $e->rejectedFields,
                ]);

                $ourSide = in_array($e->httpStatus, [401, 403, 404], true);

                return $this->textMessagingError($workspaceUid, $businessUid, $ourSide
                    ? 'The carrier could not accept this request because of a configuration problem on our side, not with your details. Nothing was charged and your saved details remain. Our team has been alerted; please contact support.'
                    : 'The carrier did not accept this toll-free verification request, so it was not created. Nothing was charged and your saved details remain. Check your details and opt-in link, press Save draft, and try again; if it keeps happening, contact support and we can see which item the carrier rejected.');
            }

            // The carrier partner refused a step. Operator-only detail (HTTP
            // status, code, rejected field) is already in the sanitized
            // provider log; the customer learns the step, what is saved and
            // what was charged, and is not told to fix their details.
            \Illuminate\Support\Facades\Log::warning('Messaging registration submission failed.', [
                'business_id' => (int) $business->id,
                'exception' => $e::class,
                'step' => $e->step,
            ]);

            return $this->textMessagingError($workspaceUid, $businessUid, $e->step === \App\Library\Messaging\Exceptions\MessagingRegistrationProviderException::STEP_BRAND
                ? 'Step 1 of 2 (carrier brand registration): the carrier could not accept this step. Your saved details remain, nothing was charged. You can try again later; if it keeps happening, contact support.'
                : 'Step 2 of 2 (campaign registration): the carrier could not accept this step. Your carrier brand is registered and saved'
                    . ($e->brandFeeChargedNow ? '; its fee was charged when it was created and is not refunded, but it will not be charged again when you retry' : ' and its fee will not be charged again')
                    . '. The campaign fee was not charged. Your saved details remain. You can try again later; if it keeps happening, contact support.');
        } catch (\RuntimeException $e) {
            // An internal refusal on our side (e.g. a messaging type with no
            // carrier mapping): never a raw server error, and never blamed on
            // the customer's details, which the form already validated.
            // Operator-only: class and Business id, never the message text.
            \Illuminate\Support\Facades\Log::warning('Messaging registration submission failed.', [
                'business_id' => (int) $business->id,
                'exception' => $e::class,
            ]);

            return $this->textMessagingError($workspaceUid, $businessUid, 'We couldn\'t complete this submission because of a problem on our side, not with your details. Your details are saved. Please check Usage & Billing for any charge before retrying, and contact support if it keeps happening.');
        }

        // Always land on the progress screen, never back on an editable form.
        $campaignCreated = trim((string) $registration->provider_campaign_id) !== '';

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => $registration->number_type === PhoneNumberType::Local && ! $campaignCreated
                ? 'Business verification submitted. The carrier is now verifying your business; we\'ll show your progress here and unlock the campaign step when it\'s verified.'
                : ($registration->number_type === PhoneNumberType::Local
                    ? 'Campaign submitted — awaiting carrier review. This is typically reviewed within a few business days.'
                    : 'Registration submitted. This is typically reviewed within a few business days.'),
        ]);
    }

    /**
     * Safe "Refresh status": asks the carrier about the saved brand/campaign
     * once (rate-limited by the route and by a 60-second floor), changes
     * nothing but the recorded status, and never charges or resubmits.
     */
    public function refreshRegistrationProgress(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('view_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $registration = $this->registrationFor($business);

        $back = redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid]);

        if ($registration === null || ! ProvisioningAvailability::isConfigured()) {
            return $back->with(['status' => 'info', 'message' => 'There is nothing to refresh yet.']);
        }

        $last = $registration->brand_status_checked_at;
        $last = trim((string) $registration->provider_campaign_id) !== '' ? $registration->campaign_status_checked_at : $last;

        if ($last !== null && $last->gt(now()->subSeconds(60))) {
            return $back->with(['status' => 'info', 'message' => 'Status was checked moments ago. Please try again in a minute.']);
        }

        $before = [(string) $registration->brand_status_checked_at, (string) $registration->campaign_status_checked_at];

        try {
            $registration = $this->registrations->refreshStatus($registration);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Messaging registration status refresh failed.', ['business_id' => (int) $business->id, 'exception' => $e::class]);

            return $back->with(['status' => 'info', 'message' => 'We couldn\'t reach the carrier just now. Your saved progress is unchanged; please try again shortly.']);
        }

        // Only a conclusive carrier answer stamps a check time; anything else
        // (outage, rate limit, unreadable answer) changed nothing.
        $registration = $registration->fresh();
        $answered = (string) $registration->brand_status_checked_at !== $before[0] || (string) $registration->campaign_status_checked_at !== $before[1];

        return $answered
            ? $back->with(['status' => 'success', 'message' => 'Checked with the carrier just now. The status below is current.'])
            : $back->with(['status' => 'info', 'message' => 'The carrier didn\'t give a clear answer just now, so your saved status is unchanged. Please try again shortly.']);
    }

    // -----------------------------------------------------------------
    // STATE 3 — Delivery & usage.
    // -----------------------------------------------------------------

    /**
     * A fixed, unconfigurable last-30-days window — deliberately simpler
     * than Results' own range picker (AnalyticsDateRange/_range.blade.php)
     * for this first cut of Delivery & usage; reusing the same presenter
     * method Results already calls means this costs no extra query and
     * shows the exact same figures Results would have shown for that
     * window.
     */
    public function deliveryUsage(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('view_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        $range = AnalyticsDateRange::fromInput([], (string) ($business->timezone ?: config('app.timezone', 'UTC')));
        $analytics = $this->analyticsPresenter->buildOverview($business, $range);

        return view('customer.settings.text-messaging.delivery-usage', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'messages' => $analytics->messages,
            'messageVolume' => $analytics->messageVolume,
            'range' => $range,
        ]);
    }

    // -----------------------------------------------------------------
    // STATE 3 — port a number out (messaging contract §13.4). Records and
    // tracks the request only; never releases, replaces, transfers or
    // purchases a number, and never calls Telnyx.
    // -----------------------------------------------------------------

    /**
     * §13.4: "A customer may port a number out. The platform must not
     * obstruct it. Porting out is a supported, documented request path,
     * not a support escalation." — including when registration is
     * pending/rejected or the number is suspended (§13.3: suspension
     * "stops new paid outbound while retaining the number"). Authorized on
     * the customer's own authority over the Business (owner-or-active-admin,
     * the same authorizeRegistrationMutation() gate registration mutation
     * already uses) rather than buy_numbers — porting OUT is not a
     * number-acquisition action, and must never depend on permission to buy
     * a new one. view_numbers is the same baseline read capability
     * show()/deliveryUsage() already require on this exact resource.
     *
     * Review correction — a Business may retain more than one number
     * (§4.2's one-to-many schema), so the customer selects which one; the
     * posted number_id is a plain identifier, never trusted alone. Both
     * this method AND PortOutRequestManager::request() independently
     * re-verify, at the mutation boundary, that the number both belongs to
     * this Business and is currently portable (Active or Suspended, never
     * Pending — not yet an actually acquired number — or Released).
     */
    public function requestPortOut(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('view_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->authorizeRegistrationMutation($business);

        $validated = $request->validate(['number_id' => ['required', 'integer']]);
        $number = $this->portOutRequests->retainedNumberFor($business, (int) $validated['number_id']);

        if ($number === null) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'That number is not available on this Business to port out.');
        }

        try {
            $this->portOutRequests->request($business, $number, (int) Auth::id());
        } catch (PortOutRequestAlreadyActiveException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'A port-out request for this number is already in progress.');
        } catch (MessagingIdentityConflictException|PortOutRequestNumberNotPortableException) {
            // The mutation-boundary re-check inside request() itself caught
            // what retainedNumberFor() above should already have refused —
            // never reachable through this controller's own lookup, but the
            // manager never trusts a caller-supplied pairing regardless.
            return $this->textMessagingError($workspaceUid, $businessUid, 'That number is not available on this Business to port out.');
        }

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Your request to port this number out has been received. Our team will follow up with next steps.',
        ]);
    }

    public function cancelPortOutRequest(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('view_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->authorizeRegistrationMutation($business);

        $validated = $request->validate(['number_id' => ['required', 'integer']]);
        $number = $this->portOutRequests->retainedNumberFor($business, (int) $validated['number_id']);
        $portOutRequest = $number !== null ? $this->portOutRequests->activeRequestFor($number) : null;

        // Belt-and-braces cross-tenant guard: even though retainedNumberFor()
        // already scoped $number to this Business, refuse to cancel
        // anything that does not resolve back to it a second, independent
        // way.
        if ($portOutRequest === null || (int) $portOutRequest->business_id !== (int) $business->id) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'There is no active port-out request to cancel for that number.');
        }

        $this->portOutRequests->cancel((int) $portOutRequest->id, (int) Auth::id());

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Port-out request cancelled.',
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{state: string, phoneNumber: ?string, textingAvailable: bool, mediaAvailable: bool, registration: ?BusinessMessagingRegistration, campaignAssignmentStatus: ?string, campaignAssignmentFailureReason: ?string, retainedNumbers: Collection<int, BusinessMessagingNumber>, portOutRequestsByNumberId: array<int, ?BusinessMessagingNumberPortOutRequest>}
     */
    private function situation(Business $business): array
    {
        // The one read of the messaging situation now lives in MessagingReadinessReader
        // (shared with Agency Outreach readiness); behaviour is unchanged.
        return $this->readiness->situation($business);
    }

    /**
     * Shared by situation() and searchNumber() (which renders the same
     * "no active number" view directly, outside the show()/situation()
     * path).
     *
     * @return array{retainedNumbers: Collection<int, BusinessMessagingNumber>, portOutRequestsByNumberId: array<int, ?BusinessMessagingNumberPortOutRequest>}
     */
    private function portOutContextFor(Business $business): array
    {
        return $this->readiness->portOutContextFor($business);
    }

    private function registrationFor(Business $business): ?BusinessMessagingRegistration
    {
        return $this->readiness->registrationFor($business);
    }

    /**
     * PR #295 Correction Round 2, item 1 — legal/compliance registration
     * is charge-incurring and legally binding, so beyond ordinary
     * buy_numbers (already checked by the caller) it further requires the
     * SAME owner-or-active-admin authority Settings -> Team/Billing/Plan
     * already reuse for exactly this kind of account-level decision — not
     * merely view_numbers, and never manage_advanced_provider (that
     * permission is reserved for the separate Agency/BYO provider
     * surface, MessagingChannelsController, and is not checked here at
     * all). A Workspace member who is neither the owner nor an active
     * Admin is refused even if they otherwise hold buy_numbers.
     */
    private function authorizeRegistrationMutation(Business $business): void
    {
        $context = $this->shell->currentContext(Auth::user());

        if ($context->selectedBusiness === null
            || $context->selectedBusiness->uid !== $business->uid
            || ! $context->canManageWorkspace()) {
            throw new AuthorizationException('Only the Business owner or an authorized account manager may manage messaging registration.');
        }
    }

    private function numberTypeFor(Business $business): ?PhoneNumberType
    {
        $number = $this->primaryNumberFor($business);

        if ($number !== null) {
            return $number->number_type;
        }

        // Review correction — a Business may already be mid local (10DLC)
        // business verification with no number yet (Telnyx genuinely
        // supports this sequence for local numbers); toll-free's own
        // verification can never reach here without a number, since its
        // submission requires one already.
        $registration = $this->registrationFor($business);

        return $registration?->number_type === PhoneNumberType::Local ? PhoneNumberType::Local : null;
    }

    /**
     * Whether this Business already has an Approved local (10DLC)
     * business verification on file — the one condition under which the
     * verify-first sequence allows searching for or ordering a local
     * number with no number/identity yet. Never true for toll-free: its
     * own carrier verification always requires an already-owned number.
     */
    private function hasApprovedLocalRegistration(Business $business): bool
    {
        $registration = $this->registrationFor($business);

        return $registration !== null
            && $registration->number_type === PhoneNumberType::Local
            && $registration->isApproved();
    }

    private function primaryNumberFor(Business $business): ?BusinessMessagingNumber
    {
        $identity = $this->identities->resolveForBusiness($business);

        if ($identity === null) {
            return null;
        }

        try {
            return $this->identities->resolvePrimaryNumber($identity);
        } catch (MessagingIdentityConflictException) {
            return null;
        }
    }

    /**
     * A Business that already has a number never reaches the search/order
     * actions again — STATE 1 is a one-way door, not a "replace my
     * number" surface (out of scope here; see the target's own "future
     * number replacement/release actions if supported").
     */
    private function guardNoExistingNumber(Business $business): void
    {
        abort_if($this->identities->resolveForBusiness($business) !== null, 404);
    }

    /**
     * @param  array{state: string, phoneNumber: ?string, textingAvailable: bool, mediaAvailable: bool, registration: ?BusinessMessagingRegistration, campaignAssignmentStatus: ?string, campaignAssignmentFailureReason: ?string, retainedNumbers: Collection<int, BusinessMessagingNumber>, portOutRequestsByNumberId: array<int, ?BusinessMessagingNumberPortOutRequest>}  $situation
     */
    /** Real provisioning is pilot-restricted: configured AND this Business is enabled. */
    private function provisioningOpenFor(Business $business): bool
    {
        return ProvisioningPilot::allows($business) && $this->provisioning->isAvailable();
    }

    private function renderNoNumber(string $workspaceUid, string $businessUid, array $situation, ?PhoneNumberType $numberTypeLocked = null): View
    {
        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        return view('customer.settings.text-messaging.states.no-number', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'available' => $this->provisioningOpenFor($business),
            'searched' => false,
            'criteria' => [],
            'candidate' => null,
            'candidateOffers' => [],
            'candidateToken' => null,
            // A recently failed (carrier-rejected) order is explained once here, never silently dropped.
            'failedOrder' => \App\Models\BusinessMessagingNumberOrder::query()
                ->where('business_id', (int) $business->id)->where('status', \App\Models\BusinessMessagingNumberOrder::FAILED)
                ->where('updated_at', '>=', now()->subDay())->orderByDesc('id')->first(),
            'verificationChargeDisclosure' => $this->registrations->chargeDisclosureFor(PhoneNumberType::Local),
            'numberPreview' => $this->charges->forNumberPurchase($business),
            'localRegistrationPreview' => $this->charges->forLocalRegistration($business),
            'lowVolumeRegistrationPreview' => $this->charges->forLocalRegistration($business, 'low_volume_mixed'),
            // Review correction — non-null only for the verify-first local
            // sequence's "your business is verified, now choose your
            // number" render: the view hides the toll-free/local choice
            // entirely and shows a verified banner instead.
            'numberTypeLocked' => $numberTypeLocked,
            'registration' => $situation['registration'] ?? $this->registrationFor($business),
            // Review correction — a Business can land in this "no active
            // number" classification while still retaining a Suspended
            // number (Slice 3's own resolveForBusiness()/resolvePrimaryNumber()
            // only recognize an Active identity/number); the port-out exit
            // path must stay reachable, for every retained number, regardless.
            'retainedNumbers' => $situation['retainedNumbers'],
            'portOutRequestsByNumberId' => $situation['portOutRequestsByNumberId'],
        ]);
    }

    /**
     * @param  array{state: string, phoneNumber: ?string, textingAvailable: bool, mediaAvailable: bool, registration: ?BusinessMessagingRegistration, campaignAssignmentStatus: ?string, campaignAssignmentFailureReason: ?string, retainedNumbers: Collection<int, BusinessMessagingNumber>, portOutRequestsByNumberId: array<int, ?BusinessMessagingNumberPortOutRequest>}  $situation
     */
    private function renderRegistration(string $workspaceUid, string $businessUid, array $situation, Business $business): View
    {
        return view('customer.settings.text-messaging.states.registration', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'phoneNumber' => $situation['phoneNumber'],
            // Never null in the view — a Business reaching this state
            // with no captured details yet still gets a real (unsaved)
            // model, so the form can read every field the same way
            // whether this is the first visit or the tenth.
            'registration' => $registration = $situation['registration'] ?? new BusinessMessagingRegistration(['status' => MessagingRegistrationStatus::NotStarted->value]),
            // The owned number decides the regime shown; a saved row may predate it.
            'formNumberType' => $this->numberTypeFor($business) ?? ($registration->number_type ?? PhoneNumberType::Local),
            'messageVolumes' => \App\Library\Messaging\Registration\CarrierRegistrationOptions::MESSAGE_VOLUMES,
            'brandVerticals' => \App\Library\Messaging\Registration\CarrierRegistrationOptions::BRAND_VERTICALS,
            'useCases' => self::US_USE_CASES,
            'useCaseChoices' => RegistrationTemplateCatalog::CHOICES,
            'selectedChoice' => RegistrationTemplateCatalog::choiceForUseCase($registration->use_case),
            'consentItems' => RegistrationTemplateCatalog::CONSENT_ITEMS,
            'lowVolumeConsentItems' => RegistrationTemplateCatalog::LOW_VOLUME_CONSENT_ITEMS,
            'lowVolumePurposes' => \App\Library\Messaging\Registration\CarrierRegistrationOptions::LOW_VOLUME_PURPOSES,
            'prefill' => $this->registrationDefaults->resolve($business, $situation['registration'] ?? null),
            'templateBrand' => $this->registrationDefaults->brandName($business, $situation['registration'] ?? null),
            'templatesByChoice' => $this->registrationDefaults->templatesByChoice($business, $situation['registration'] ?? null),
            'submissionIssues' => $registration->exists ? $this->registrationValidator->issues($registration) : [],
            'available' => $this->provisioningOpenFor($business) && ProvisioningAvailability::isConfigured(),
            'verificationChargeDisclosure' => $this->registrations->chargeDisclosureFor($registration->number_type ?? PhoneNumberType::Local),
            'registrationPreview' => $this->charges->forLocalRegistration($business, $registration->use_case),
            'progress' => \App\Library\Messaging\Registration\RegistrationProgress::for($registration),
            'registrationPreviews' => ['standard' => $this->charges->forLocalRegistration($business), 'low_volume_mixed' => $this->charges->forLocalRegistration($business, 'low_volume_mixed')],
            // Review correction — an Approved registration with a genuine
            // number can still be stuck here, never Ready, while a local
            // number's own carrier-side campaign assignment has not yet
            // been Confirmed (or has Failed) — this is the only render
            // that ever shows that durable state to the customer.
            'campaignAssignmentStatus' => $situation['campaignAssignmentStatus'] ?? null,
            'campaignAssignmentFailureReason' => $situation['campaignAssignmentFailureReason'] ?? null,
            // Review correction — §13.4's exit path must be reachable while
            // registration is pending/rejected, not only once "ready".
            'retainedNumbers' => $situation['retainedNumbers'],
            'portOutRequestsByNumberId' => $situation['portOutRequestsByNumberId'],
        ]);
    }

    /**
     * @param  array{state: string, phoneNumber: ?string, textingAvailable: bool, mediaAvailable: bool, registration: ?BusinessMessagingRegistration, campaignAssignmentStatus: ?string, campaignAssignmentFailureReason: ?string, retainedNumbers: Collection<int, BusinessMessagingNumber>, portOutRequestsByNumberId: array<int, ?BusinessMessagingNumberPortOutRequest>}  $situation
     */
    /**
     * Which Locations use this Business's number.
     *
     * The number is Business-level; a workflow limited to Locations can only text
     * through it once the owner says which Locations it speaks for. Changing that
     * changes what the Business sends as, so it takes the same owner-or-account-manager
     * authority as registration, AND reach of every Location (a person who sees only
     * some Locations cannot decide for the rest). Locations are chosen by uid and
     * resolved inside this Business: another Business's uid is simply not found.
     */
    public function updateNumberLocations(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('view_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->authorizeRegistrationMutation($business);

        $locationAuthority = app(\App\Library\Automation\Workflow\WorkflowLocationAuthority::class);

        if (! $locationAuthority->hasFullReach((int) Auth::id(), $business)) {
            throw new AuthorizationException('Only someone with access to every location can choose which locations use the number.');
        }

        $validated = $request->validate([
            'location_uids' => ['nullable', 'array', 'max:100'],
            'location_uids.*' => ['string', 'max:64'],
        ]);

        $number = $this->primaryNumberFor($business);

        if ($number === null) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Add a phone number first.');
        }

        $uids = array_values(array_unique($validated['location_uids'] ?? []));
        $locations = \App\Models\BusinessLocation::query()
            ->where('business_id', (int) $business->id)
            ->whereIn('uid', $uids)
            ->get(['id', 'uid']);

        if ($locations->count() !== count($uids)) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'One of those locations could not be found.');
        }

        try {
            $this->identities->assignLocations($number, $business, $locations->pluck('id')->map(fn ($id): int => (int) $id)->all());
        } catch (\InvalidArgumentException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Choose active locations of this business.');
        }

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => $locations->isEmpty()
                ? 'Your number is no longer limited to particular locations.'
                : 'Saved. Your number is now used by the locations you chose.',
        ]);
    }

    private function renderReady(string $workspaceUid, string $businessUid, array $situation): View
    {
        $business = $this->resolveBusinessTenancy($workspaceUid, $businessUid)[1];
        $number = $this->primaryNumberFor($business);
        $locations = \App\Models\BusinessLocation::query()
            ->where('business_id', (int) $business->id)
            ->where('lifecycle_state', \App\Enums\Business\BusinessLocationLifecycleState::Active->value)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'uid', 'name']);
        $assigned = $number === null ? [] : $this->identities->assignedLocationIds($number);

        return view('customer.settings.text-messaging.states.ready', [
            // Shown only when there is a real choice to make (several Locations) and
            // the person may make it (owner / account manager with full reach).
            'locationChoices' => $locations->count() > 1 && $number !== null
                && app(\App\Library\Automation\Workflow\WorkflowLocationAuthority::class)->hasFullReach((int) Auth::id(), $business)
                && $this->shell->currentContext(Auth::user())->canManageWorkspace()
                ? $locations->map(fn ($location): array => [
                    'uid' => (string) $location->uid,
                    'name' => (string) ($location->name ?: 'Unnamed location'),
                    'checked' => in_array((int) $location->id, $assigned, true),
                ])->all()
                : [],
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'phoneNumber' => $situation['phoneNumber'],
            'textingAvailable' => $situation['textingAvailable'],
            'mediaAvailable' => $situation['mediaAvailable'],
            'retainedNumbers' => $situation['retainedNumbers'],
            'portOutRequestsByNumberId' => $situation['portOutRequestsByNumberId'],
        ]);
    }

    private function fundingRefusalMessage(MessagingInsufficientFundsException $e, string $purpose): string
    {
        $reason = $e->denialReason;

        if ($reason === null || $reason === 'insufficient_balance') {
            return 'Your Business doesn\'t have enough funds ' . $purpose . '. Add funds to your Business balance and try again.';
        }

        return app(\App\Library\Usage\UsageWalletManager::class)->customerMessageForDenial($reason);
    }

    private function textMessagingError(string $workspaceUid, string $businessUid, string $message): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'error',
            'message' => $message,
        ]);
    }
}
