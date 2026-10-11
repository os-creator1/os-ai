<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Http\Controllers\Controller;
use App\Library\PlatformBilling\Offers\OfferReturn;
use App\Library\PlatformBilling\Offers\PrivateOfferRedemptionManager;
use App\Library\PlatformBilling\PlatformPlanPresenter;
use App\Library\PlatformBilling\V1SignupDraft;
use App\Library\PlatformBilling\V1SignupManager;
use App\Models\PlatformPrivateOffer;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * The customer's side of a Private Offer link.
 *
 * OPENING THE LINK CHANGES NOTHING. `show()` is a read; the only thing that
 * ever starts a payment is an authenticated POST in which the customer has
 * ticked the box accepting the exact terms they were shown. Even then nothing
 * is paid or entitled until Stripe's own confirmation arrives (see
 * PrivateOfferRedemptionManager / OfferReconciler).
 *
 * The page carries a secret in its URL, so it is never cached and never leaks
 * through a Referer header. The Checkout return URLs carry the redemption uid,
 * not the secret.
 */
class PrivateOfferController extends Controller
{
    public function __construct(
        private readonly PrivateOfferRedemptionManager $offers,
        private readonly PlatformPlanPresenter $plans,
        private readonly V1SignupManager $signup,
    ) {
    }

    public function show(Request $request, string $token): Response
    {
        $offer = $this->offers->findByToken($token) ?? abort(404);
        $viewer = $request->user();

        if ($viewer === null) {
            OfferReturn::remember($token);
        } else {
            OfferReturn::forget();
        }

        $view = $this->offers->present($offer, $viewer, $request->query('workspace') !== null ? (string) $request->query('workspace') : null);

        return $this->private(response()->view('offers.show', [
            'token' => $token,
            'view' => $view,
            'offer' => $offer,
            'plan' => $this->plans->presentTier($offer->catalog->tier),
            'canSignUp' => config('account.can_register')
                && config('account.verify_account')
                && ! $offer->isBillingChange()
                && $offer->target_workspace_id === null,
        ]));
    }

    /**
     * A brand-new customer arriving from an offer link: the normal signup,
     * fixed to the offered plan, ending back on the offer page instead of at an
     * undiscounted Checkout.
     */
    public function signup(Request $request, string $token): RedirectResponse
    {
        $offer = $this->offers->findByToken($token) ?? abort(404);

        if (Auth::check()) {
            return redirect()->route('offers.show', ['token' => $token]);
        }

        $view = $this->offers->present($offer, null);

        if ($view['state'] === 'unavailable'
            || $offer->isBillingChange()
            || $offer->target_workspace_id !== null
            || ! config('account.can_register')
            || ! config('account.verify_account')) {
            return redirect()->route('offers.show', ['token' => $token]);
        }

        OfferReturn::remember($token);

        $draft = new V1SignupDraft($request->session());
        $draft->setOfferToken($token);
        $draft->setTier($offer->catalog->tier->value);

        return redirect()->route('register.account');
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $offer = $this->offers->findByToken($token) ?? abort(404);

        $validator = Validator::make($request->all(), [
            'terms_hash' => ['required', 'string', 'size:64'],
            // The explicit act. A missing or unticked box is a refusal, not a default.
            'accept_terms' => ['required', 'accepted'],
            // Which of their workspaces an email-bound offer is for. Never trusted:
            // the manager only accepts one the signed-in user owns.
            'workspace_uid' => ['nullable', 'string', 'max:64'],
        ], [
            'accept_terms.required' => __('Please tick the box to confirm you accept these terms.'),
            'accept_terms.accepted' => __('Please tick the box to confirm you accept these terms.'),
        ]);

        if ($validator->fails()) {
            return redirect()->route('offers.show', ['token' => $token])->withErrors($validator->errors());
        }

        $hash = (string) $validator->validated()['terms_hash'];
        $workspaceUid = $validator->validated()['workspace_uid'] ?? null;
        $user = $request->user();

        try {
            if ($offer->isBillingChange()) {
                $this->offers->acceptBillingChange($offer, $user, $hash);

                return redirect()->route('offers.show', ['token' => $token])->with([
                    'status' => 'success',
                    'message' => __('Done. Your new price starts at your next renewal; nothing is charged today.'),
                ]);
            }

            $session = $this->offers->accept(
                $offer,
                $user,
                $hash,
                fn (PlatformPrivateOfferRedemption $r): array => [
                    route('offers.return', ['redemption' => $r->uid]),
                    route('offers.cancelled', ['redemption' => $r->uid]),
                ],
                $workspaceUid,
            );
        } catch (PlatformBillingException $e) {
            return redirect()->route('offers.show', ['token' => $token])->with([
                'status' => 'error',
                'message' => $e->customerMessage(),
            ]);
        }

        return redirect()->away((string) $session->url);
    }

    /**
     * Back from Stripe. Provider truth decides: the redirect itself proves
     * nothing (a `?success` query flag would be forgeable).
     */
    public function checkoutReturn(Request $request, string $redemption): RedirectResponse
    {
        $row = PlatformPrivateOfferRedemption::query()->where('uid', $redemption)->firstOrFail();

        try {
            $state = $this->offers->handleReturn($row, $request->user());
        } catch (PlatformBillingException $e) {
            abort_if($e->reason === PlatformBillingException::OFFER_NOT_FOR_YOU, 404);

            return redirect()->route(\Illuminate\Support\Facades\Route::has('signup.plan') ? 'signup.plan' : 'user.home')
                ->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        $subscription = PlatformSubscription::query()->where('workspace_id', $row->workspace_id)->first();

        if ($subscription !== null && $state === PlatformPrivateOfferRedemption::STATE_REDEEMED) {
            // The same idempotent seam the webhook uses: assigns the plan from
            // the CONFIRMED subscription, never from this redirect.
            $this->signup->activateFromConfirmedSubscription($subscription->refresh());

            return redirect()->route('user.home')->with([
                'status' => 'success',
                'message' => __('Your subscription is active. Welcome aboard.'),
            ]);
        }

        // Not confirmed yet (the webhook can lag a few seconds). The signup
        // provisioning screen already polls provider truth and hands off.
        return redirect()->route('signup.success');
    }

    public function checkoutCancelled(Request $request, string $redemption): RedirectResponse
    {
        $row = PlatformPrivateOfferRedemption::query()->where('uid', $redemption)->firstOrFail();

        try {
            $this->offers->handleCancel($row, $request->user());
        } catch (PlatformBillingException) {
            abort(404);
        }

        return redirect()->route('user.home')->with([
            'status' => 'info',
            'message' => __('No payment was taken. Your offer link still works until it expires.'),
        ]);
    }

    private function private(Response $response): Response
    {
        return $response
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
