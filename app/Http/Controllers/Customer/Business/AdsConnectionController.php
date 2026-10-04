<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Exceptions\GoogleAds\GoogleAdsAccountSelectionException;
use App\Exceptions\GoogleAds\GoogleAdsConcurrencyException;
use App\Exceptions\GoogleAds\GoogleAdsConfigurationException;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesAdsBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\GoogleAds\GoogleAdsAccountDirectory;
use App\Library\GoogleAds\GoogleAdsAccountSelector;
use App\Library\GoogleAds\GoogleAdsCustomerId;
use App\Library\GoogleAds\GoogleAdsMoney;
use App\Library\GoogleAds\Sync\GoogleAdsSyncRequester;
use App\Library\GoogleAds\Sync\GoogleAdsSyncRequestOutcome;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Library\Money\CurrencyExponent;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * Google Ads Module V1 — the connection side of the module shell: connect,
 * the fixed OAuth callback, account selection, Settings (budget target and
 * target CPL), disconnect and the manual refresh.
 *
 * Reads (Settings, the account list) need `view_google_ads` / `manage_google_ads`
 * respectively; every state change needs `manage_google_ads`, which is
 * credential-class and defaults to false. Tenancy is ResolvesAdsBusinessTenancy:
 * Workspace -> Business -> active Business -> ads_basic_visibility OR
 * google_ads_module, so Core connects and reads. Disconnect alone skips the
 * entitlement step (GBP precedent, contract §39.4) so stored credentials are
 * never trapped by a plan change.
 *
 * NO TOKEN, authorization code, client secret or raw provider payload is
 * ever rendered, flashed, logged or placed in a URL here. Messages shown to
 * the customer are fixed copy or the exceptions' own customer-safe messages.
 *
 * NO PROVIDER CALL on the refresh request: GoogleAdsSyncRequester only queues
 * the sync. The only provider calls in this controller are the code exchange
 * in the callback and the account listing / selection (both explicit,
 * manage-permissioned, throttled actions).
 */
class AdsConnectionController extends CustomerBaseController
{
    use ResolvesAdsBusinessTenancy;
    use ResolvesBusinessTenancy;

    private const CONNECTION_UNAVAILABLE_TITLE = 'Google Ads connection unavailable';

    /** Sane ceilings so a typo cannot store an absurd planning figure. */
    private const MAX_MONTHLY_TARGET = '100000000';

    private const MAX_TARGET_CPL = '1000000';

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly GoogleOAuthStateSigner $stateSigner,
        private readonly GoogleAdsAccountDirectory $directory,
        private readonly GoogleAdsAccountSelector $selector,
        private readonly GoogleAdsSyncRequester $syncRequester,
    ) {
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function settings(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('view_google_ads');

        $data = $this->adsViewData($workspace, $business, 'settings');
        $account = $data['account'];

        return view('customer.business.ads.settings', $data + [
            'monthlyTargetInput' => $account === null ? null : $this->inputValue($account->monthly_budget_target_micros, (string) $account->currency_code),
            'cplTargetInput' => $account === null ? null : $this->inputValue($account->target_cpl_micros, (string) $account->currency_code),
        ]);
    }

    public function saveSettings(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_google_ads');

        $account = $this->resolveAdsAccount($business);

        if ($account === null) {
            return $this->toSettings($workspace, $business, 'error', 'Choose a Google Ads account before setting targets.');
        }

        $input = [
            'monthly_budget_target' => $this->normaliseMoney($request->input('monthly_budget_target')),
            'target_cpl' => $this->normaliseMoney($request->input('target_cpl')),
        ];

        $validator = Validator::make($input, [
            'monthly_budget_target' => ['nullable', 'regex:/\A\d{1,9}(\.\d{1,2})?\z/'],
            'target_cpl' => ['nullable', 'regex:/\A\d{1,7}(\.\d{1,2})?\z/'],
        ], [
            'monthly_budget_target.regex' => 'Enter the monthly budget as a plain amount, for example 250 or 250.50.',
            'target_cpl.regex' => 'Enter the target cost per conversion as a plain amount, for example 25 or 25.50.',
        ]);

        $validator->after(function ($validator) use ($input): void {
            foreach (['monthly_budget_target' => self::MAX_MONTHLY_TARGET, 'target_cpl' => self::MAX_TARGET_CPL] as $field => $max) {
                if ($input[$field] !== null && ! $validator->errors()->has($field) && bccomp($input[$field], $max, 2) > 0) {
                    $validator->errors()->add($field, 'That amount is higher than we can use. Enter a smaller figure or leave it blank.');
                }
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('customer.workspaces.businesses.ads.settings', [$workspace->uid, $business->uid])
                ->withErrors($validator)
                ->withInput($request->only(['monthly_budget_target', 'target_cpl']));
        }

        // Stored in MICROS of the ACCOUNT currency. Blank, or zero, clears
        // the planning value to NULL ("no target set").
        $account->forceFill([
            'monthly_budget_target_micros' => $this->toMicrosOrNull($input['monthly_budget_target']),
            'target_cpl_micros' => $this->toMicrosOrNull($input['target_cpl']),
        ])->save();

        return $this->toSettings($workspace, $business, 'success', 'Your targets were saved.');
    }

    // -----------------------------------------------------------------
    // Connect + the fixed OAuth callback
    // -----------------------------------------------------------------

    public function connect(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_google_ads');

        $existing = $this->adsConnectionFor($business);

        if ($existing !== null && $existing->isActive()) {
            return $this->toOverview($workspace, $business, 'error', 'This business is already connected to Google Ads. Disconnect first to connect a different Google account.');
        }

        try {
            $url = $this->adsConnections()->beginConnect($business, (int) Auth::id());
        } catch (GoogleAdsConfigurationException $exception) {
            // The exact setting name is an operator diagnostic, logged here,
            // never customer-visible. Nothing secret is in it.
            Log::error('Google Ads configuration error.', [
                'reason' => $exception->reason,
                'operator_message' => $exception->operatorMessage(),
                'workspace_uid' => $workspaceUid,
                'business_uid' => $businessUid,
            ]);

            return $this->toOverview($workspace, $business, 'error', $exception->customerMessage(), self::CONNECTION_UNAVAILABLE_TITLE);
        } catch (GoogleAdsConcurrencyException $exception) {
            return $this->toOverview($workspace, $business, 'error', $exception->userMessage());
        } catch (LogicException) {
            return $this->toOverview($workspace, $business, 'error', 'This business is already connected to Google Ads.');
        }

        return redirect()->away($url);
    }

    /**
     * The ONE fixed, tenant-free callback. Mirrors the GBP callback step for
     * step: signed state first, then Business and connection from the state
     * only, then the full chain re-run, then the actor check, and only then
     * the single-use nonce consumption and the code exchange. Every failure
     * before the exchange is a bare 404 — this route has no tenant
     * parameters, so a permission-shaped answer would itself disclose that
     * the signed Business exists.
     */
    public function callback(Request $request): RedirectResponse
    {
        // 1 — state first, before any tenant data is touched.
        $payload = $this->stateSigner->verify($request->query('state'));

        if ($payload === null) {
            abort(404);
        }

        // 1b — this controller is exclusively the Google Ads callback; a
        // state signed for another product is refused like any malformed one.
        if ($payload['p'] !== GoogleConnectionProduct::GoogleAds->value) {
            abort(404);
        }

        // 2 — Business and connection come ONLY from the signed state.
        $business = Business::query()->find($payload['b']);

        if ($business === null || $business->workspace_id === null) {
            abort(404);
        }

        $connection = $this->adsConnectionFor($business);

        if ($connection === null) {
            abort(404);
        }

        // 3 — the Workspace is looked up, never supplied by the caller.
        $workspace = Workspace::query()->find($business->workspace_id);

        // 4 — the complete chain, re-run from scratch.
        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        if ($this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $business->uid) === null) {
            abort(404);
        }

        if (! app(BusinessRouteAccess::class)->actorMayUseBusinessRoute(Auth::user(), $workspace, $business)) {
            abort(404);
        }

        if ($business->status !== BusinessStatus::Active) {
            abort(404);
        }

        if (! $this->adsEntitlementAllows($workspace, $business, (int) Auth::id())) {
            abort(404);
        }

        if (Gate::denies('manage_google_ads')) {
            abort(404);
        }

        // 5 — the callback actor must be the one who started THIS attempt,
        // checked BEFORE consumption so a mismatched actor cannot burn the
        // rightful actor's still-valid nonce.
        if (! $this->adsConnections()->attemptBelongsToActor($connection, (int) Auth::id())) {
            abort(404);
        }

        // 6 — atomic, single-use consumption.
        if (! $this->stateSigner->consume((int) $business->id, GoogleConnectionProduct::GoogleAds, $payload['n'])) {
            abort(404);
        }

        if ($request->query('error') !== null || $request->query('code') === null) {
            return $this->toOverview($workspace, $business, 'error', 'Google did not complete the connection.');
        }

        // 7 — only now. The code is passed straight to the manager and is
        // never logged, flashed or redirected.
        try {
            $this->adsConnections()->completeConnect($connection, (string) $request->query('code'), (int) Auth::id());
        } catch (GoogleAdsConfigurationException $exception) {
            Log::error('Google Ads configuration error.', [
                'reason' => $exception->reason,
                'operator_message' => $exception->operatorMessage(),
                'workspace_uid' => (string) $workspace->uid,
                'business_uid' => (string) $business->uid,
            ]);

            return $this->toOverview($workspace, $business, 'error', $exception->customerMessage(), self::CONNECTION_UNAVAILABLE_TITLE);
        } catch (GoogleAdsConcurrencyException $exception) {
            return $this->toOverview($workspace, $business, 'error', $exception->userMessage());
        } catch (GoogleAdsProviderException $exception) {
            return $this->toOverview($workspace, $business, 'error', $exception->userMessage());
        }

        // 8 — canonical UIDs, resolved server-side.
        return redirect()
            ->route('customer.workspaces.businesses.ads.accounts', [(string) $workspace->uid, (string) $business->uid])
            ->with($this->adsFlash('success', 'Google account connected. Choose the Google Ads account to use.'));
    }

    // -----------------------------------------------------------------
    // Account selection
    // -----------------------------------------------------------------

    public function accounts(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->resolveAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_google_ads');

        $connection = $this->adsConnectionFor($business);

        if ($connection === null || ! $connection->isActive()) {
            return $this->toOverview($workspace, $business, 'error', 'Connect Google Ads first.');
        }

        $data = $this->adsViewData($workspace, $business, 'settings');
        $candidates = null;
        $loadError = null;

        try {
            $candidates = array_map(fn ($candidate): array => [
                'customerId' => $candidate->customerId,
                'idDisplay' => $this->formatCustomerId($candidate->customerId),
                'name' => $candidate->name,
                'currency' => $candidate->currencyCode,
                'timeZone' => $candidate->timeZone,
                'isManager' => $candidate->isManager,
                'isTest' => $candidate->isTest,
                'selectable' => $candidate->isSelectable(),
            ], $this->directory->candidates($business, $connection, (int) Auth::id()));
        } catch (GoogleAdsProviderException $exception) {
            $loadError = $exception->userMessage();
        }

        return view('customer.business.ads.accounts', $data + [
            'candidates' => $candidates,
            'loadError' => $loadError,
            'selectedCustomerId' => $data['account']?->customer_id,
        ]);
    }

    public function selectAccount(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_google_ads');

        // ONLY the customer id is read from the request: login id, currency
        // and time zone are re-derived server-side from the fresh candidate.
        $validated = $request->validate(['customer_id' => ['required', 'string', 'max:20']]);

        try {
            $account = $this->selector->select($business, (int) Auth::id(), (string) $validated['customer_id']);
        } catch (GoogleAdsAccountSelectionException $exception) {
            if (in_array($exception->reason, [GoogleAdsAccountSelectionException::NOT_A_CANDIDATE, GoogleAdsAccountSelectionException::INVALID_CUSTOMER_ID], true)) {
                abort(404);
            }

            return redirect()
                ->route('customer.workspaces.businesses.ads.accounts', [$workspace->uid, $business->uid])
                ->with($this->adsFlash('error', $exception->customerMessage()));
        } catch (GoogleAdsProviderException $exception) {
            return redirect()
                ->route('customer.workspaces.businesses.ads.accounts', [$workspace->uid, $business->uid])
                ->with($this->adsFlash('error', $exception->userMessage()));
        }

        // Queues the first sync; nothing is fetched in this request.
        $this->syncRequester->requestInitial($account, (int) Auth::id());

        return $this->toOverview($workspace, $business, 'success', 'Google Ads account selected. We are loading your data now, which can take a few minutes.');
    }

    // -----------------------------------------------------------------
    // Disconnect + manual refresh
    // -----------------------------------------------------------------

    public function disconnect(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveAdsAccessibleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_google_ads');

        $connection = $this->adsConnectionFor($business);

        if ($connection === null) {
            return $this->toOverview($workspace, $business, 'success', 'Google Ads is not connected.');
        }

        try {
            $this->adsConnections()->disconnect($connection, (int) Auth::id());
        } catch (GoogleAdsConcurrencyException $exception) {
            return $this->toOverview($workspace, $business, 'error', $exception->userMessage());
        } catch (LogicException) {
            return $this->toOverview($workspace, $business, 'error', 'Google Ads could not be disconnected right now. Please try again.');
        }

        return $this->toOverview($workspace, $business, 'success', 'Google Ads disconnected and the stored authorization destroyed. Your earlier figures are kept.');
    }

    public function refresh(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_google_ads');

        $account = $this->resolveAdsAccount($business);

        if ($account === null) {
            return $this->toSettings($workspace, $business, 'error', 'Connect Google Ads and choose an account first.');
        }

        // Queue-only: the requester never calls Google in this request.
        $result = $this->syncRequester->requestManual($account, (int) Auth::id());

        return match ($result->outcome) {
            GoogleAdsSyncRequestOutcome::Queued => $this->toSettings($workspace, $business, 'success', 'Refresh started. New figures will appear in a few minutes.'),
            GoogleAdsSyncRequestOutcome::Throttled,
            GoogleAdsSyncRequestOutcome::FreshEnough => $this->toSettings(
                $workspace,
                $business,
                'info',
                'Your figures were refreshed recently, so there is nothing new to load yet.'
                    . ($result->nextAllowedAt !== null ? ' You can refresh again ' . $result->nextAllowedAt->diffForHumans() . '.' : ''),
            ),
            GoogleAdsSyncRequestOutcome::AlreadyRunning => $this->toSettings($workspace, $business, 'info', 'A refresh is already in progress. New figures will appear shortly.'),
            GoogleAdsSyncRequestOutcome::NotSyncable => $this->toSettings($workspace, $business, 'error', 'This account cannot be refreshed right now. Check the connection status below.'),
        };
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function toOverview(Workspace $workspace, Business $business, string $status, string $message, ?string $title = null): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.ads.index', [(string) $workspace->uid, (string) $business->uid])
            ->with(array_filter(['status' => $status, 'message' => $message, 'message_title' => $title], fn ($value) => $value !== null));
    }

    private function toSettings(Workspace $workspace, Business $business, string $status, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.ads.settings', [(string) $workspace->uid, (string) $business->uid])
            ->with($this->adsFlash($status, $message));
    }

    /** "1234567890" -> "123-456-7890". */
    private function formatCustomerId(string $customerId): string
    {
        $normalized = GoogleAdsCustomerId::normalize($customerId);

        return $normalized === null
            ? $customerId
            : substr($normalized, 0, 3) . '-' . substr($normalized, 3, 3) . '-' . substr($normalized, 6);
    }

    /** A plain input string ("" when unset) in the account currency's own precision. */
    private function inputValue(?int $micros, string $currency): string
    {
        if ($micros === null) {
            return '';
        }

        $exponent = CurrencyExponent::isSupported($currency) ? CurrencyExponent::for($currency) : 2;

        return (string) GoogleAdsMoney::microsToDecimalString($micros, $exponent);
    }

    /** Trims, drops spaces and thousands commas; "" and non-scalars become null. */
    private function normaliseMoney(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            // An array or object is never a figure: fail validation, never clear.
            return '!';
        }

        $clean = str_replace([',', ' '], '', trim((string) $value));

        return $clean === '' ? null : $clean;
    }

    private function toMicrosOrNull(?string $decimal): ?int
    {
        if ($decimal === null) {
            return null;
        }

        $micros = GoogleAdsMoney::toMicros($decimal);

        return $micros === null || $micros <= 0 ? null : $micros;
    }
}
