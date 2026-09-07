<?php

namespace App\Library\Website;

use OpenAI;
use Throwable;

/**
 * Website Generation + Hosting Slice A contract §14. Reuses the exact
 * canonical seam config('services.openai.*') and follows the exact
 * fail-closed shape of
 * App\Library\AgencyProspecting\OpenAiAgencyProspectingClient — no new
 * config keys, no new package, no ai_settings table. Fails closed,
 * never throws: a missing/inactive API key, or any provider exception,
 * returns null rather than fabricating a reply. Deliberately not
 * `final` (unlike its sibling Library\Website services) so tests can
 * bind a Mockery mock in place of a real provider call.
 */
class WebsiteAiGenerationClient
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
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = trim($result->choices[0]->message->content ?? '');

            return $content !== '' ? $content : null;
        } catch (Throwable) {
            return null;
        }
    }
}
