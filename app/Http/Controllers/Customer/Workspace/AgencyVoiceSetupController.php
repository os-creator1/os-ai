<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Controllers\Customer\Workspace\Concerns\ResolvesAgencyProspectingWorkspace;
use App\Library\AgencyOutreach\AgencyByoSender;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Messaging\E164Normalizer;
use App\Library\Voice\Byo\AgencyByoVoice;
use App\Library\Voice\Byo\AgencyVoiceVerifier;
use App\Models\AgencyProspectingChannel;
use App\Models\BusinessVoiceBrowserCredential;
use App\Models\SendingServer;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The minimal Voice setup under an agency's already-connected Telnyx account. Four explicit actions, all behind the same
 * Workspace role + entitlement as the channel itself (a foreign Workspace's channel is a 404):
 *   save    — store the two Telnyx connection ids and the voice number (nothing is verified or enabled by saving);
 *   verify  — read-only Telnyx checks, the only thing that can mark a step verified;
 *   enable  — turn voice on for THIS channel (only when every step is verified; turns it off on any other channel);
 *   disable — turn it off.
 * Nothing here assigns a number, edits a connection or places a call at Telnyx: the agency does that in its own portal.
 */
class AgencyVoiceSetupController extends CustomerBaseController
{
    use ResolvesAgencyProspectingWorkspace;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly EntitlementManager $entitlementManager,
        private readonly AgencyVoiceVerifier $verifier,
    ) {
    }

    public function save(Request $request, string $workspaceUid, AgencyProspectingChannel $channel): RedirectResponse
    {
        $channel = $this->channelFor($workspaceUid, $channel);

        $data = $request->validate([
            'voice_connection_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'voice_webrtc_connection_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/', 'different:voice_connection_id'],
            'voice_number' => ['required', 'string', 'max:32'],
        ]);

        $number = E164Normalizer::normalize($data['voice_number']);
        if ($number === null) {
            return $this->back($workspaceUid, $channel)->withErrors(['voice_number' => 'Enter the number in international format, for example +14155552671.']);
        }
        $digits = preg_replace('/\D/', '', $number);

        $taken = AgencyProspectingChannel::query()->where('voice_number_key', $digits)->where('id', '!=', $channel->id)->exists();
        if ($taken) {
            return $this->back($workspaceUid, $channel)->withErrors(['voice_number' => 'This number is already connected to another account.']);
        }

        $connectionChanged = $channel->voice_connection_id !== $data['voice_connection_id'];
        $webrtcChanged = $channel->voice_webrtc_connection_id !== $data['voice_webrtc_connection_id'];
        $numberChanged = preg_replace('/\D/', '', (string) $channel->voice_number) !== $digits;

        $attributes = [
            'voice_connection_id' => $data['voice_connection_id'],
            'voice_webrtc_connection_id' => $data['voice_webrtc_connection_id'],
            'voice_number' => $number,
        ];
        if ($connectionChanged || $webrtcChanged || $numberChanged) {
            // Anything changed must be proven again, and voice stays off until the agency turns it on again.
            $attributes += ['voice_enabled' => false, 'voice_connection_verified_at' => null, 'voice_webrtc_verified_at' => null, 'voice_number_verified_at' => null];
        }

        try {
            DB::transaction(function () use ($channel, $attributes, $webrtcChanged): void {
                $channel->forceFill($attributes)->save();
                if ($webrtcChanged) {
                    // Credentials minted on the old connection must never be reused. Scoped to THIS channel only
                    // (never channel 0 = platform, never another agency's).
                    BusinessVoiceBrowserCredential::query()->where('agency_prospecting_channel_id', (int) $channel->id)->delete();
                }
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return $this->back($workspaceUid, $channel)->withErrors(['voice_number' => 'This number is already connected to another account.']);
        }

        return $this->back($workspaceUid, $channel)->with('flash_success', 'Voice details saved. Now verify them with Telnyx.');
    }

    public function verify(string $workspaceUid, AgencyProspectingChannel $channel): RedirectResponse
    {
        $channel = $this->channelFor($workspaceUid, $channel);
        $credentials = AgencyByoSender::credentials($channel);

        if ($credentials === null) {
            return $this->back($workspaceUid, $channel)->with('flash_error', 'The saved Telnyx credentials can no longer be read. Reconnect your account.');
        }
        if (trim((string) $channel->voice_connection_id) === '' || trim((string) $channel->voice_webrtc_connection_id) === '' || trim((string) $channel->voice_number) === '') {
            return $this->back($workspaceUid, $channel)->with('flash_error', 'Save the Voice connection, WebRTC connection and number first.');
        }

        // Bind this verification to the exact account + settings being checked. The Telnyx calls below run OUTSIDE any
        // transaction (no lock is held across HTTP); the snapshot is re-read under a row lock before anything is written.
        $snapshot = AgencyByoVoice::fingerprint($channel);

        $result = $this->verifier->verify(
            $credentials['api_key'],
            (string) $channel->voice_connection_id,
            (string) $channel->voice_webrtc_connection_id,
            preg_replace('/\D/', '', (string) $channel->voice_number),
        );

        if ($result['fatal'] !== null) {
            // The account could not be queried: nothing is proven either way, so nothing already verified is touched.
            return $this->back($workspaceUid, $channel)->with('flash_error', $result['fatal']);
        }

        $problems = array_filter([$result['voice_connection'], $result['webrtc_connection'], $result['number']]);

        $applied = DB::transaction(function () use ($channel, $snapshot, $result, $problems): bool {
            $locked = AgencyProspectingChannel::query()->with('sendingServer')->whereKey($channel->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status === AgencyProspectingChannel::STATUS_DISCONNECTED
                || ! hash_equals($snapshot, AgencyByoVoice::fingerprint($locked))) {
                return false; // settings or key changed while Telnyx was being asked: fail closed, approve nothing
            }

            $now = now();
            $locked->forceFill([
                'voice_connection_verified_at' => $result['voice_connection'] === null ? $now : null,
                'voice_webrtc_verified_at' => $result['webrtc_connection'] === null ? $now : null,
                'voice_number_verified_at' => $result['number'] === null ? $now : null,
                'voice_last_error_code' => null,
                // A step that no longer verifies takes voice offline rather than leaving a stale "ready".
                'voice_enabled' => $problems === [] ? $locked->voice_enabled : false,
            ])->save();

            return true;
        });

        if (! $applied) {
            return $this->back($workspaceUid, $channel)->with('flash_error', 'Your voice settings or Telnyx key changed while they were being checked, so the result was discarded. Verify again.');
        }

        return $problems === []
            ? $this->back($workspaceUid, $channel)->with('flash_success', 'Telnyx confirmed your voice setup. You can now turn voice on.')
            : $this->back($workspaceUid, $channel)->with('flash_error', implode(' ', $problems));
    }

    public function enable(string $workspaceUid, AgencyProspectingChannel $channel): RedirectResponse
    {
        $channel = $this->channelFor($workspaceUid, $channel);

        $send = AgencyByoSender::sendReadiness($channel);
        if (! $send['ok']) {
            return $this->back($workspaceUid, $channel)->with('flash_error', (string) $send['message']);
        }
        $missing = AgencyByoVoice::missingSteps($channel);
        if ($missing !== []) {
            return $this->back($workspaceUid, $channel)->with('flash_error', 'Voice cannot be turned on yet. Missing or unverified: '
                . implode(', ', array_map(fn (string $k): string => AgencyByoVoice::STEPS[$k], $missing)) . '.');
        }

        DB::transaction(function () use ($channel): void {
            // One voice account per Agency, chosen explicitly: any other channel's voice is switched off here, visibly.
            AgencyProspectingChannel::query()->where('workspace_id', $channel->workspace_id)->where('id', '!=', $channel->id)->where('voice_enabled', true)->update(['voice_enabled' => false]);
            $channel->forceFill(['voice_enabled' => true])->save();
        });

        return $this->back($workspaceUid, $channel)->with('flash_success', 'Voice is on. Calls from your CRM now go through your own Telnyx account and are billed by Telnyx to you.');
    }

    public function disable(string $workspaceUid, AgencyProspectingChannel $channel): RedirectResponse
    {
        $channel = $this->channelFor($workspaceUid, $channel);
        $channel->forceFill(['voice_enabled' => false])->save();

        return $this->back($workspaceUid, $channel)->with('flash_success', 'Voice is off for this Telnyx account.');
    }

    private function channelFor(string $workspaceUid, AgencyProspectingChannel $channel): AgencyProspectingChannel
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        abort_unless((int) $channel->workspace_id === (int) $workspace->id
            && $channel->provider === SendingServer::TYPE_TELNYX
            && $channel->status !== AgencyProspectingChannel::STATUS_DISCONNECTED, 404);

        return $channel->loadMissing('sendingServer');
    }

    private function back(string $workspaceUid, AgencyProspectingChannel $channel): RedirectResponse
    {
        return redirect()->route('customer.workspaces.prospecting.channels.show', [$workspaceUid, $channel->uid]);
    }
}
