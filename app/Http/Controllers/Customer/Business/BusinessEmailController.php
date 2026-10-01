<?php

namespace App\Http\Controllers\Customer\Business;

use App\DTO\BusinessEmail\BusinessEmailSendRequest;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Enums\BusinessEmail\BusinessEmailSource;
use App\Exceptions\BusinessEmail\BusinessEmailConcurrencyException;
use App\Exceptions\BusinessEmail\BusinessEmailConfigurationException;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Exceptions\BusinessEmail\BusinessEmailSendRefusedException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\BusinessEmail\SendBusinessEmailRequest;
use App\Library\BusinessEmail\BusinessEmailAccountManager;
use App\Library\BusinessEmail\BusinessEmailContactResolver;
use App\Library\BusinessEmail\BusinessEmailOAuthConfig;
use App\Library\BusinessEmail\BusinessEmailOAuthStateSigner;
use App\Library\BusinessEmail\BusinessEmailSender;
use App\Models\Business;
use App\Models\BusinessEmailMessage;
use App\Models\Contacts;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

/**
 * Settings → Email: connect / reconnect / disconnect the Business's own
 * Google or Microsoft mailbox, see its status, and send one email to a
 * Contact through the canonical BusinessEmailSender.
 *
 * Every Business-scoped action runs the canonical tenancy chain
 * (Workspace → Business → BusinessRouteAccess → active Business) via
 * ResolvesBusinessTenancy; every failure is 404, never 403. There is no
 * Email entitlement gate: no PlatformFeature exists for it and none is
 * invented here.
 *
 * THE OAUTH CALLBACK IS ONE FIXED, TENANT-FREE ROUTE per provider (providers
 * match redirect_uri exactly). It never authenticates or creates a user and
 * never trusts the provider-supplied mailbox as authorization: the Business
 * comes ONLY from the signed state, and the whole tenancy + permission chain
 * is re-run, and the initiating actor re-checked, BEFORE the nonce is
 * consumed or any code exchanged.
 */
class BusinessEmailController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    private const RECENT_MESSAGES = 10;

    public function __construct(
        private readonly BusinessEmailAccountManager $accounts,
        private readonly BusinessEmailOAuthStateSigner $stateSigner,
        private readonly BusinessEmailOAuthConfig $oauthConfig,
        private readonly BusinessEmailSender $sender,
        private readonly BusinessEmailContactResolver $contacts,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View
    {
        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid, true);

        // Either email permission may view the page. Throwing the same
        // exception authorize() throws keeps a missing permission a 401, like
        // every other permission-gated customer action.
        if (! Gate::any(['manage_business_email', 'chat_box'])) {
            throw new AuthorizationException();
        }

        $account = $this->accounts->findForBusiness($business);
        $canManage = Gate::allows('manage_business_email');
        $canSend = Gate::allows('chat_box') && Gate::allows('view_contact') && $account?->isActive() === true;

        $messages = BusinessEmailMessage::query()
            ->where('business_id', $business->id)
            ->orderByDesc('id')
            ->limit(self::RECENT_MESSAGES)
            ->get(['uid', 'to_email', 'subject', 'status', 'failure_category', 'created_at']);

        return view('customer.business.email.show', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'account' => $account,
            'canManage' => $canManage,
            'canSend' => $canSend,
            'providers' => collect(BusinessEmailProviderType::cases())
                ->filter(fn (BusinessEmailProviderType $provider) => $this->oauthConfig->isUsable($provider))
                ->values(),
            'contacts' => $canSend ? $this->contacts->emailableContacts($business) : [],
            'messages' => $messages,
            'sendToken' => (string) Str::uuid(),
        ]);
    }

    /** CSRF-protected POST: it mutates connection state and the nonce. */
    public function connect(string $workspaceUid, string $businessUid, string $provider): RedirectResponse
    {
        $this->authorize('manage_business_email');
        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid, true);

        $providerType = BusinessEmailProviderType::tryFrom($provider);
        abort_if($providerType === null, 404);

        try {
            $url = $this->accounts->beginConnect($business, (int) Auth::id(), $providerType);
        } catch (BusinessEmailConfigurationException $exception) {
            Log::error('Business email configuration error.', [
                'reason' => $exception->reason,
                'provider' => $exception->provider,
                'operator_message' => $exception->operatorMessage(),
                'business_id' => $business->id,
            ]);

            return $this->back($workspaceUid, $businessUid, 'error', $exception->customerMessage());
        } catch (BusinessEmailConcurrencyException $exception) {
            return $this->back($workspaceUid, $businessUid, 'error', $exception->userMessage());
        } catch (LogicException $exception) {
            return $this->back($workspaceUid, $businessUid, 'error', $exception->getMessage());
        }

        return redirect()->away($url);
    }

    /**
     * Validation order — every failure is 404 with ZERO token exchange and no
     * disclosure of whether a Business exists:
     *   1. signed state present, signature valid, not expired
     *   2. the route's provider equals the state's provider
     *   3. Business and account come ONLY from the signed state
     *   4. the whole tenancy chain re-run for the CURRENT user
     *   5. manage permission
     *   6. the callback actor is the actor who initiated THIS attempt
     *   7. only then is the nonce consumed, atomically and once
     *   8. only after consumption is the code exchanged
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $providerType = BusinessEmailProviderType::tryFrom($provider);
        abort_if($providerType === null, 404);

        $payload = $this->stateSigner->verify($request->query('state'));

        if ($payload === null || $payload['p'] !== $providerType->value) {
            abort(404);
        }

        $business = Business::query()->find($payload['b']);

        if ($business === null || $business->workspace_id === null) {
            abort(404);
        }

        $workspace = Workspace::query()->find($business->workspace_id);

        if ($workspace === null) {
            abort(404);
        }

        // Aborts 404 unless THIS user may use THIS Business right now.
        [, $business] = $this->resolveBusinessTenancy((string) $workspace->uid, (string) $business->uid, true);

        // 404 (not 401): this route has no tenant parameters, so a
        // permission-shaped answer would disclose that the Business exists.
        if (Gate::denies('manage_business_email')) {
            abort(404);
        }

        $account = $this->accounts->findForBusiness($business);

        if ($account === null || $account->provider !== $providerType) {
            abort(404);
        }

        if (! $this->accounts->attemptBelongsToActor($account, (int) Auth::id())) {
            abort(404);
        }

        if (! $this->stateSigner->consume((int) $business->id, $providerType, $payload['n'])) {
            abort(404);
        }

        $workspaceUid = (string) $workspace->uid;
        $businessUid = (string) $business->uid;

        if ($request->query('error') !== null || $request->query('code') === null) {
            return $this->back($workspaceUid, $businessUid, 'error', 'The email provider did not complete the connection.');
        }

        try {
            $this->accounts->completeConnect($account->fresh(), (string) $request->query('code'));
        } catch (BusinessEmailConcurrencyException $exception) {
            return $this->back($workspaceUid, $businessUid, 'error', $exception->userMessage());
        } catch (BusinessEmailProviderException $exception) {
            Log::warning('Business email connection could not be completed.', [
                'business_id' => $business->id,
                'provider' => $providerType->value,
                'category' => $exception->category->value,
                'provider_code' => $exception->providerCode,
            ]);

            return $this->back($workspaceUid, $businessUid, 'error', $exception->userMessage());
        } catch (LogicException $exception) {
            return $this->back($workspaceUid, $businessUid, 'error', $exception->getMessage());
        }

        return $this->back($workspaceUid, $businessUid, 'success', 'Email account connected.');
    }

    public function disconnect(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('manage_business_email');
        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid, true);

        $account = $this->accounts->findForBusiness($business);

        if ($account === null) {
            return $this->back($workspaceUid, $businessUid, 'success', 'No email account is connected.');
        }

        try {
            $this->accounts->disconnect($account);
        } catch (BusinessEmailConcurrencyException $exception) {
            return $this->back($workspaceUid, $businessUid, 'error', $exception->userMessage());
        }

        return $this->back($workspaceUid, $businessUid, 'success', 'Email account disconnected and its stored authorization destroyed.');
    }

    public function send(SendBusinessEmailRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('chat_box');
        $this->authorize('view_contact');
        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid, true);

        // Resolved THROUGH the Business: another Business's Contact uid is
        // indistinguishable from an unknown one.
        $contact = Contacts::query()
            ->where('business_id', $business->id)
            ->where('uid', (string) $request->validated('contact_uid'))
            ->first();

        if ($contact === null) {
            return $this->back($workspaceUid, $businessUid, 'error', 'That contact could not be found.');
        }

        try {
            $message = $this->sender->send(new BusinessEmailSendRequest(
                business: $business,
                contact: $contact,
                subject: (string) $request->validated('subject'),
                bodyText: (string) $request->validated('body'),
                operationKey: 'manual:' . $request->validated('send_token'),
                source: BusinessEmailSource::Manual,
                sentByUserId: (int) Auth::id(),
            ));
        } catch (BusinessEmailSendRefusedException $exception) {
            return $this->back($workspaceUid, $businessUid, 'error', $exception->userMessage());
        }

        return match ($message->status) {
            BusinessEmailMessageStatus::Accepted => $this->back($workspaceUid, $businessUid, 'success', 'Email sent. Your email provider accepted it for delivery.'),
            BusinessEmailMessageStatus::Failed => $this->back($workspaceUid, $businessUid, 'error', $message->failure_category?->customerMessage() ?? 'The email could not be sent.'),
            BusinessEmailMessageStatus::Unconfirmed => $this->back($workspaceUid, $businessUid, 'error', 'We could not confirm whether this email was sent. Check your sent mail before trying again.'),
            default => $this->back($workspaceUid, $businessUid, 'success', 'This email is already being sent.'),
        };
    }

    private function back(string $workspaceUid, string $businessUid, string $status, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.email.show', [$workspaceUid, $businessUid])
            ->with(['status' => $status, 'message' => $message]);
    }
}
