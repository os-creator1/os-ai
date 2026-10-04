<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessStatus;
use App\Exceptions\MetaAds\MetaAdsAccountSelectionException;
use App\Exceptions\MetaAds\MetaAdsConcurrencyException;
use App\Exceptions\MetaAds\MetaConfigurationException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesMetaAdsBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Ads\AdsFeatureAccess;
use App\Library\Money\CurrencyExponent;
use App\Library\MetaAds\MetaAdsAccountDirectory;
use App\Library\MetaAds\MetaAdsAccountSelector;
use App\Library\MetaAds\MetaAdsConfig;
use App\Library\MetaAds\MetaAdsMoney;
use App\Library\MetaAds\MetaOAuthStateSigner;
use App\Library\MetaAds\Sync\MetaAdsSyncRequester;
use App\Library\MetaAds\Sync\MetaAdsSyncRequestOutcome;
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
 * Meta Ads Module V1 (contract 24 §3/§4) — the connection side: connect /
 * re-authorise, the fixed OAuth callback, account selection, Settings
 * (targets + result type), disconnect and the manual refresh.
 *
 * Reads (Settings, the account list) need `view_meta_ads` / `manage_meta_ads`
 * respectively; every state change needs `manage_meta_ads` (credential-class,
 * default false). Tenancy is ResolvesMetaAdsBusinessTenancy: ads_basic_visibility
 * OR the full module, so Core connects and reads. Disconnect alone skips the
 * entitlement step so stored credentials are never trapped by a plan change.
 *
 * NO TOKEN, authorization code, app secret or raw provider payload is ever
 * rendered, flashed, logged or placed in a URL here. Customer messages are
 * fixed copy or the exceptions' own customer-safe messages. The refresh
 * request makes no provider call (it only queues).
 */
class MetaAdsConnectionController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesMetaAdsBusinessTenancy;

    private const CONNECTION_UNAVAILABLE_TITLE = 'Meta connection unavailable';

    /** Sane ceilings so a typo cannot store an absurd planning figure. */
    private const MAX_MONTHLY_TARGET = '100000000';

    private const MAX_TARGET_CPR = '1000000';

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly MetaOAuthStateSigner $stateSigner,
        private readonly MetaAdsAccountDirectory $directory,
        private readonly MetaAdsAccountSelector $selector,
        private readonly MetaAdsSyncRequester $syncRequester,
        private readonly MetaAdsConfig $config,
    ) {
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function settings(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveMetaAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('view_meta_ads');

        $data = $this->metaAdsViewData($workspace, $business, 'settings');
        $account = $data['account'];

        return view('customer.business.ads.meta.settings', $data + [
            'monthlyTargetInput' => $account === null ? null : $this->inputValue($account->monthly_budget_target_micros, (string) $account->currency_code),
            'cprTargetInput' => $account === null ? null : $this->inputValue($account->target_cost_per_result_micros, (string) $account->currency_code),
            'resultTypes' => $this->config->resultTypes(),
        ]);
    }

    public function saveSettings(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveMetaAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_meta_ads');

        $account = $this->resolveMetaAdsAccount($business);

        if ($account === null) {
            return $this->toSettings($workspace, $business, 'error', 'Choose a Meta ad account before setting targets.');
        }

        $resultTypes = $this->config->resultTypes();
        $rawType = $request->input('result_action_type');

        $input = [
            'monthly_budget_target' => $this->normaliseMoney($request->input('monthly_budget_target')),
            'target_cost_per_result' => $this->normaliseMoney($request->input('target_cost_per_result')),
            // Blank = unset. Anything that is not exactly an allow-list key is refused below.
            'result_action_type' => is_string($rawType) ? (trim($rawType) === '' ? null : trim($rawType)) : ($rawType === null ? null : '!'),
        ];

        $validator = Validator::make($input, [
            'monthly_budget_target' => ['nullable', 'regex:/\A\d{1,9}(\.\d{1,2})?\z/'],
            'target_cost_per_result' => ['nullable', 'regex:/\A\d{1,7}(\.\d{1,2})?\z/'],
            'result_action_type' => ['nullable', 'string'],
        ], [
            'monthly_budget_target.regex' => 'Enter the monthly budget as a plain amount, for example 250 or 250.50.',
            'target_cost_per_result.regex' => 'Enter the target cost per result as a plain amount, for example 25 or 25.50.',
        ]);

        $validator->after(function ($validator) use ($input, $resultTypes): void {
            foreach (['monthly_budget_target' => self::MAX_MONTHLY_TARGET, 'target_cost_per_result' => self::MAX_TARGET_CPR] as $field => $max) {
                if ($input[$field] !== null && ! $validator->errors()->has($field) && bccomp($input[$field], $max, 2) > 0) {
                    $validator->errors()->add($field, 'That amount is higher than we can use. Enter a smaller figure or leave it blank.');
                }
            }

            if ($input['result_action_type'] !== null && ! array_key_exists($input['result_action_type'], $resultTypes)) {
                $validator->errors()->add('result_action_type', 'Choose one of the listed result types.');
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('customer.workspaces.businesses.ads.meta.settings', [$workspace->uid, $business->uid])
                ->withErrors($validator)
                ->withInput($request->only(['monthly_budget_target', 'target_cost_per_result', 'result_action_type']));
        }

        // Micros of the ACCOUNT currency. Blank or zero clears to NULL ("no target").
        // Changing the result type is only a pointer change: stored typed rows are never reinterpreted.
        $account->forceFill([
            'monthly_budget_target_micros' => $this->toMicrosOrNull($input['monthly_budget_target']),
            'target_cost_per_result_micros' => $this->toMicrosOrNull($input['target_cost_per_result']),
            'result_action_type' => $input['result_action_type'],
        ])->save();

        return $this->toSettings($workspace, $business, 'success', 'Your settings were saved.');
    }

    // -----------------------------------------------------------------
    // Connect + the fixed OAuth callback
    // -----------------------------------------------------------------

    public function connect(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveMetaAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_meta_ads');

        $existing = $this->metaAdsConnectionFor($business);
        $forceReauth = false;

        if ($existing !== null && $existing->isActive()) {
            // Active: only a re-authorisation is meaningful (token due, or ads_management was not granted).
            $forceReauth = ! $this->metaAdsConnections()->canManage($existing);

            if (! $forceReauth && ! $this->metaAdsConnections()->mayBegin($existing)) {
                return $this->toOverview($workspace, $business, 'error', 'This business is already connected to Meta. Your connection does not need renewing yet.');
            }
        }

        try {
            $url = $this->metaAdsConnections()->beginConnect($business, (int) Auth::id(), $forceReauth);
        } catch (MetaConfigurationException $exception) {
            // The exact setting name is an operator diagnostic, logged here, never customer-visible.
            Log::error('Meta Ads configuration error.', [
                'reason' => $exception->reason,
                'operator_message' => $exception->operatorMessage(),
                'workspace_uid' => $workspaceUid,
                'business_uid' => $businessUid,
            ]);

            return $this->toOverview($workspace, $business, 'error', $exception->customerMessage(), self::CONNECTION_UNAVAILABLE_TITLE);
        } catch (MetaAdsConcurrencyException $exception) {
            return $this->toOverview($workspace, $business, 'error', $exception->userMessage());
        } catch (LogicException) {
            return $this->toOverview($workspace, $business, 'error', 'This business is already connected to Meta.');
        }

        return redirect()->away($url);
    }

    /**
     * The ONE fixed, tenant-free callback. Order is load-bearing: signed state
     * (404) -> product claim (404) -> Business and connection from the state
     * ONLY -> the whole tenancy / entitlement / permission chain -> the actor
     * must be the initiator -> only then the single-use nonce consumption ->
     * only then the code exchange. Every failure before consumption is a bare
     * 404 (no tenant parameters exist, so a permission-shaped answer would
     * disclose that the signed Business exists). The code and state are never
     * logged, flashed, redirected or rendered.
     */
    public function callback(Request $request): RedirectResponse
    {
        $payload = $this->stateSigner->verify(is_string($request->query('state')) ? $request->query('state') : null);

        if ($payload === null) {
            abort(404);
        }

        // This controller is exclusively the Meta callback: a state signed for another product is refused.
        if ($payload['p'] !== MetaOAuthStateSigner::PRODUCT) {
            abort(404);
        }

        $business = Business::query()->find($payload['b']);

        if ($business === null || $business->workspace_id === null) {
            abort(404);
        }

        $connection = $this->metaAdsConnectionFor($business);

        if ($connection === null) {
            abort(404);
        }

        $workspace = Workspace::query()->find($business->workspace_id);

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

        if (! app(AdsFeatureAccess::class)->hasAnyAds($workspace, $business, (int) Auth::id())) {
            abort(404);
        }

        if (Gate::denies('manage_meta_ads')) {
            abort(404);
        }

        // The callback actor must be the one who started THIS attempt (checked BEFORE consumption,
        // so a mismatched actor cannot burn the rightful actor's still-valid nonce).
        if ((int) ($payload['u'] ?? 0) !== (int) Auth::id()
            || ! $this->metaAdsConnections()->attemptBelongsToActor($connection, (int) Auth::id())) {
            abort(404);
        }

        if (! $this->stateSigner->consume($payload)) {
            abort(404);
        }

        // The owner declined on Meta's dialog (error / error_reason), or no code arrived.
        if ($request->query('error') !== null || $request->query('error_reason') !== null || ! is_string($request->query('code')) || $request->query('code') === '') {
            return $this->toOverview($workspace, $business, 'error', 'Meta access was not granted. Nothing was connected; you can try again whenever you are ready.');
        }

        try {
            $this->metaAdsConnections()->completeConnect($connection, (string) $request->query('code'), (int) Auth::id());
        } catch (MetaConfigurationException $exception) {
            Log::error('Meta Ads configuration error.', [
                'reason' => $exception->reason,
                'operator_message' => $exception->operatorMessage(),
                'workspace_uid' => (string) $workspace->uid,
                'business_uid' => (string) $business->uid,
            ]);

            return $this->toOverview($workspace, $business, 'error', $exception->customerMessage(), self::CONNECTION_UNAVAILABLE_TITLE);
        } catch (MetaAdsConcurrencyException $exception) {
            return $this->toOverview($workspace, $business, 'error', $exception->userMessage());
        } catch (MetaProviderException $exception) {
            return $this->toOverview($workspace, $business, 'error', $exception->userMessage());
        }

        return redirect()
            ->route('customer.workspaces.businesses.ads.meta.accounts', [(string) $workspace->uid, (string) $business->uid])
            ->with($this->metaAdsFlash('success', 'Meta connected. Choose the ad account to use.'));
    }

    // -----------------------------------------------------------------
    // Account selection
    // -----------------------------------------------------------------

    public function accounts(string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->resolveMetaAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_meta_ads');

        $connection = $this->metaAdsConnectionFor($business);

        if ($connection === null || ! $connection->isActive()) {
            return $this->toOverview($workspace, $business, 'error', 'Connect Meta first.');
        }

        $data = $this->metaAdsViewData($workspace, $business, 'settings');
        $candidates = null;
        $loadError = null;

        try {
            $candidates = array_map(fn ($candidate): array => [
                'accountId' => $candidate->adAccountId,
                'name' => $candidate->name,
                'currency' => $candidate->currencyCode,
                'timeZone' => $candidate->timeZone,
                'selectable' => $candidate->isSelectable(),
                'statusLabel' => $this->accountStatusLabel($candidate->accountStatus),
            ], $this->directory->candidates($business, $connection, (int) Auth::id()));
        } catch (MetaProviderException $exception) {
            $loadError = $exception->userMessage();
        }

        return view('customer.business.ads.meta.accounts', $data + [
            'candidates' => $candidates,
            'loadError' => $loadError,
            'selectedAccountId' => $data['account']?->ad_account_id,
        ]);
    }

    public function selectAccount(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveMetaAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_meta_ads');

        // ONLY the account id is read from the request: currency, time zone and status are
        // re-derived server-side from the fresh candidate.
        $validated = $request->validate(['account_id' => ['required', 'string', 'max:24']]);

        $connection = $this->metaAdsConnectionFor($business);

        if ($connection === null || ! $connection->isActive()) {
            return $this->toOverview($workspace, $business, 'error', 'Connect Meta first.');
        }

        try {
            $account = $this->selector->select($business, $connection, (string) $validated['account_id'], (int) Auth::id());
        } catch (MetaAdsAccountSelectionException $exception) {
            if (in_array($exception->reason, [MetaAdsAccountSelectionException::NOT_A_CANDIDATE, MetaAdsAccountSelectionException::INVALID_ACCOUNT_ID], true)) {
                abort(404);
            }

            return redirect()
                ->route('customer.workspaces.businesses.ads.meta.accounts', [$workspace->uid, $business->uid])
                ->with($this->metaAdsFlash('error', $exception->customerMessage()));
        } catch (MetaProviderException $exception) {
            return redirect()
                ->route('customer.workspaces.businesses.ads.meta.accounts', [$workspace->uid, $business->uid])
                ->with($this->metaAdsFlash('error', $exception->userMessage()));
        }

        // Queues the first sync; nothing is fetched in this request.
        $this->syncRequester->requestInitial($account, (int) Auth::id());

        return $this->toOverview($workspace, $business, 'success', 'Meta ad account selected. We are loading your data now, which can take a few minutes.');
    }

    // -----------------------------------------------------------------
    // Disconnect + manual refresh
    // -----------------------------------------------------------------

    public function disconnect(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveMetaAdsAccessibleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_meta_ads');

        $connection = $this->metaAdsConnectionFor($business);

        if ($connection === null) {
            return $this->toOverview($workspace, $business, 'success', 'Meta is not connected.');
        }

        try {
            $this->metaAdsConnections()->disconnect($connection, (int) Auth::id());
        } catch (MetaAdsConcurrencyException $exception) {
            return $this->toOverview($workspace, $business, 'error', $exception->userMessage());
        } catch (LogicException) {
            return $this->toOverview($workspace, $business, 'error', 'Meta could not be disconnected right now. Please try again.');
        }

        return $this->toOverview($workspace, $business, 'success', 'Meta disconnected and the stored authorization destroyed. Your earlier figures are kept.');
    }

    public function refresh(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveMetaAdsTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_meta_ads');

        $account = $this->resolveMetaAdsAccount($business);

        if ($account === null) {
            return $this->toSettings($workspace, $business, 'error', 'Connect Meta and choose an ad account first.');
        }

        // Queue-only: the requester never calls Meta in this request.
        $result = $this->syncRequester->requestManual($account, (int) Auth::id());

        return match ($result->outcome) {
            MetaAdsSyncRequestOutcome::Queued => $this->toSettings($workspace, $business, 'success', 'Refresh started. New figures will appear in a few minutes.'),
            MetaAdsSyncRequestOutcome::Throttled,
            MetaAdsSyncRequestOutcome::FreshEnough => $this->toSettings(
                $workspace,
                $business,
                'info',
                'Your figures were refreshed recently, so there is nothing new to load yet.'
                    . ($result->nextAllowedAt !== null ? ' You can refresh again ' . $result->nextAllowedAt->diffForHumans() . '.' : ''),
            ),
            MetaAdsSyncRequestOutcome::AlreadyRunning => $this->toSettings($workspace, $business, 'info', 'A refresh is already in progress. New figures will appear shortly.'),
            MetaAdsSyncRequestOutcome::NotSyncable => $this->toSettings($workspace, $business, 'error', 'This account cannot be refreshed right now. Check the connection status below.'),
        };
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function toOverview(Workspace $workspace, Business $business, string $status, string $message, ?string $title = null): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.ads.meta.index', [(string) $workspace->uid, (string) $business->uid])
            ->with(array_filter(['status' => $status, 'message' => $message, 'message_title' => $title], fn ($value) => $value !== null));
    }

    private function toSettings(Workspace $workspace, Business $business, string $status, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.ads.meta.settings', [(string) $workspace->uid, (string) $business->uid])
            ->with($this->metaAdsFlash($status, $message));
    }

    /** Meta's documented account_status codes as owner-facing text. */
    private function accountStatusLabel(int $status): string
    {
        return match ($status) {
            1 => 'Active',
            2 => 'Disabled',
            3 => 'Payment unsettled',
            7 => 'Pending risk review',
            8 => 'Pending settlement',
            9 => 'In grace period',
            100 => 'Pending closure',
            101 => 'Closed',
            default => 'Not active',
        };
    }

    /** A plain input string ("" when unset) in the account currency's own precision. */
    private function inputValue(?int $micros, string $currency): string
    {
        if ($micros === null) {
            return '';
        }

        $exponent = CurrencyExponent::isSupported($currency) ? CurrencyExponent::for($currency) : 2;

        return (string) MetaAdsMoney::microsToDecimalString($micros, $exponent);
    }

    /** Trims, drops spaces and thousands commas; "" and non-scalars become null / a failing marker. */
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

        $micros = MetaAdsMoney::tryParseToMicros($decimal);

        return $micros === null || $micros <= 0 ? null : $micros;
    }
}
