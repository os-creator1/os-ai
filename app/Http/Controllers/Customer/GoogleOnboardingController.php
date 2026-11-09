<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\Business\GoogleOnboardingUnavailableException;
use App\Exceptions\Entitlement\BusinessSlotAllocationRequiredException;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Exceptions\Entitlement\InactiveWorkspacePlanException;
use App\Exceptions\Entitlement\SuspendedWorkspacePlanException;
use App\Exceptions\Entitlement\WorkspacePlanUnassignedException;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileConcurrencyException;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileConfigurationException;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Http\Requests\Business\GoogleOnboardingConfirmRequest;
use App\Http\Requests\Business\GoogleOnboardingStartRequest;
use App\Library\Business\GoogleOnboardingBridge;
use App\Library\Business\GoogleOnboardingPreview;
use App\Library\Business\OnboardingManager;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * The Google Business Profile quick start inside onboarding: connect, choose
 * the profile you manage, review an editable listing card, confirm.
 *
 * Every action is a thin shell around GoogleOnboardingBridge. Every failure —
 * no profiles, cancelled or failed authorization, a disconnected or revoked
 * account, an expired preview, an unavailable API, a plan without Google —
 * ends at the ordinary onboarding screen, where manual setup always works.
 * Nothing here writes to Google or activates the Business.
 */
class GoogleOnboardingController extends CustomerBaseController
{
    /** The sealed preview snapshot (ciphertext only) for this session's current import. */
    private const SESSION_SNAPSHOT = 'onboarding.google.snapshot';

    private const MANUAL_HINT = ' You can also set up your business manually.';

    public function __construct(
        private readonly OnboardingManager $onboarding,
        private readonly GoogleOnboardingBridge $bridge,
        private readonly GoogleOnboardingPreview $preview,
    ) {
    }

    /** Authorization finished (or "Choose another profile"): the profiles this Google account manages. */
    public function show(): View|RedirectResponse
    {
        $this->authorize('manage_google_business_profile');

        [$onboarding, $customer, $business, $connection] = $this->context();

        if ($business === null) {
            return $this->toManual();
        }

        if ($connection === null || ! $connection->isActive()) {
            return $this->toManual('Google is not connected yet.' . self::MANUAL_HINT);
        }

        try {
            $listing = $this->bridge->offers($business, $connection, (int) $customer->user_id, $this->onboarding->googleAddressConsent($onboarding));
        } catch (GoogleBusinessProfileProviderException $exception) {
            return $this->toManual($exception->userMessage() . self::MANUAL_HINT);
        }

        request()->session()->forget(self::SESSION_SNAPSHOT);

        return view('customer.onboarding.google.select', [
            'accounts' => $listing['accounts'],
            'offers' => $listing['offers'],
        ]);
    }

    /** "Connect Google Business Profile". */
    public function connect(GoogleOnboardingStartRequest $request): RedirectResponse
    {
        $this->authorize('manage_google_business_profile');

        $customer = $this->customer();
        $onboarding = $this->onboarding->start($customer);

        try {
            $url = $this->bridge->begin($customer, $onboarding, [
                'country_code' => $request->validated('country_code'),
                'timezone' => $request->validated('timezone'),
                'currency_code' => $request->validated('currency_code'),
            ], (bool) $request->validated('address_consent'));
        } catch (GoogleOnboardingUnavailableException $exception) {
            return $this->toManual($exception->getMessage());
        } catch (GoogleBusinessProfileConfigurationException $exception) {
            // The exact setting name is an operator diagnostic, logged, never customer-visible.
            Log::error('Google Business Profile configuration error.', [
                'reason' => $exception->reason,
                'operator_message' => $exception->operatorMessage(),
            ]);

            return $this->toManual($exception->customerMessage() . self::MANUAL_HINT);
        } catch (GoogleBusinessProfileConcurrencyException $exception) {
            return $this->toManual($exception->userMessage());
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('customer.onboarding.show')->withInput()->withErrors(['onboarding' => $exception->getMessage()]);
        } catch (WorkspacePlanUnassignedException
               | InactiveWorkspacePlanException
               | SuspendedWorkspacePlanException
               | BusinessSlotAllocationRequiredException
               | BusinessSlotLimitExceededException) {
            return $this->toManual("We can't create your business with the current account setup. Please contact support for help.");
        } catch (LogicException) {
            return $this->toManual('Google could not be connected right now.' . self::MANUAL_HINT);
        }

        // Already connected: straight to choosing a profile.
        return $url === null ? redirect()->route('customer.onboarding.google.show') : redirect()->away($url);
    }

    /** The customer explicitly chose one profile. Read it (read-only, nothing persisted) and show the card. */
    public function select(Request $request): RedirectResponse
    {
        $this->authorize('manage_google_business_profile');

        $token = $request->validate(['candidate_token' => ['required', 'string', 'max:2000']])['candidate_token'];

        [$onboarding, $customer, $business, $connection] = $this->context();

        if ($business === null || $connection === null || ! $connection->isActive()) {
            return $this->toManual('Google is not connected.' . self::MANUAL_HINT);
        }

        try {
            $imported = $this->bridge->importSelection($token, $business, $connection, (int) $customer->user_id, $this->onboarding->googleAddressConsent($onboarding));
        } catch (GoogleBusinessProfileProviderException $exception) {
            return redirect()->route('customer.onboarding.google.show')->withErrors(['onboarding' => $exception->userMessage()]);
        }

        if ($imported === null) {
            return redirect()->route('customer.onboarding.google.show')
                ->withErrors(['onboarding' => 'That selection is no longer valid. Choose your profile again.']);
        }

        $request->session()->put(self::SESSION_SNAPSHOT, $imported['token']);

        return redirect()->route('customer.onboarding.google.review');
    }

    /** The editable business listing card. */
    public function review(Request $request): View|RedirectResponse
    {
        $this->authorize('manage_google_business_profile');

        [$onboarding, $customer, $business, $connection] = $this->context();

        $snapshot = $this->openSnapshot($request, $business, $connection, $customer);

        if ($snapshot === null) {
            $request->session()->forget(self::SESSION_SNAPSHOT);

            return redirect()->route('customer.onboarding.google.show')
                ->withErrors(['onboarding' => 'That import has expired. Choose your profile again.']);
        }

        return view('customer.onboarding.google.preview', [
            'card' => $this->preview->build($snapshot, $business, $onboarding, $request->old()),
            'business' => $business,
            'snapshotToken' => (string) $request->session()->get(self::SESSION_SNAPSHOT),
            'websiteChoice' => $request->old('website_choice', $this->defaultWebsiteChoice($snapshot, $onboarding)),
            'publicAddress' => (bool) $request->old('public_address', $business->locations()->where('is_primary', true)->value('public_address')),
        ]);
    }

    /** The customer approved the card: save it through the canonical onboarding services. */
    public function confirm(GoogleOnboardingConfirmRequest $request): RedirectResponse
    {
        $this->authorize('manage_google_business_profile');

        [$onboarding, $customer, $business, $connection] = $this->context();

        if ($this->openSnapshot($request, $business, $connection, $customer) === null) {
            $request->session()->forget(self::SESSION_SNAPSHOT);

            return redirect()->route('customer.onboarding.google.show')
                ->withErrors(['onboarding' => 'That import has expired. Choose your profile again.']);
        }

        try {
            $onboarding = $this->bridge->confirm($onboarding, $customer, $request->validated());
        } catch (InvalidArgumentException) {
            // A canonical step refused the values: continue wherever the customer really is, manually.
            return redirect()
                ->route('customer.onboarding.show', ['step' => $this->onboarding->resolveStep($onboarding->refresh())->value])
                ->withErrors(['onboarding' => 'We could not save everything from that card. Please check this step.']);
        } catch (WorkspacePlanUnassignedException
               | InactiveWorkspacePlanException
               | SuspendedWorkspacePlanException
               | BusinessSlotAllocationRequiredException
               | BusinessSlotLimitExceededException) {
            return $this->toManual("We can't create your business with the current account setup. Please contact support for help.");
        }

        $request->session()->forget(self::SESSION_SNAPSHOT);

        // Whatever the card did not fully answer is simply the step the wizard resumes at.
        return redirect()->route('customer.onboarding.show', ['step' => $this->onboarding->resolveStep($onboarding)->value]);
    }

    /** "Use a different Google account": destroy this connection's stored authorization, then start over. */
    public function reset(Request $request): RedirectResponse
    {
        $this->authorize('manage_google_business_profile');

        [, $customer, $business] = $this->context();

        if ($business !== null) {
            try {
                $this->bridge->disconnect($business, (int) $customer->user_id);
            } catch (GoogleBusinessProfileConcurrencyException $exception) {
                return $this->toManual($exception->userMessage());
            }
        }

        $request->session()->forget(self::SESSION_SNAPSHOT);

        return redirect()->route('customer.onboarding.show')
            ->with('status', 'Google account disconnected. You can connect a different one or set up manually.');
    }

    /**
     * Existing website address from Google is only a SAVED address; it is never presented as a connected site.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function defaultWebsiteChoice(array $snapshot, CustomerOnboarding $onboarding): ?string
    {
        $saved = $this->onboarding->websiteChoice($onboarding);

        if ($saved !== null) {
            return $saved;
        }

        return filled($snapshot['website'] ?? null) ? 'existing' : null;
    }

    /** @return array<string, mixed>|null */
    private function openSnapshot(Request $request, ?Business $business, ?BusinessGoogleConnection $connection, Customer $customer): ?array
    {
        if ($business === null || $connection === null || ! $connection->isActive()) {
            return null;
        }

        $token = $request->input('snapshot', $request->old('snapshot', $request->session()->get(self::SESSION_SNAPSHOT)));

        return $this->bridge->open(is_string($token) ? $token : null, $business, $connection, (int) $customer->user_id);
    }

    /** @return array{0: CustomerOnboarding, 1: Customer, 2: ?Business, 3: ?BusinessGoogleConnection} */
    private function context(): array
    {
        $customer = $this->customer();
        $onboarding = $this->onboarding->start($customer);
        $business = $this->bridge->draftBusinessFor($onboarding, $customer);

        return [$onboarding, $customer, $business, $business === null ? null : $this->bridge->connectionFor($business)];
    }

    private function toManual(?string $message = null): RedirectResponse
    {
        $redirect = redirect()->route('customer.onboarding.show');

        return $message === null ? $redirect : $redirect->withErrors(['onboarding' => $message]);
    }

    private function customer(): Customer
    {
        return Auth::user()->customer;
    }
}
