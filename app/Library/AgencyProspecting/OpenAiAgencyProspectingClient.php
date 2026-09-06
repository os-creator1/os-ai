<?php

namespace App\Library\AgencyProspecting;

use App\Library\AgencyProspecting\Contracts\AgencyProspectingAiClient;
use OpenAI;
use Throwable;

/**
 * Runtime pass — reuses the exact same call chain already proven in
 * CampaignController::generateAIMessage() (OpenAI::client(config(
 * 'services.openai.api_key'))->chat()->create([...])), the only existing
 * AI-provider integration in this repository. No new configuration keys,
 * no new package — config('services.openai.*') is the one already shipped.
 *
 * Fails closed, never throws: a missing/inactive API key, or any provider
 * exception, returns null rather than fabricating a reply.
 */
final class OpenAiAgencyProspectingClient implements AgencyProspectingAiClient
{
    public function complete(array $messages): ?string
    {
        if (! config('services.openai.active') || empty(config('services.openai.api_key'))) {
            return null;
        }

        try {
            $client = OpenAI::client(config('services.openai.api_key'));

            $result = $client->chat()->create([
                'model' => config('services.openai.model'),
                'messages' => $messages,
            ]);

            $content = trim($result->choices[0]->message->content ?? '');

            return $content !== '' ? $content : null;
        } catch (Throwable) {
            return null;
        }
    }
}
