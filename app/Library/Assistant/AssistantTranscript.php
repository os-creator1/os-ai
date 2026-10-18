<?php

namespace App\Library\Assistant;

use App\Models\AssistantMessage;
use Illuminate\Support\Str;

/**
 * The ONE place a stored conversation message becomes text for the external provider.
 *
 * A reply the server rendered itself from records (a list of waiting customers: names, phone numbers, message snippets) was for
 * the customer's eyes: the provider only learns that one was shown. Normal history (AssistantPromptBuilder) and compaction
 * (AssistantCompactor) both read stored messages, so both go through here and cannot drift apart: whatever is withheld from the
 * model's input is also withheld from the transcript that gets summarised, and so can never persist in a summary.
 *
 * The customer's own conversation (AssistantPresenter) is untouched: it still shows the full original reply.
 */
final class AssistantTranscript
{
    public const SERVER_RENDERED = '[The server showed the customer a list of records. Its details are not repeated here.]';

    public static function forProvider(AssistantMessage $row, int $limit): string
    {
        if (($row->payload['deterministic'] ?? false) === true) {
            return self::SERVER_RENDERED;
        }

        return Str::limit(trim((string) $row->body), $limit);
    }
}
