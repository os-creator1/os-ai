<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Messaging\MessagingEntityType;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\BusinessMessagingProvisioningService;
use App\Library\Messaging\BusinessMessagingRegistrationService;
use App\Library\Messaging\CandidateToken;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\Exceptions\InvalidCandidateTokenException;
use App\Library\Messaging\Exceptions\MessagingFundingUnavailableException;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\MessagingInsufficientFundsException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\Exceptions\MessagingRegistrationImmutableException;
use App\Library\Messaging\Exceptions\PortOutRequestAlreadyActiveException;
use App\Library\Messaging\PortOutRequestManager;
use App\Library\Messaging\ProvisioningAvailability;
use App\Library\Navigation\CustomerShellComposer;
use App\Models\Business;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingNumberPortOutRequest;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
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
        'mixed' => 'A mix of the above',
    ];

    public function __construct(
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly BusinessMessagingProvisioningService $provisioning,
        private readonly BusinessMessagingRegistrationService $registrations,
        private readonly BusinessAnalyticsPresenter $analyticsPresenter,
        private readonly CustomerShellComposer $shell,
        private readonly PortOutRequestManager $portOutRequests,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('view_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        $situation = $this->situation($business);

        return match ($situation['state']) {
            'ready' => $this->renderReady($workspaceUid, $businessUid, $situation),
            'registration_required' => $this->renderRegistration($workspaceUid, $businessUid, $situation),
            default => $this->renderNoNumber($workspaceUid, $businessUid),
        };
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
    public function searchNumber(Request $request, string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('buy_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->guardNoExistingNumber($business);

        $validated = $request->validate([
            'number_type' => ['required', 'in:local,toll_free'],
            'area_code' => ['nullable', 'digits:3'],
        ]);

        $criteria = new NumberSearchCriteria(
            countryCode: 'US',
            numberType: PhoneNumberType::from($validated['number_type']),
            areaCode: $validated['area_code'] ?? null,
        );

        $candidates = $this->provisioning->searchNumbers($criteria);
        $candidate = $candidates[0] ?? null;

        return view('customer.settings.text-messaging.states.no-number', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'available' => $this->provisioning->isAvailable(),
            'searched' => true,
            'criteria' => $validated,
            'candidate' => $candidate,
            // PR #295 Correction Round 1, item 2 — the browser never gets
            // the raw phone_number/provider_candidate_reference/number_type
            // fields back as independently-editable form inputs; it only
            // ever gets this one opaque, short-lived, Business-bound token.
            'candidateToken' => $candidate !== null ? CandidateToken::encode($business, $candidate) : null,
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

        if (! $this->provisioning->isAvailable()) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Number setup is not available in this environment yet.');
        }

        $validated = $request->validate([
            'candidate_token' => ['required', 'string'],
        ]);

        try {
            $candidate = CandidateToken::decode($validated['candidate_token'], $business);
        } catch (InvalidCandidateTokenException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'This number is no longer available. Please search again.');
        }

        try {
            $this->provisioning->provisionNumber($business, $candidate);
        } catch (MessagingProviderNotConfiguredException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Number setup is not available in this environment yet.');
        } catch (MessagingIdentityConflictException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'This Business already has a number set up.');
        } catch (MessagingFundingUnavailableException|MessagingInsufficientFundsException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Number setup is not available in this environment yet.');
        }

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Phone number added. Next, complete messaging registration so texts deliver reliably.',
        ]);
    }

    // -----------------------------------------------------------------
    // STATE 2 — registration required.
    // -----------------------------------------------------------------

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
            'use_case' => ['required', 'string', 'in:' . implode(',', array_keys(self::US_USE_CASES))],
            'opt_in_method' => ['required', 'string', 'max:2000'],
            'sample_message_1' => ['required', 'string', 'max:500'],
            'sample_message_2' => ['required', 'string', 'max:500'],
            'privacy_policy_url' => ['required', 'url', 'max:255'],
            'terms_url' => ['required', 'url', 'max:255'],
        ]);

        $validated['number_type'] = $numberType->value;
        $validated['country_code'] = 'US';

        try {
            $this->registrations->captureDetails($business, $validated);
        } catch (MessagingRegistrationImmutableException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'This registration has already been approved and can no longer be edited.');
        }

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Business details saved.',
        ]);
    }

    public function submitRegistration(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('buy_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->authorizeRegistrationMutation($business);
        $registration = $this->registrationFor($business);

        if ($registration === null || $registration->legal_business_name === null) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Complete the business details below before submitting.');
        }

        if (! ProvisioningAvailability::isConfigured()) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Messaging registration is not available in this environment yet.');
        }

        $this->registrations->submit($registration);

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Registration submitted. This is typically reviewed within a few business days.',
        ]);
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
     * not a support escalation." Authorized exactly like registration
     * mutation (buy_numbers + owner-or-active-admin, item's own precedent
     * in authorizeRegistrationMutation()) because leaving is at least as
     * consequential a decision as registering. The number is always
     * resolved server-side from this Business's own active identity — the
     * request never accepts a number id from the client, so there is no
     * input that could name another Business's number.
     */
    public function requestPortOut(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('buy_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->authorizeRegistrationMutation($business);

        $number = $this->primaryNumberFor($business);

        if ($number === null) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'Get a phone number before requesting a port-out.');
        }

        try {
            $this->portOutRequests->request($business, $number, (int) Auth::id());
        } catch (PortOutRequestAlreadyActiveException) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'A port-out request for this number is already in progress.');
        }

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Your request to port this number out has been received. Our team will follow up with next steps.',
        ]);
    }

    public function cancelPortOutRequest(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('buy_numbers');

        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->authorizeRegistrationMutation($business);

        $number = $this->primaryNumberFor($business);
        $request = $number !== null ? $this->portOutRequests->activeRequestFor($number) : null;

        // Belt-and-braces cross-tenant guard: even though the request id
        // itself is never accepted from the client, refuse to cancel
        // anything that does not resolve back to THIS Business's own
        // number.
        if ($request === null || (int) $request->business_id !== (int) $business->id) {
            return $this->textMessagingError($workspaceUid, $businessUid, 'There is no active port-out request to cancel.');
        }

        $this->portOutRequests->cancel((int) $request->id, (int) Auth::id());

        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Port-out request cancelled.',
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{state: string, phoneNumber: ?string, textingAvailable: bool, mediaAvailable: bool, registration: ?BusinessMessagingRegistration, portOutRequest: ?BusinessMessagingNumberPortOutRequest}
     */
    private function situation(Business $business): array
    {
        $identity = $this->identities->resolveForBusiness($business);
        $registration = $this->registrationFor($business);

        if ($identity === null) {
            return ['state' => 'no_number', 'phoneNumber' => null, 'textingAvailable' => false, 'mediaAvailable' => false, 'registration' => $registration, 'portOutRequest' => null];
        }

        try {
            $number = $this->identities->resolvePrimaryNumber($identity);
        } catch (MessagingIdentityConflictException) {
            return ['state' => 'no_number', 'phoneNumber' => null, 'textingAvailable' => false, 'mediaAvailable' => false, 'registration' => $registration, 'portOutRequest' => null];
        }

        $ready = $number->isActive() && $registration !== null && $registration->isApproved();

        return [
            'state' => $ready ? 'ready' : 'registration_required',
            'phoneNumber' => $number->phone_number,
            'textingAvailable' => $ready,
            'mediaAvailable' => $ready,
            'registration' => $registration,
            'portOutRequest' => $this->portOutRequests->activeRequestFor($number),
        ];
    }

    private function registrationFor(Business $business): ?BusinessMessagingRegistration
    {
        return BusinessMessagingRegistration::query()->where('business_id', $business->id)->first();
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

        return $number?->number_type;
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

    private function renderNoNumber(string $workspaceUid, string $businessUid): View
    {
        return view('customer.settings.text-messaging.states.no-number', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'available' => $this->provisioning->isAvailable(),
            'searched' => false,
            'criteria' => [],
            'candidate' => null,
            'candidateToken' => null,
        ]);
    }

    /**
     * @param  array{state: string, phoneNumber: ?string, textingAvailable: bool, mediaAvailable: bool, registration: ?BusinessMessagingRegistration}  $situation
     */
    private function renderRegistration(string $workspaceUid, string $businessUid, array $situation): View
    {
        return view('customer.settings.text-messaging.states.registration', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'phoneNumber' => $situation['phoneNumber'],
            // Never null in the view — a Business reaching this state
            // with no captured details yet still gets a real (unsaved)
            // model, so the form can read every field the same way
            // whether this is the first visit or the tenth.
            'registration' => $situation['registration'] ?? new BusinessMessagingRegistration(['status' => MessagingRegistrationStatus::NotStarted->value]),
            'useCases' => self::US_USE_CASES,
            'available' => ProvisioningAvailability::isConfigured(),
        ]);
    }

    /**
     * @param  array{state: string, phoneNumber: ?string, textingAvailable: bool, mediaAvailable: bool, registration: ?BusinessMessagingRegistration, portOutRequest: ?BusinessMessagingNumberPortOutRequest}  $situation
     */
    private function renderReady(string $workspaceUid, string $businessUid, array $situation): View
    {
        return view('customer.settings.text-messaging.states.ready', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'phoneNumber' => $situation['phoneNumber'],
            'textingAvailable' => $situation['textingAvailable'],
            'mediaAvailable' => $situation['mediaAvailable'],
            'portOutRequest' => $situation['portOutRequest'],
        ]);
    }

    private function textMessagingError(string $workspaceUid, string $businessUid, string $message): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid])->with([
            'status' => 'error',
            'message' => $message,
        ]);
    }
}
