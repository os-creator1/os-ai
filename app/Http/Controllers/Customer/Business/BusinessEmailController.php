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
use App\Exceptions\BusinessEmail\BusinessEmailImapException;
use App\Http\Requests\BusinessEmail\ConnectOtherEmailRequest;
use App\Http\Requests\BusinessEmail\ReplyBusinessEmailRequest;
use App\Http\Requests\BusinessEmail\SendBusinessEmailRequest;
use App\Library\BusinessEmail\BusinessEmailAccountManager;
use App\Library\BusinessEmail\BusinessEmailContactResolver;
use App\Library\BusinessEmail\BusinessEmailInboundSyncer;
use App\Library\BusinessEmail\BusinessEmailLocationScope;
use App\Library\BusinessEmail\BusinessEmailOAuthConfig;
use App\Library\BusinessEmail\BusinessEmailOAuthStateSigner;
use App\Library\BusinessEmail\BusinessEmailProviderRegistry;
use App\Library\BusinessEmail\BusinessEmailSender;
use App\Library\BusinessEmail\Imap\ImapPresets;
use App\Library\BusinessEmail\Imap\ImapSettings;
use App\Library\BusinessEmail\ImapBusinessEmailProvider;
use App\Library\Workspace\WorkspaceManagementAuthority;
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
        private readonly BusinessEmailLocationScope $locations,
        private readonly WorkspaceManagementAuthority $authority,
        private readonly BusinessEmailInboundSyncer $inbound,
        private readonly BusinessEmailProviderRegistry $registry,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid, true);

        // The Workspace owner, or either email permission, may view the page.
        // Throwing the same exception authorize() throws keeps a missing
        // permission a 401, like every other permission-gated customer action.
        $canManage = $this->authority->mayManage($workspace, 'manage_business_email');

        if (! $canManage && ! Gate::allows('chat_box')) {
            throw new AuthorizationException();
        }

        $account = $this->accounts->findForBusiness($business);
        $canSend = Gate::allows('chat_box') && Gate::allows('view_contact') && $account?->isActive() === true;

        // Location ACL (LocationAccessGuard, taken ONCE): the actor's reach
        // scopes the recent-email list and the Contact picker in SQL, so
        // neither leaks another Location's records and neither is N+1.
        $accessible = $this->locations->accessibleLocationIds((int) Auth::id(), $business);

        $messageQuery = BusinessEmailMessage::query()->where('business_id', $business->id);
        $this->locations->restrictMessages($messageQuery, (int) Auth::id(), $business);

        $messages = $messageQuery
            ->orderByDesc('id')
            ->limit(self::RECENT_MESSAGES)
            ->get(['id', 'uid', 'direction', 'contact_id', 'from_email', 'to_email', 'subject', 'status', 'failure_category', 'created_at']);

        $locationChoices = $this->locations->activeLocations($business, $accessible);

        return view('customer.business.email.show', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'account' => $account,
            'canManage' => $canManage,
            'canSend' => $canSend,
            'providerOptions' => $this->providerOptions(),
            'imapSummary' => $this->imapSummary($account),
            'inboundFailureText' => $this->inboundFailureText($account?->inbound_failure),
            'contacts' => $canSend ? $this->contacts->emailableContacts($business, 50, $accessible) : [],
            // Asked for only when there is a real choice to make.
            'locationChoices' => $locationChoices['activeTotal'] > 1 ? $locationChoices['selectable'] : collect(),
            'messages' => $messages,
            // Inbound mail needs the read permission; older connections must reauthorize.
            'inboundReady' => $account !== null && $this->inbound->canSync($account),
            'inboundNeedsReauth' => $account?->isActive() === true && $this->inbound->supportsInbound($account) && ! $this->inbound->canSync($account),
            'sendToken' => (string) Str::uuid(),
        ]);
    }

    /**
     * The OAuth providers are ALWAYS listed. One that is not configured on this
     * platform is shown as unavailable with the reason, never silently hidden.
     *
     * @return list<array<string, mixed>>
     */
    private function providerOptions(): array
    {
        $options = [];

        foreach ([
            [BusinessEmailProviderType::Google, 'Google', 'Gmail and Google Workspace.', 'Connect Google'],
            [BusinessEmailProviderType::Microsoft, 'Microsoft', 'Outlook.com, Hotmail, Microsoft 365 and Exchange Online.', 'Connect Microsoft'],
        ] as [$type, $title, $help, $button]) {
            $options[] = [
                'type' => $type->value,
                'title' => $title,
                'help' => $help,
                'button' => $button,
                'usable' => $this->oauthConfig->isUsable($type),
                'unavailable' => $title . ' sign-in is not set up on this platform yet. Please contact support; you can use "Connect Other Email" meanwhile if your mailbox supports IMAP/SMTP.',
            ];
        }

        return $options;
    }

    /** @return array{imap: string, smtp: string}|null */
    private function imapSummary(?\App\Models\BusinessEmailAccount $account): ?array
    {
        if ($account === null || ! $account->isActive() || $account->provider !== BusinessEmailProviderType::Imap) {
            return null;
        }

        try {
            $settings = ImapSettings::fromBlob((string) $account->refresh_token_encrypted);
        } catch (BusinessEmailImapException) {
            return null;
        }

        return ['imap' => $settings->imapHost . ':' . $settings->imapPort, 'smtp' => $settings->smtpHost . ':' . $settings->smtpPort];
    }

    private function inboundFailureText(?string $failure): string
    {
        return match ($failure) {
            null, '' => '',
            BusinessEmailInboundSyncer::NEEDS_REAUTHORIZATION => 'the provider no longer lets us read this mailbox. Reauthorize the connection.',
            'authentication_expired' => 'the provider rejected the saved sign-in. Reconnect the mailbox.',
            'provider_rate_limited' => 'the provider is limiting requests; we will retry automatically.',
            'temporary_provider_failure' => 'the provider is not answering; we will retry automatically.',
            default => 'the last check did not finish; we will retry automatically.',
        };
    }

    /**
     * "Connect Other Email": validate, auto-detect blank server settings, TEST
     * both directions against the real servers, and only then store the
     * encrypted credential. A failed test changes nothing.
     */
    public function connectOther(ConnectOtherEmailRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [$workspace, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid, true);
        $this->authorizeManage($workspace);

        $input = $request->validated();
        $input['username'] = trim((string) ($input['username'] ?? '')) !== '' ? $input['username'] : (string) $input['email'];
        $flash = ['status' => 'error'];

        $needsDetection = trim((string) ($input['imap_host'] ?? '')) === '' || trim((string) ($input['smtp_host'] ?? '')) === '';

        if ($needsDetection) {
            $detected = app(ImapPresets::class)->detect(strtolower(trim((string) $input['email'])));

            if ($detected['use_oauth'] !== null || $detected['settings'] === null) {
                return $this->backWithInput($workspaceUid, $businessUid, $request, $detected['note'] ?? 'We could not work out your mail server settings. Enter the IMAP and SMTP servers your provider gave you.');
            }

            foreach ($detected['settings'] as $key => $value) {
                if (trim((string) ($input[$key] ?? '')) === '') {
                    $input[$key] = $value;
                }
            }
        }

        try {
            $settings = ImapSettings::validated($input);
            $provider = $this->registry->for(BusinessEmailProviderType::Imap);
            assert($provider instanceof ImapBusinessEmailProvider);
            $provider->testConnection($settings);
        } catch (BusinessEmailImapException $exception) {
            Log::info('Other email connection test failed.', ['business_id' => $business->id, 'reason' => $exception->reason]);

            return $this->backWithInput($workspaceUid, $businessUid, $request, $exception->customerMessage());
        }

        try {
            $this->accounts->connectWithCredentials($business, (int) Auth::id(), BusinessEmailProviderType::Imap, $settings->email, $settings->toBlob());
        } catch (BusinessEmailConcurrencyException $exception) {
            return $this->back($workspaceUid, $businessUid, 'error', $exception->userMessage());
        } catch (LogicException $exception) {
            return $this->back($workspaceUid, $businessUid, 'error', $exception->getMessage());
        }

        return $this->back($workspaceUid, $businessUid, 'success', 'Email account connected. Receiving and sending both passed the connection test.');
    }

    private function backWithInput(string $workspaceUid, string $businessUid, Request $request, string $message): RedirectResponse
    {
        return $this->back($workspaceUid, $businessUid, 'error', $message)->withInput($request->except(['password']));
    }

    /** CSRF-protected POST: it mutates connection state and the nonce. */
    public function connect(Request $request, string $workspaceUid, string $businessUid, string $provider): RedirectResponse
    {
        [$workspace, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid, true);
        $this->authorizeManage($workspace);

        $providerType = BusinessEmailProviderType::tryFrom($provider);
        abort_if($providerType === null, 404);

        try {
            $url = $this->accounts->beginConnect($business, (int) Auth::id(), $providerType, $request->boolean('reauthorize'));
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
        [$workspace, $business] = $this->resolveBusinessTenancy((string) $workspace->uid, (string) $business->uid, true);

        // 404 (not 401): this route has no tenant parameters, so a
        // permission-shaped answer would disclose that the Business exists.
        if (! $this->authority->mayManage($workspace, 'manage_business_email')) {
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
            return $this->back($workspaceUid, $businessUid, 'error', $account->state === \App\Enums\BusinessEmail\BusinessEmailAccountState::Active
                ? 'The email provider did not complete the new authorization. Your existing connection is unchanged.'
                : 'The email provider did not complete the connection.');
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
        [$workspace, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid, true);
        $this->authorizeManage($workspace);

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

        // Resolved THROUGH the Business, then through the Location ACL: another
        // Business's Contact, an unknown uid and a Contact in a Location this
        // actor cannot reach are one and the same 404 (the CRM shape), with no
        // message row and no provider call.
        $contact = Contacts::query()
            ->where('business_id', $business->id)
            ->where('uid', (string) $request->validated('contact_uid'))
            ->first();

        abort_if($contact === null || ! $this->locations->contactAccessible((int) Auth::id(), $contact), 404);

        // An explicit Location must be one of this Business's ACTIVE Locations
        // that the actor may work in; anything else is the same 404.
        $location = null;
        $locationUid = (string) $request->validated('location_uid', '');

        if ($locationUid !== '') {
            $accessible = $this->locations->accessibleLocationIds((int) Auth::id(), $business);
            $location = $this->locations->activeLocations($business, $accessible)['selectable']->firstWhere('uid', $locationUid);

            abort_if($location === null, 404);
        }

        try {
            $message = $this->sender->send(new BusinessEmailSendRequest(
                business: $business,
                contact: $contact,
                subject: (string) $request->validated('subject'),
                bodyText: (string) $request->validated('body'),
                operationKey: 'manual:' . $request->validated('send_token'),
                source: BusinessEmailSource::Manual,
                location: $location,
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

    /**
     * Replies to one recorded message (inbound or outbound) in its own thread.
     * The message is resolved THROUGH the Business and the actor's Location
     * ACL; anything else is the same 404 with no row and no provider call.
     */
    public function reply(ReplyBusinessEmailRequest $request, string $workspaceUid, string $businessUid, string $messageUid): RedirectResponse
    {
        $this->authorize('chat_box');
        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid, true);

        $originalQuery = BusinessEmailMessage::query()
            ->where('business_id', $business->id)
            ->where('uid', $messageUid);
        $this->locations->restrictMessages($originalQuery, (int) Auth::id(), $business);
        $original = $originalQuery->first();

        abort_if($original === null, 404);

        // A sender who is not a Contact is fine: the recipient is the stored
        // message's counterpart (never browser input) and no Contact is created.
        $contact = $original->contact_id !== null
            ? Contacts::query()->where('business_id', $business->id)->find($original->contact_id)
            : null;

        // A contact-linked thread additionally needs the contact permission.
        if ($original->contact_id !== null) {
            $this->authorize('view_contact');
        }

        abort_if($original->contact_id !== null && ($contact === null || ! $this->locations->contactAccessible((int) Auth::id(), $contact)), 404);

        $location = null;
        $locationUid = (string) $request->validated('location_uid', '');

        if ($locationUid !== '') {
            $accessible = $this->locations->accessibleLocationIds((int) Auth::id(), $business);
            $location = $this->locations->activeLocations($business, $accessible)['selectable']->firstWhere('uid', $locationUid);

            abort_if($location === null, 404);
        }

        $back = fn (string $status, string $message) => $request->validated('return_to') === 'conversations'
            ? redirect()->route('customer.workspaces.businesses.conversations.email', [$workspaceUid, $businessUid, 'thread' => $original->provider_thread_id ?? $original->uid])
                ->with(['status' => $status, 'message' => $message])
            : $this->back($workspaceUid, $businessUid, $status, $message);

        try {
            $message = $this->sender->send(new BusinessEmailSendRequest(
                business: $business,
                contact: $contact,
                subject: (string) $request->validated('subject'),
                bodyText: (string) $request->validated('body'),
                operationKey: 'reply:' . $request->validated('send_token'),
                source: BusinessEmailSource::Manual,
                location: $location,
                sentByUserId: (int) Auth::id(),
                inResponseTo: $original,
            ));
        } catch (BusinessEmailSendRefusedException $exception) {
            return $back('error', $exception->userMessage());
        }

        return $message->status === BusinessEmailMessageStatus::Accepted
            ? $back('success', 'Reply sent.')
            : $back('error', $message->failure_category?->customerMessage() ?? 'The reply could not be sent.');
    }

    /** Same 401 a missing permission has always been on the tenant-addressed routes. */
    private function authorizeManage(Workspace $workspace): void
    {
        if (! $this->authority->mayManage($workspace, 'manage_business_email')) {
            throw new AuthorizationException();
        }
    }

    private function back(string $workspaceUid, string $businessUid, string $status, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.email.show', [$workspaceUid, $businessUid])
            ->with(['status' => $status, 'message' => $message]);
    }
}
