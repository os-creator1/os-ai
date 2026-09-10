<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Controllers\Customer\Workspace\Concerns\ResolvesAgencyProspectingWorkspace;
use App\Library\AgencyProspecting\AgencyProspectingWebhookToken;
use App\Library\AgencyProspecting\AgencyProspectPhoneNormalizer;
use App\Library\Entitlement\EntitlementManager;
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
            ],
        ],
    ];

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly EntitlementManager $entitlementManager,
        private readonly SendingServerRepository $sendingServers,
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

        [$errors, $credentials] = $this->validateCredentials($provider, $request->all(), false);

        if (! empty($errors)) {
            return redirect()->route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, $provider])
                ->withErrors($errors);
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
        DB::transaction(function () use ($input, $workspace, $provider, $senderNumber): void {
            $sendingServer = $this->sendingServers->store($input);

            AgencyProspectingChannel::create([
                'workspace_id' => $workspace->id,
                'sending_server_id' => $sendingServer->id,
                'provider' => $provider,
                'sender_number' => $senderNumber,
                'status' => AgencyProspectingChannel::STATUS_ACTIVE,
                'created_by_user_id' => Auth::id(),
            ]);
        });

        return redirect()->route('customer.workspaces.prospecting.channels.index', $workspaceUid)->with([
            'flash_success' => self::ALLOWED_PROVIDERS[$provider]['label'] . ' channel connected.',
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

        [$errors, $credentials] = $this->validateCredentials($provider, $request->all(), true);

        if (! empty($errors)) {
            return redirect()->route('customer.workspaces.prospecting.channels.show', [$workspaceUid, $channel->uid])
                ->withErrors($errors);
        }

        if (! empty($credentials) && $channel->sendingServer !== null) {
            $this->sendingServers->update($channel->sendingServer, $credentials);
        }

        return redirect()->route('customer.workspaces.prospecting.channels.show', [$workspaceUid, $channel->uid])->with([
            'flash_success' => 'Channel updated.',
        ]);
    }

    public function enable(string $workspaceUid, AgencyProspectingChannel $channel): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceChannel($workspace, $channel);

        if (! $channel->sendingServer || ! $channel->sendingServer->status) {
            return $this->channelsError($workspaceUid, 'This provider is not currently available.');
        }

        $channel->update(['status' => AgencyProspectingChannel::STATUS_ACTIVE]);

        return redirect()->route('customer.workspaces.prospecting.channels.index', $workspaceUid)->with([
            'flash_success' => 'Channel enabled.',
        ]);
    }

    public function disable(string $workspaceUid, AgencyProspectingChannel $channel): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceChannel($workspace, $channel);

        $channel->update(['status' => AgencyProspectingChannel::STATUS_DISABLED]);

        return redirect()->route('customer.workspaces.prospecting.channels.index', $workspaceUid)->with([
            'flash_success' => 'Channel disabled.',
        ]);
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

    private function webhookUrl(AgencyProspectingChannel $channel): string
    {
        $token = AgencyProspectingWebhookToken::forChannel($channel->uid);
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
