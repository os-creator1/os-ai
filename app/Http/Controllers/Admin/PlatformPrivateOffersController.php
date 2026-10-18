<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Http\Controllers\Controller;
use App\Library\PlatformBilling\Offers\PrivateOfferManager;
use App\Library\PlatformBilling\Offers\PrivateOfferRedemptionManager;
use App\Models\PlatformPrivateOffer;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Platform Owner → Billing & Revenue → Private Offers.
 *
 * Create Offer -> Copy Link, plus view / revoke / track. The owner never opens
 * Stripe: the Prices an offer needs are created and verified by MotionGrove
 * when a customer accepts it.
 *
 * Every mutation is by an authenticated platform administrator, needs a reason,
 * and is recorded in the platform audit trail by the manager. The plain link is
 * shown ONCE (on creation or when a replacement link is issued) because only its
 * hash is stored; "New link" is how an owner gets another one.
 */
class PlatformPrivateOffersController extends Controller
{
    public function __construct(
        private readonly PrivateOfferManager $offers,
        private readonly PrivateOfferRedemptionManager $redemptions,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('view workspace plans');

        $workspace = $this->workspaceFromQuery($request);

        return view('admin.platform-billing.offers.index', [
            'rows' => $this->offers->overview($workspace?->id),
            'plans' => WorkspacePlanCatalog::query()->with('currency:id,code')->orderBy('id')->get(),
            'prefillWorkspace' => $workspace,
            'breadcrumbs' => $this->crumbs(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        $data = $request->only([
            'kind', 'label', 'tier', 'customer_email', 'workspace_uid', 'trial_days', 'custom_price',
            'discount_type', 'discount_value', 'discount_duration', 'discount_periods',
            'expires_at', 'redemption_limit', 'reason',
        ]);

        try {
            $created = $this->offers->create($data, (int) Auth::id());
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('admin.platform-billing.offers.show', [$created['offer']->uid])
            ->with([
                'status' => 'success',
                'message' => __('Offer created. Copy the link below and send it to the customer — it is shown only once.'),
                // Flashed (not stored): the plain token exists nowhere else.
                'offer_link' => route('offers.show', ['token' => $created['token']]),
            ]);
    }

    public function show(string $offer): View
    {
        $this->authorize('view workspace plans');

        $model = $this->find($offer);
        $model->load(['catalog', 'currency', 'workspace', 'creator']);

        $rows = PlatformPrivateOfferRedemption::query()
            ->where('platform_private_offer_id', $model->id)
            ->with(['workspace:id,uid,name', 'actor:id,first_name,last_name,email'])
            ->orderByDesc('id')
            ->get();

        return view('admin.platform-billing.offers.show', [
            'offer' => $model,
            'summary' => $this->offers->describe($model),
            'redemptions' => $rows,
            'canApplyWithoutAcceptance' => $this->canApplyWithoutAcceptance($model, $rows),
            'breadcrumbs' => $this->crumbs($model->label),
        ]);
    }

    public function revoke(Request $request, string $offer): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            $this->offers->revoke($this->find($offer), (int) Auth::id(), $data['reason']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with(['status' => 'success', 'message' => __('Offer revoked. Anyone who had not paid yet can no longer use it; customers who already redeemed keep the terms they accepted.')]);
    }

    public function newLink(Request $request, string $offer): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $model = $this->find($offer);

        try {
            $token = $this->offers->rotateLink($model, (int) Auth::id(), $data['reason']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('admin.platform-billing.offers.show', [$model->uid])->with([
            'status' => 'success',
            'message' => __('A new link was issued. The previous link no longer works.'),
            'offer_link' => route('offers.show', ['token' => $token]),
        ]);
    }

    /** Apply a price-neutral-or-better billing change without waiting for the customer. */
    public function applyChange(Request $request, string $offer): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            $this->redemptions->applyBillingChangeAsOwner($this->find($offer), (int) Auth::id(), $data['reason']);
        } catch (PlatformBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => __('The new terms were put on the subscription. They start at the next renewal; nothing is charged today.')]);
    }

    private function canApplyWithoutAcceptance(PlatformPrivateOffer $offer, $redemptions): bool
    {
        return ! $redemptions->contains(fn ($r) => $r->active_slot !== null)
            && $this->redemptions->ownerCanApplyWithoutAcceptance($offer);
    }

    private function find(string $uid): PlatformPrivateOffer
    {
        return PlatformPrivateOffer::query()->where('uid', $uid)->firstOrFail();
    }

    private function workspaceFromQuery(Request $request): ?Workspace
    {
        $uid = $request->query('workspace');

        return is_string($uid) && $uid !== '' ? Workspace::query()->where('uid', $uid)->first() : null;
    }

    private function crumbs(?string $leaf = null): array
    {
        $crumbs = [
            ['link' => url(config('app.admin_path') . '/platform-billing'), 'name' => __('Billing & Revenue')],
            ['link' => route('admin.platform-billing.offers.index'), 'name' => __('Private Offers')],
        ];

        if ($leaf !== null) {
            $crumbs[] = ['name' => $leaf];
        }

        return $crumbs;
    }
}
