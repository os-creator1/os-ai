<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Controllers\Customer\Workspace\Concerns\ResolvesAgencyProspectingWorkspace;
use App\Library\AgencyProspecting\AgencyProspectingWebhookToken;
use App\Library\AgencyOutreach\AgencyByoSender;
use App\Library\AgencyOutreach\AgencyOutreachBusinessResolver;
use App\Library\AgencyOutreach\MessagingReadinessReader;
use App\Library\AgencyProspecting\AgencyProspectPhoneNormalizer;
use App\Library\AgencyProspecting\TelnyxCredentialVerifier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Security\SealedSecret;
use App\Models\AgencyProspectingChannel;
use App\Models\Customer;
use App\Models\SendingServer;
use App\Models\Workspace;
use App\Repositories\Contracts\SendingServerRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Agency AI Prospecting runtime pass — the Workspace-owned sending-channel
 * sub-page ("Prospecting -> Channels"), filling the foundation pass's own
 * explicitly-deferred gap: no Workspace-level provider ownership existed.
 * Deliberately mirrors Business\MessagingChannelsController's own hard
 * provider allowlist and dedicated-non-shared-SendingServer discipline —
 * never a Business's CustomerBasedSendingServer assignment, never a
 * Business's own phone/SenderID. Every action shares the exact same
 * Workspace-role + active-Workspace + ProspectOutreach-entitlement
 * boundary as the rest of Prospecting (ResolvesAgencyProspectingWorkspace).
 */
class AgencyProspectingChannelController extends CustomerBaseController
{
    use ResolvesAgencyProspectingWorkspace;

    private const ALLOWED_PROVIDERS = [
        SendingServer::TYPE_TWILIO => [
            'label' => 'Twilio',
            'credential_fields' => [
                'account_sid' => ['label' => 'Twilio account identifier', 'required' => true],
                'auth_token' => ['label' => 'Twilio secret', 'required' => true],
            ],
        ],
        SendingServer::TYPE_TELNYX => [
            'label' => 'Telnyx',
            'credential_fields' => [
                'api_key' => ['label' => 'Telnyx access key', 'required' => true],
                'c1' => ['label' => 'Messaging profile ID', 'required' => true],
                'c2' => ['label' => 'Messaging connection ID', 'required' => false],
                'webhook_public_key' => ['label' => 'Telnyx webhook public key (needed to receive replies)', 'required' => false],
            ],
        ],
    ];

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly EntitlementManager $entitlementManager,
        private readonly SendingServerRepository $sendingServers,
        private readonly TelnyxCredentialVerifier $telnyxVerifier,
    ) {
    }

    public function channels(string $workspaceUid): View|Factory|Application
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        return view('customer.workspaces.prospecting.channels.index', [
            'workspaceUid' => $workspaceUid,
            'channels' => AgencyProspectingChannel::where('workspace_id', $workspace->id)
                ->with('sendingServer')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    /**
     * Agency Settings -> Phone & Messaging: the one place the two setup paths
     * sit side by side. Option A lists this Workspace's own BYO channels;
     * Option B is read from the Agency's own Business via the SAME
     * MessagingReadinessReader Settings -> Text messaging and Outreach
     * readiness use, so the three can never disagree. Read-only: no provider
     * call, no purchase, no write.
     */
    public function phoneMessaging(string $workspaceUid, MessagingReadinessReader $readiness): View|Factory|Application
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);
        $channels = AgencyProspectingChannel::where('workspace_id', $workspace->id)
            ->where('status', '!=', AgencyProspectingChannel::STATUS_DISCONNECTED)
            ->with('sendingServer')
            ->orderByDesc('id')
            ->get();

        return view('customer.workspaces.prospecting.phone-messaging', [
            'workspaceUid' => $workspaceUid,
            'channels' => $channels,
            'readiness' => $channels->mapWithKeys(fn ($channel) => [$channel->id => AgencyByoSender::sendReadiness($channel)]),
            'business' => $business,
            'managed' => $business !== null ? $readiness->situation($business) : null,
            'defaultIsByo' => $channels->contains(fn ($channel) => (bool) $channel->is_default_sender),
        ]);
    }

    public function connect(string $workspaceUid, string $provider): View|Factory|Application|RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        if (! $this->isAllowedProvider($provider)) {
            return $this->channelsError($workspaceUid, 'Unsupported provider.');
        }

        return view('customer.workspaces.prospecting.channels.connect', [
            'workspaceUid' => $workspaceUid,
            'provider' => $provider,
            'providerLabel' => self::ALLOWED_PROVIDERS[$provider]['label'],
            'fields' => self::ALLOWED_PROVIDERS[$provider]['credential_fields'],
        ]);
    }

    /**
     * Resolves the SendingServer/CustomerBasedSendingServer owner-identity
     * question the runtime task raised explicitly: B2 derives this from a
     * Business's owner Customer identity, which does not exist for a
     * Workspace-level channel. The Workspace owner's OWN Customer record
     * (App\Models\Customer, keyed by user_id) is the correct analogous
     * primitive — never a Business, never an arbitrary/first/primary
     * Business's owner. WorkspaceManager::createWorkspace() proves a
     * Workspace owner is only required to be a valid User, never a
     * Customer, so this is independently verified here rather than
     * assumed; if absent, channel creation fails cleanly with no guess,
     * no fabricated Business, no borrowed Customer.
     */
    public function storeConnect(Request $request, string $workspaceUid, string $provider): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        if (! $this->isAllowedProvider($provider)) {
            return $this->channelsError($workspaceUid, 'Unsupported provider.');
        }

        if (config('app.stage') === 'demo') {
            return $this->channelsError($workspaceUid, 'Sorry! This option is not available in demo mode');
        }

        $senderNumber = AgencyProspectPhoneNormalizer::normalize((string) $request->input('sender_number'));

        if ($senderNumber === null) {
            return redirect()->route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, $provider])
                ->withErrors(['sender_number' => 'Enter a valid international phone number (include a country code).']);
        }

        if (AgencyProspectingChannel::where('workspace_id', $workspace->id)->where('sender_number', $senderNumber)->exists()) {
            return redirect()->route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, $provider])
                ->withErrors(['sender_number' => 'This Workspace already has a channel with this sender number.']);
        }

        // One number, one owner: never connectable by a second agency, nor when it is
        // already a number bought through MotionGrove. The message is deliberately
        // the same for both so it does not reveal who owns it.
        if (AgencyProspectingChannel::where('sender_number', $senderNumber)->where('status', '!=', AgencyProspectingChannel::STATUS_DISCONNECTED)->exists()
            || \App\Models\BusinessMessagingNumber::where('phone_number', '+' . $senderNumber)->exists()) {
            return redirect()->route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, $provider])
                ->withErrors(['sender_number' => 'This number is already connected to an account. Use a number you own that is not in use elsewhere.']);
        }

        [$errors, $credentials] = $this->validateCredentials($provider, $request->all(), false);

        $webhookKey = null;

        if ($provider === SendingServer::TYPE_TELNYX) {
            $webhookKey = $credentials['webhook_public_key'] ?? null;
            unset($credentials['webhook_public_key']);

            if ($webhookKey !== null && ! $this->isValidWebhookKey($webhookKey)) {
                $errors['webhook_public_key'] = 'That is not a valid Telnyx webhook public key. Copy it exactly from your Telnyx portal.';
            }
        }

        if (! empty($errors)) {
            return redirect()->route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, $provider])
                ->withErrors($errors);
        }

        if ($provider === SendingServer::TYPE_TELNYX) {
            $check = $this->telnyxVerifier->verify($credentials['api_key'], $credentials['c1'], $senderNumber);

            if (! $check['ok']) {
                return redirect()->route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, $provider])
                    ->withErrors(['api_key' => $check['message']]);
            }

            // Stored sealed (App\Library\Security\SealedSecret); never shown again.
            $credentials['api_key'] = SealedSecret::seal($credentials['api_key']);
        }

        $ownerCustomerExists = Customer::where('user_id', $workspace->owner_user_id)->exists();

        if (! $ownerCustomerExists) {
            return $this->channelsError(
                $workspaceUid,
                'This Workspace\'s owner has no valid Customer record required to own a sending connection; channel creation was not completed.'
            );
        }

        $metadata = $this->sendingServers->allSendingServer()[$provider];
        $input = array_merge($metadata, $credentials, [
            'settings' => $provider,
            'user_id' => $workspace->owner_user_id,
        ]);

        // Correction 1 — SendingServer.user_id stays bound to the
        // Workspace's own legitimate owner identity (the tenant/provider
        // ownership question this controller resolves above); an audit
        // column named created_by_user_id must instead record the actual
        // authenticated actor who performed this action (the owner, or an
        // active Workspace Admin) — never silently conflated with the
        // provider-owner identity.
        $channel = DB::transaction(function () use ($input, $workspace, $provider, $senderNumber, $webhookKey): AgencyProspectingChannel {
            $sendingServer = $this->sendingServers->store($input);

            return AgencyProspectingChannel::create([
                'workspace_id' => $workspace->id,
                'sending_server_id' => $sendingServer->id,
                'provider' => $provider,
                'sender_number' => $senderNumber,
                'status' => AgencyProspectingChannel::STATUS_ACTIVE,
                'created_by_user_id' => Auth::id(),
                'webhook_public_key' => $webhookKey !== null ? SealedSecret::seal($webhookKey) : null,
                'credentials_verified_at' => $provider === SendingServer::TYPE_TELNYX ? now() : null,
            ]);
        });

        // Connected is not the same as ready: say what is still missing.
        $message = self::ALLOWED_PROVIDERS[$provider]['label'] . ' account connected.';

        if ($provider === SendingServer::TYPE_TELNYX) {
            $message .= $webhookKey === null
                ? ' Add your Telnyx webhook public key and set the webhook URL before choosing it as your sender, so replies can be received.'
                : ' Set the webhook URL shown on this page in Telnyx, then choose it as your sender.';
        }

        return redirect()->route('customer.workspaces.prospecting.channels.show', [$workspaceUid, $channel->uid])->with([
            'flash_success' => $message,
        ]);
    }

    public function show(string $workspaceUid, AgencyProspectingChannel $channel): View|Factory|Application
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceChannel($workspace, $channel);

        $provider = $channel->provider;

        return view('customer.workspaces.prospecting.channels.show', [
            'workspaceUid' => $workspaceUid,
            'channel' => $channel,
            'providerLabel' => self::ALLOWED_PROVIDERS[$provider]['label'] ?? $provider,
            'fields' => self::ALLOWED_PROVIDERS[$provider]['credential_fields'] ?? [],
            'webhookUrl' => $this->webhookUrl($channel),
        ]);
    }

    /**
     * Replace credentials (change account / reconnect). Nothing is saved unless Telnyx
     * accepts the resulting key, profile and number together; on rejection the
     * existing connection is left exactly as it was. A blank field keeps its saved
     * value, and a saved key is never sent back to the browser.
     */
    public function update(Request $request, string $workspaceUid, AgencyProspectingChannel $channel): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceChannel($workspace, $channel);

        if (config('app.stage') === 'demo') {
            return $this->channelsError($workspaceUid, 'Sorry! This option is not available in demo mode');
        }

        $provider = $channel->provider;

        if (! $this->isAllowedProvider($provider)) {
            return $this->channelsError($workspaceUid, 'Unsupported provider.');
        }

        $back = fn (array $errors) => redirect()->route('customer.workspaces.prospecting.channels.show', [$workspaceUid, $channel->uid])->withErrors($errors);
        $reconnecting = $channel->status === AgencyProspectingChannel::STATUS_DISCONNECTED;

        [$errors, $credentials] = $this->validateCredentials($provider, $request->all(), ! $reconnecting);

        $webhookKey = null;

        if ($provider === SendingServer::TYPE_TELNYX) {
            $webhookKey = $credentials['webhook_public_key'] ?? null;
            unset($credentials['webhook_public_key']);

            if ($webhookKey !== null && ! $this->isValidWebhookKey($webhookKey)) {
                $errors['webhook_public_key'] = 'That is not a valid Telnyx webhook public key. Copy it exactly from your Telnyx portal.';
            }
        }

        if (! empty($errors)) {
            return $back($errors);
        }

        $server = $channel->sendingServer;
        $verifiedNow = false;

        if ($provider === SendingServer::TYPE_TELNYX && $server !== null && ! empty($credentials)) {
            $check = $this->telnyxVerifier->verify(
                $credentials['api_key'] ?? (string) $server->api_key,
                $credentials['c1'] ?? (string) $server->c1,
                (string) $channel->sender_number,
            );

            if (! $check['ok']) {
                $channel->forceFill(['last_error_code' => $check['reason']])->save();

                return $back(['api_key' => $check['message']]);
            }

            if (isset($credentials['api_key'])) {
                $credentials['api_key'] = SealedSecret::seal($credentials['api_key']);
            }

            $verifiedNow = true;
        }

        DB::transaction(function () use ($credentials, $server, $channel, $webhookKey, $verifiedNow, $reconnecting): void {
            if (! empty($credentials) && $server !== null) {
                $this->sendingServers->update($server, $credentials);
            }

            $attributes = [];

            if ($webhookKey !== null) {
                $attributes['webhook_public_key'] = SealedSecret::seal($webhookKey);
                $attributes['webhook_verified_at'] = null;
            }

            if ($verifiedNow) {
                $attributes['credentials_verified_at'] = now();
                $attributes['last_error_code'] = null;
            }

            if ($reconnecting && $verifiedNow) {
                // A reconnected account comes back switched on but is never made the
                // default sender automatically.
                $attributes['status'] = AgencyProspectingChannel::STATUS_ACTIVE;
                $attributes['disconnected_at'] = null;
            }

            if ($attributes !== []) {
                $channel->forceFill($attributes)->save();
            }
        });

        return redirect()->route('customer.workspaces.prospecting.channels.show', [$workspaceUid, $channel->uid])->with([
            'flash_success' => 'Channel updated.',
        ]);
    }

    public function enable(string $workspaceUid, AgencyProspectingChannel $channel): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceChannel($workspace, $channel);

        if ($channel->status === AgencyProspectingChannel::STATUS_DISCONNECTED) {
            return $this->channelsError($workspaceUid, 'This account was disconnected. Reconnect it with new credentials first.');
        }

        if (! $channel->sendingServer || ! $channel->sendingServer->status) {
            return $this->channelsError($workspaceUid, 'This provider is not currently available.');
        }

        if ($channel->provider === SendingServer::TYPE_TELNYX) {
            $credentials = AgencyByoSender::credentials($channel);
            $check = $credentials === null
                ? ['ok' => false, 'reason' => 'missing_credentials', 'message' => 'The saved Telnyx credentials can no longer be read. Reconnect your account.']
                : $this->telnyxVerifier->verify($credentials['api_key'], $credentials['profile_id'], (string) $channel->sender_number);

            if (! $check['ok']) {
                $channel->forceFill(['last_error_code' => $check['reason']])->save();

                return $this->channelsError($workspaceUid, 'Channel was not enabled: ' . $check['message']);
            }

            $channel->forceFill(['credentials_verified_at' => now(), 'last_error_code' => null]);
        }

        $channel->forceFill(['status' => AgencyProspectingChannel::STATUS_ACTIVE])->save();

        return redirect()->route('customer.workspaces.prospecting.channels.index', $workspaceUid)->with([
            'flash_success' => 'Channel enabled.',
        ]);
    }

    /**
     * Reversible pause: credentials are kept. If this is the default sender, sends are
     * then refused (and say why) rather than quietly moving to another number.
     */
    public function disable(string $workspaceUid, AgencyProspectingChannel $channel): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceChannel($workspace, $channel);

        if ($channel->status === AgencyProspectingChannel::STATUS_DISCONNECTED) {
            return $this->channelsError($workspaceUid, 'This account is already disconnected.');
        }

        $channel->update(['status' => AgencyProspectingChannel::STATUS_DISABLED]);

        return redirect()->route('customer.workspaces.prospecting.channels.index', $workspaceUid)->with([
            'flash_success' => 'Channel disabled.',
        ]);
    }

    /**
     * Permanent disconnect: the stored Telnyx key and webhook key are erased here; the
     * Telnyx account and number themselves are untouched. The row stays only so past
     * messages can still name the sender they used. The current default sender cannot
     * be disconnected — choose another sender first, so nothing is switched silently.
     */
    public function disconnect(string $workspaceUid, AgencyProspectingChannel $channel): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceChannel($workspace, $channel);

        if ($channel->is_default_sender) {
            return $this->channelsError($workspaceUid, 'This is your current sender. Choose a different sender in Phone & Messaging before disconnecting it.');
        }

        DB::transaction(function () use ($channel): void {
            if ($channel->sendingServer !== null) {
                $channel->sendingServer->forceFill(['api_key' => null, 'auth_token' => null, 'status' => false])->save();
            }

            $channel->forceFill([
                'status' => AgencyProspectingChannel::STATUS_DISCONNECTED,
                'is_default_sender' => false,
                'webhook_public_key' => null,
                'disconnected_at' => now(),
                'last_error_code' => null,
            ])->save();
        });

        return redirect()->route('customer.workspaces.prospecting.phone-messaging', $workspaceUid)->with([
            'flash_success' => 'Account disconnected. Its saved credentials were erased.',
        ]);
    }

    /**
     * Choose the agency's default sender: its own Telnyx channel, or the number bought
     * through MotionGrove ("managed"). Only changes which number NEW messages use —
     * no number moves between accounts and no conversation is touched.
     */
    public function setSender(Request $request, string $workspaceUid, MessagingReadinessReader $readiness): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $choice = (string) $request->input('sender');
        $back = fn (string $key, string $message) => redirect()->route('customer.workspaces.prospecting.phone-messaging', $workspaceUid)->with([$key => $message]);

        if ($choice === 'managed') {
            $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);

            if ($business === null || $readiness->situation($business)['state'] !== 'ready') {
                return $back('flash_error', 'Your MotionGrove number is not ready to send yet. Finish its setup first.');
            }

            AgencyProspectingChannel::where('workspace_id', $workspace->id)->update(['is_default_sender' => false]);

            return $back('flash_success', 'Messages will now be sent from your MotionGrove number.');
        }

        $channel = AgencyProspectingChannel::where('workspace_id', $workspace->id)->where('uid', $choice)->first();

        if ($channel === null || $channel->provider !== SendingServer::TYPE_TELNYX) {
            return $back('flash_error', 'Choose one of your own connected Telnyx numbers.');
        }

        $state = AgencyByoSender::sendReadiness($channel);

        if (! $state['ok']) {
            return $back('flash_error', (string) $state['message']);
        }

        if (! AgencyByoSender::hasWebhookKey($channel)) {
            return $back('flash_error', 'Add your Telnyx webhook public key first. Without it, replies to your messages could not be received.');
        }

        // Live re-check just before it becomes the sender: a key revoked since connecting is caught here.
        $credentials = AgencyByoSender::credentials($channel);
        $check = $this->telnyxVerifier->verify($credentials['api_key'], $credentials['profile_id'], (string) $channel->sender_number);

        if (! $check['ok']) {
            $channel->forceFill(['last_error_code' => $check['reason']])->save();

            return $back('flash_error', $check['message']);
        }

        DB::transaction(function () use ($workspace, $channel): void {
            AgencyProspectingChannel::where('workspace_id', $workspace->id)->lockForUpdate()->get();
            AgencyProspectingChannel::where('workspace_id', $workspace->id)->update(['is_default_sender' => false]);
            $channel->forceFill(['is_default_sender' => true, 'last_error_code' => null])->save();
        });

        return $back('flash_success', 'Messages will now be sent from ' . $channel->sender_number . ' through your own Telnyx account.');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function isAllowedProvider(string $provider): bool
    {
        return array_key_exists($provider, self::ALLOWED_PROVIDERS);
    }

    /**
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function validateCredentials(string $provider, array $submitted, bool $isUpdate): array
    {
        $errors = [];
        $values = [];

        foreach (self::ALLOWED_PROVIDERS[$provider]['credential_fields'] as $key => $meta) {
            $value = trim((string) ($submitted[$key] ?? ''));

            if ($value === '') {
                if ($meta['required'] && ! $isUpdate) {
                    $errors[$key] = $meta['label'] . ' is required.';
                }

                continue;
            }

            $values[$key] = $value;
        }

        return [$errors, $values];
    }

    private function resolveWorkspaceChannel(Workspace $workspace, AgencyProspectingChannel $channel): AgencyProspectingChannel
    {
        abort_unless((int) $channel->workspace_id === (int) $workspace->id, 404);

        return $channel;
    }

    /** A Telnyx webhook public key is base64 of a 32-byte Ed25519 key. */
    private function isValidWebhookKey(string $key): bool
    {
        $decoded = base64_decode($key, true);

        return $decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    }

    private function webhookUrl(AgencyProspectingChannel $channel): string
    {
        $token = AgencyProspectingWebhookToken::forChannel($channel->uid);

        // A Telnyx channel receives through the agency's own Conversations route.
        if ($channel->provider === SendingServer::TYPE_TELNYX) {
            return route('prospecting.webhooks.agency_telnyx', [$channel->uid, $token]);
        }

        $providerSegment = $channel->provider === SendingServer::TYPE_TELNYX ? 'telnyx' : 'twilio';

        return route('prospecting.webhooks.' . $providerSegment, [$channel->uid, $token]);
    }

    private function channelsError(string $workspaceUid, string $message): RedirectResponse
    {
        return redirect()->route('customer.workspaces.prospecting.channels.index', $workspaceUid)->with([
            'flash_error' => $message,
        ]);
    }
}
