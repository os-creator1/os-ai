<?php

namespace App\Library\AgencyProspecting;

use App\Library\AgencyProspecting\Contracts\AgencyProspectingMessageSender;
use App\Models\AgencyProspectingChannel;
use App\Models\SendingServer;
use Illuminate\Support\Facades\Http;
use Throwable;
use Twilio\Exceptions\ConfigurationException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client as TwilioClient;

/**
 * Runtime pass — reuses the exact provider primitives the send audit
 * confirmed on current main: Twilio via the already-installed `twilio/sdk`
 * package's `Twilio\Rest\Client` (SendCampaignSMS::sendPlainSMS()'s own
 * TYPE_TWILIO case), Telnyx via a plain JSON POST to
 * `https://api.telnyx.com/v2/messages` with a Bearer api_key (the same
 * endpoint/auth/payload shape as SendCampaignSMS::sendPlainSMS()'s own
 * TYPE_TELNYX case, ported to Laravel's Http:: facade instead of raw curl
 * since this is fresh code, not a change to that legacy method). A bounded
 * switch on exactly two providers — never a generic gateway abstraction —
 * matching Agency Prospecting's own hard SMS-only, Twilio/Telnyx-only
 * scope. Never touches EloquentCampaignRepository::campaignBuilder() or
 * the legacy Campaigns/Reports tables.
 */
final class ProviderAgencyProspectingMessageSender implements AgencyProspectingMessageSender
{
    public function send(AgencyProspectingChannel $channel, string $from, string $to, string $body): AgencyProspectingSendResult
    {
        $server = $channel->sendingServer;

        if ($server === null) {
            return AgencyProspectingSendResult::failure('missing_sending_server');
        }

        return match ($channel->provider) {
            SendingServer::TYPE_TWILIO => $this->sendViaTwilio($server, $from, $to, $body),
            SendingServer::TYPE_TELNYX => $this->sendViaTelnyx($server, $from, $to, $body),
            default => AgencyProspectingSendResult::failure('unsupported_provider'),
        };
    }

    private function sendViaTwilio(SendingServer $server, string $from, string $to, string $body): AgencyProspectingSendResult
    {
        try {
            $client = new TwilioClient($server->account_sid, $server->auth_token);

            $message = $client->messages->create('+' . $to, [
                'from' => '+' . $from,
                'body' => $body,
            ]);

            if (in_array($message->status, ['queued', 'accepted', 'sending', 'sent'], true)) {
                return AgencyProspectingSendResult::success($message->sid);
            }

            return AgencyProspectingSendResult::failure($message->status !== '' ? $message->status : 'twilio_rejected');
        } catch (ConfigurationException|TwilioException $exception) {
            return AgencyProspectingSendResult::failure($exception->getMessage());
        } catch (Throwable $exception) {
            return AgencyProspectingSendResult::failure($exception->getMessage());
        }
    }

    private function sendViaTelnyx(SendingServer $server, string $from, string $to, string $body): AgencyProspectingSendResult
    {
        try {
            $payload = [
                'to' => '+' . $to,
                'from' => '+' . $from,
                'text' => $body,
            ];

            if (! empty($server->c1)) {
                $payload['messaging_profile_id'] = $server->c1;
            }

            $response = Http::withToken($server->api_key)
                ->timeout(30)
                ->post('https://api.telnyx.com/v2/messages', $payload);

            $data = $response->json();

            if (is_array($data) && ($data['data']['to'][0]['status'] ?? null) === 'queued' && ! empty($data['data']['id'])) {
                return AgencyProspectingSendResult::success($data['data']['id']);
            }

            if (is_array($data) && ! empty($data['errors'][0]['detail'])) {
                return AgencyProspectingSendResult::failure($data['errors'][0]['detail']);
            }

            return AgencyProspectingSendResult::failure('telnyx_unexpected_response');
        } catch (Throwable $exception) {
            return AgencyProspectingSendResult::failure($exception->getMessage());
        }
    }
}
