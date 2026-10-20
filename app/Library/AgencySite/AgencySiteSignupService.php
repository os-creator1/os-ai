<?php

namespace App\Library\AgencySite;

use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Library\AgencyBilling\AgencyCheckoutSessionResult;
use App\Library\AgencyBilling\AgencyClientSubscriptionManager;
use App\Library\Workspace\AgencyClientProvisioningManager;
use App\Models\AgencySaasPlan;
use App\Models\AgencySite;
use App\Models\AgencySiteSignup;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * White Label — a visitor on an Agency's site chooses a plan and becomes that Agency's managed customer.
 *
 * It orchestrates existing, separately-tested machinery and adds no billing or provisioning logic of its own:
 *   account      UserRepository::store (the platform's own user + customer creation)
 *   workspace    AgencyClientProvisioningManager::provisionClientWorkspace (Workspace, Business, Location and the
 *                ACTIVE Agency relationship — the same steps the invitation flow runs)
 *   commercial   AgencyClientSubscriptionManager::offer + startCheckout (Stripe Checkout on the AGENCY's connected
 *                account; the payer's own consent; no paid state until the webhook / return re-reads Stripe)
 *
 * Attribution is structural: the Agency is read from the site row the visitor is on, the plan must belong to that
 * Agency, and the resulting relationship, offer and Stripe session all carry that Agency's ids. Nothing about the
 * Agency is accepted from the form.
 *
 * Idempotent per (Agency, email): a double submit, refresh or later retry resumes the same account and workspace
 * — it never creates a second one — and only an actor who can prove the password may resume.
 *
 * No entitlement is granted here. The subscription finalizer assigns the purchased tier when Stripe reports a paid
 * state; until then the new workspace has no paid plan.
 */
final class AgencySiteSignupService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AgencyClientProvisioningManager $provisioning,
        private readonly AgencyClientSubscriptionManager $subscriptions,
    ) {
    }

    /**
     * @param  \Closure(Workspace): string  $successUrl  where Stripe returns the payer, given their new workspace
     * @param  array{first_name: string, last_name?: ?string, email: string, password: string, business_name: string}  $input
     * @return array{signup: AgencySiteSignup, user: User, checkout: AgencyCheckoutSessionResult}
     *
     * @throws ValidationException
     * @throws AgencyBillingException
     */
    public function start(AgencySite $site, AgencySaasPlan $plan, array $input, ?string $ip, \Closure $successUrl, string $cancelUrl): array
    {
        if ((int) $plan->agency_workspace_id !== (int) $site->agency_workspace_id) {
            throw ValidationException::withMessages(['plan' => 'That plan is not available.']);
        }

        $agency = Workspace::query()->findOrFail($site->agency_workspace_id);
        $email = mb_strtolower(trim($input['email']));
        $businessName = trim($input['business_name']);

        $signup = AgencySiteSignup::query()
            ->where('agency_workspace_id', $agency->id)
            ->where('email', $email)
            ->first();

        $user = $signup !== null && $signup->user_id !== null ? User::query()->find($signup->user_id) : null;

        if ($signup !== null) {
            // Resuming is for the person who created the account, proven by its password.
            if ($user === null || ! Hash::check($input['password'], (string) $user->password)) {
                throw ValidationException::withMessages(['email' => 'We could not continue with those details. Sign in if you already have an account.']);
            }
        } else {
            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                // Never attach an Agency relationship to — or reveal — an account that already exists.
                throw ValidationException::withMessages(['email' => 'We could not create an account with those details. Sign in if you already have an account.']);
            }

            try {
                [$signup, $user] = $this->provision($site, $plan, $agency, $input, $email, $businessName, $ip);
            } catch (QueryException $e) {
                // Two simultaneous submits: the unique (Agency, email) row won for the other request.
                $signup = AgencySiteSignup::query()->where('agency_workspace_id', $agency->id)->where('email', $email)->first();
                $user = $signup?->user_id ? User::query()->find($signup->user_id) : null;

                if ($signup === null || $user === null || ! Hash::check($input['password'], (string) $user->password)) {
                    throw $e;
                }
            }
        }

        $client = Workspace::query()->findOrFail($signup->client_workspace_id);

        // Offer (or re-offer after an unpaid attempt) the chosen plan, then open Checkout as the payer.
        $subscription = $this->subscriptions->findForClientWorkspace($client);

        if ($subscription !== null && $subscription->status->isLive()) {
            throw ValidationException::withMessages(['email' => 'This account already has an active subscription. Sign in to manage it.']);
        }

        $this->subscriptions->offer((int) $agency->owner_user_id, $agency, $client, $plan);

        $checkout = $this->subscriptions->startCheckout((int) $user->id, $client, (string) $user->email, $successUrl($client), $cancelUrl);

        $signup->forceFill(['status' => AgencySiteSignup::CHECKOUT_STARTED, 'agency_saas_plan_id' => $plan->id])->save();

        return ['signup' => $signup, 'user' => $user, 'checkout' => $checkout];
    }

    /** @return array{0: AgencySiteSignup, 1: User} */
    private function provision(AgencySite $site, AgencySaasPlan $plan, Workspace $agency, array $input, string $email, string $businessName, ?string $ip): array
    {
        return DB::transaction(function () use ($site, $plan, $agency, $input, $email, $businessName, $ip): array {
            $user = $this->users->store([
                'first_name' => trim($input['first_name']),
                'last_name' => trim((string) ($input['last_name'] ?? '')),
                'email' => $email,
                'password' => $input['password'],
                'status' => true,
                'phone' => null,
                'is_customer' => true,
            ]);

            if (config('account.verify_account')) {
                $user->sendEmailVerificationNotification();
            }

            /** @var Customer $customer */
            $customer = Customer::query()->where('user_id', $user->id)->firstOrFail();

            $client = $this->provisioning->provisionClientWorkspace(
                $user,
                $customer,
                $agency,
                (int) $agency->owner_user_id,
                $businessName,
                $businessName,
            );

            $signup = new AgencySiteSignup([
                'agency_workspace_id' => $agency->id,
                'agency_site_id' => $site->id,
                'agency_saas_plan_id' => $plan->id,
                'email' => $email,
                'business_name' => $businessName,
                'user_id' => $user->id,
                'client_workspace_id' => $client->id,
                'status' => AgencySiteSignup::PROVISIONED,
                'consent_ip' => $ip,
                'terms_accepted_at' => now(),
            ]);
            $signup->generateUid();
            $signup->save();

            return [$signup, $user];
        });
    }
}
