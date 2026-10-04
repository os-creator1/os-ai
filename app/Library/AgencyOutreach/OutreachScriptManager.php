<?php

namespace App\Library\AgencyOutreach;

use App\Library\AgencyProspecting\AgencyProspectUrlPolicy;
use App\Models\AgencyProspectingSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one writer of an Agency's Outreach script and settings (contract §3).
 *
 * Saving canonicalises the owner's vocabulary into merge tokens, validates the two
 * URLs as http(s) (the only kind ever merged into a message to a stranger), bumps
 * `script_version` only when script/answer TEXT actually changed (so a save that
 * only flips a switch does not re-version the copy), and pins
 * `scheduling_mode` to `calendar_link` — conversational scheduling is not
 * selectable in V1 (contract §10).
 *
 * Fields it does not know are ignored, never written: this is a whitelist, so a
 * crafted request cannot reach `workspace_id`, `uid` or any other column.
 */
final class OutreachScriptManager
{
    /** Free-text settings that are saved as given (trimmed; blank clears). */
    private const PLAIN_TEXT = ['agency_name', 'offer', 'niche', 'qualification_context'];

    private const URLS = ['booking_url', 'website_url'];

    private const BOOLEANS = ['followup_enabled', 'ai_enabled'];

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws ValidationException a URL that is not a valid http(s) URL, or a bad delay
     */
    public static function save(Workspace $workspace, array $fields, ?User $actor = null): AgencyProspectingSetting
    {
        $changes = [];
        $errors = [];

        foreach (OutreachScriptDefaults::FIELDS as $field) {
            if (array_key_exists($field, $fields)) {
                $changes[$field] = self::canonicalText($fields[$field]);
            }
        }

        foreach (self::PLAIN_TEXT as $field) {
            if (array_key_exists($field, $fields)) {
                $changes[$field] = self::text($fields[$field]);
            }
        }

        foreach (self::URLS as $field) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }

            $url = self::text($fields[$field]);

            if ($url !== null && ! AgencyProspectUrlPolicy::isValidHttpUrl($url)) {
                $errors[$field] = 'Enter a full web address starting with http:// or https://.';

                continue;
            }

            $changes[$field] = $url;
        }

        foreach (self::BOOLEANS as $field) {
            if (array_key_exists($field, $fields)) {
                $changes[$field] = filter_var($fields[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (array_key_exists('follow_up_delay_hours', $fields)) {
            $hours = filter_var($fields['follow_up_delay_hours'], FILTER_VALIDATE_INT);

            if ($hours === false || $hours < 1 || $hours > 168) {
                $errors['follow_up_delay_hours'] = 'Choose a follow-up delay between 1 and 168 hours.';
            } else {
                $changes['follow_up_delay_hours'] = $hours;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // V1 is calendar-link only; whatever was asked for, this is what is stored.
        $changes['scheduling_mode'] = 'calendar_link';

        return DB::transaction(function () use ($workspace, $changes): AgencyProspectingSetting {
            $settings = AgencyProspectingSetting::query()
                ->where('workspace_id', (int) $workspace->id)
                ->lockForUpdate()
                ->first();

            if ($settings === null) {
                $settings = new AgencyProspectingSetting(['workspace_id' => (int) $workspace->id]);
            }

            $textChanged = false;

            foreach (OutreachScriptDefaults::FIELDS as $field) {
                if (array_key_exists($field, $changes) && ($settings->getAttribute($field) ?? null) !== $changes[$field]) {
                    $textChanged = true;
                }
            }

            $settings->fill($changes);

            if ($textChanged) {
                // A new row starts at the column default (1 = "the neutral defaults"), so a
                // first customised save is already version 2 by the same rule as any later edit.
                $settings->script_version = max(1, (int) ($settings->script_version ?? 1)) + 1;
            }

            $settings->save();

            return $settings->fresh();
        });
    }

    /** Trimmed text; blank means "clear the field" (null), so the neutral default applies again. */
    private static function text(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function canonicalText(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === null ? null : OutreachScriptTokens::canonicalise($text);
    }
}
