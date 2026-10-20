<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Http\Controllers\Controller;
use App\Library\AgencySite\AgencySiteSignupService;
use App\Library\AgencySite\SiteRenderer;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencySaasPlan;
use App\Models\AgencySite;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * White Label — "pick a plan" on an Agency's published site becomes a managed customer account and a Stripe
 * Checkout on the Agency's own connected account.
 *
 * Only a LIVE site of a usable Agency can take sign-ups, and only for a plan that Agency has published and can
 * currently sell; everything else is the same plain 404. The visitor supplies who they are and which business;
 * which Agency, which Stripe account and which price are never read from the request.
 */
class AgencySiteSignupController extends Controller
{
    public function __construct(
        private readonly AgencySiteSignupService $signups,
        private readonly SiteRenderer $renderer,
        private readonly AgencyClientRelationshipManager $relationships,
        private readonly AgencyWhiteLabelManager $whiteLabel,
    ) {
    }

    public function show(string $publicId, string $planUid): View
    {
        [$site, $plan, $values] = $this->resolve($publicId, $planUid);
        $mg = $this->renderer->site($site, $values);

        return view('agency-site.signup', [
            'mg' => $mg,
            'page' => 'signup',
            'meta' => ['title' => 'Sign up — '.$mg->brandName()] + $mg->meta('pricing'),
            'plan' => $mg->plans()[$plan->uid] ?? abort(404),
            'publicId' => $publicId,
            'planUid' => $planUid,
            'terms' => $mg->link('terms'),
            'privacy' => $mg->link('privacy'),
            'disclosure' => $values['x.disclosure'] ?? null,
        ]);
    }

    public function store(Request $request, string $publicId, string $planUid): RedirectResponse
    {
        [$site, $plan] = $this->resolve($publicId, $planUid);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100', 'regex:/^[\pL\s\-\'\.]+$/u'],
            'last_name' => ['nullable', 'string', 'max:100', 'regex:/^[\pL\s\-\'\.]+$/u'],
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            'business_name' => ['required', 'string', 'max:120'],
            'accept' => ['accepted'],
        ], ['accept.accepted' => 'Please accept the terms to continue.']);

        try {
            $result = $this->signups->start(
                $site,
                $plan,
                $data,
                $request->ip(),
                fn (Workspace $client) => route('customer.workspaces.agency-plan.return', [$client->uid]),
                route('agency.site.page', [$publicId, 'pricing']),
            );
        } catch (ValidationException $e) {
            return back()->withInput($request->except(['password', 'password_confirmation']))->withErrors($e->errors());
        } catch (AgencyBillingException $e) {
            return back()->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['plan' => $e->customerMessage()]);
        }

        // The payer is signed in so the Checkout return (and their new workspace) are theirs from the start.
        Auth::login($result['user']);
        $request->session()->regenerate();

        return redirect()->away((string) $result['checkout']->url);
    }

    /** @return array{0: AgencySite, 1: AgencySaasPlan, 2: array<string, mixed>} */
    private function resolve(string $publicId, string $planUid): array
    {
        $site = AgencySite::query()->where('public_id', $publicId)->first();
        abort_if($site === null || ! $site->isLive(), 404);

        $agency = Workspace::query()->find($site->agency_workspace_id);
        abort_if($agency === null || ! $agency->is_active
            || ! $this->relationships->agencyWorkspaceHasManagementEligibility($agency)
            || ! $this->whiteLabel->isEntitled($agency), 404);

        $values = (array) ($site->publishedRevision?->config['values'] ?? []);
        $plan = AgencySaasPlan::query()->where('agency_workspace_id', $agency->id)->where('uid', $planUid)->first();
        abort_if($plan === null || ! $plan->is_published || ! $plan->isSellable(), 404);

        // The published plan list must actually offer subscriptions on this site.
        $mg = $this->renderer->site($site, $values);
        abort_unless($mg->context()->offersSubscriptions() && isset($mg->plans()[$plan->uid]), 404);

        return [$site, $plan, $values];
    }
}
