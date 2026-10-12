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

    private const BOOLEANS = ['followup_enabled', 'ai_enabled', 'sequence_paused'];

    /** System safeguards, not product limits: a runaway form cannot create an unbounded script. */
    public const MAX_STEPS = 50;

    public const MAX_FAQ = 100;

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

        if (array_key_exists('sequence_steps', $fields)) {
            [$changes['sequence_steps'], $stepErrors] = self::steps($fields['sequence_steps']);
            $errors += $stepErrors;
        }

        if (array_key_exists('faq_entries', $fields)) {
            [$changes['faq_entries'], $faqErrors] = self::faq($fields['faq_entries'], $workspace);
            $errors += $faqErrors;
        }

        if (array_key_exists('faq_fallback', $fields)) {
            if (in_array($fields['faq_fallback'], [OutreachFaq::FALLBACK_AI, OutreachFaq::FALLBACK_CONTINUE, OutreachFaq::FALLBACK_HANDOFF], true)) {
                $changes['faq_fallback'] = $fields['faq_fallback'];
            } else {
                $errors['faq_fallback'] = 'Choose what to do when a question is not covered.';
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

            foreach (['sequence_steps', 'faq_entries'] as $listField) {
                if (array_key_exists($listField, $changes)
                    && json_encode(self::canonical($settings->getAttribute($listField))) !== json_encode(self::canonical($changes[$listField]))) {
                    $textChanged = true;
                }
            }

            $wasPaused = (bool) $settings->sequence_paused;

            $settings->fill($changes);

            if ($textChanged) {
                // A new row starts at the column default (1 = "the neutral defaults"), so a
                // first customised save is already version 2 by the same rule as any later edit.
                $settings->script_version = max(1, (int) ($settings->script_version ?? 1)) + 1;
            }

            $settings->save();

            // Resuming a paused sequence lets the follow-ups that were held run.
            if ($wasPaused && array_key_exists('sequence_paused', $changes) && ! $changes['sequence_paused']) {
                DB::afterCommit(fn () => self::releaseHeldFollowUps($workspace));
            }

            return $settings->fresh();
        });
    }

    /** MySQL JSON does not keep key order, so lists are compared with their keys sorted. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($v) => self::canonical($v), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private static function releaseHeldFollowUps(Workspace $workspace): void
    {
        \App\Models\AgencyProspectCampaignMember::query()
            ->where('workspace_id', (int) $workspace->id)
            ->whereNotNull('followup_at')
            ->whereNull('followup_sent_at')
            ->whereNull('followup_cancelled_at')
            ->pluck('id')
            ->each(fn ($id) => \App\Jobs\Outreach\OutreachFollowUpJob::dispatch((int) $id));
    }

    /**
     * @param  mixed  $raw
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>}
     */
    private static function steps(mixed $raw): array
    {
        $errors = [];
        $steps = [];
        $seen = [];

        foreach (array_values(is_array($raw) ? $raw : []) as $i => $step) {
            if (! is_array($step)) {
                continue;
            }

            if (count($steps) >= self::MAX_STEPS) {
                $errors['sequence_steps'] = 'A sequence can have up to ' . self::MAX_STEPS . ' messages.';
                break;
            }

            $id = trim((string) ($step['id'] ?? ''));
            $id = ($id === '' || isset($seen[$id])) ? 's' . strtolower(\Illuminate\Support\Str::random(10)) : mb_substr($id, 0, 64);
            $seen[$id] = true;

            $body = OutreachScriptTokens::canonicalise(trim((string) ($step['body'] ?? '')));

            if ($body === '') {
                $errors["sequence_steps.{$i}.body"] = 'Write the message, or delete this step.';
            } elseif (mb_strlen($body) > 1600) {
                $errors["sequence_steps.{$i}.body"] = 'Keep each message under 1600 characters.';
            }

            $time = ($step['advance_on'] ?? OutreachSequence::REPLY) === OutreachSequence::TIME;
            $delay = null;

            if ($time) {
                $delay = filter_var($step['delay_hours'] ?? null, FILTER_VALIDATE_INT);

                if ($delay === false || $delay < 1 || $delay > 720) {
                    $errors["sequence_steps.{$i}.delay_hours"] = 'Choose a wait between 1 and 720 hours.';
                    $delay = null;
                }
            }

            $steps[] = [
                'id' => $id,
                'body' => $body,
                'advance_on' => $time ? OutreachSequence::TIME : OutreachSequence::REPLY,
                'delay_hours' => $delay,
                'enabled' => ! array_key_exists('enabled', $step) || filter_var($step['enabled'], FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return [$steps, $errors];
    }

    /**
     * @param  mixed  $raw
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>}
     */
    private static function faq(mixed $raw, Workspace $workspace): array
    {
        $errors = [];
        $entries = [];
        $seen = [];
        $ownCampaigns = \App\Models\AgencyProspectCampaign::query()->where('workspace_id', (int) $workspace->id)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $intents = [
            OutreachIntentClassifier::PRICING, OutreachIntentClassifier::LOCATION, OutreachIntentClassifier::FOUND_YOU,
            OutreachIntentClassifier::WEBSITE, OutreachIntentClassifier::WHAT_WE_DO, OutreachIntentClassifier::CLARIFY,
        ];

        foreach (array_values(is_array($raw) ? $raw : []) as $i => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (count($entries) >= self::MAX_FAQ) {
                $errors['faq_entries'] = 'You can keep up to ' . self::MAX_FAQ . ' approved answers.';
                break;
            }

            $id = trim((string) ($entry['id'] ?? ''));
            $id = ($id === '' || isset($seen[$id])) ? 'q' . strtolower(\Illuminate\Support\Str::random(10)) : mb_substr($id, 0, 64);
            $seen[$id] = true;

            $question = trim((string) ($entry['question'] ?? ''));
            $answer = OutreachScriptTokens::canonicalise(trim((string) ($entry['answer'] ?? '')));

            if ($answer === '') {
                $errors["faq_entries.{$i}.answer"] = 'Write the answer, or delete this entry.';
            } elseif (mb_strlen($answer) > 1000 || mb_strlen($question) > 300) {
                $errors["faq_entries.{$i}.answer"] = 'Keep questions under 300 and answers under 1000 characters.';
            }

            $keywords = $entry['keywords'] ?? [];
            $keywords = is_string($keywords) ? (preg_split('/[,\n]+/', $keywords) ?: []) : (array) $keywords;
            $campaigns = array_values(array_intersect(array_map('intval', (array) ($entry['campaign_ids'] ?? [])), $ownCampaigns));

            $entries[] = [
                'id' => $id,
                'question' => $question,
                'answer' => $answer,
                'keywords' => array_slice(array_values(array_filter(array_map(
                    static fn ($k): string => mb_strtolower(trim((string) $k)),
                    $keywords,
                ), static fn (string $k): bool => $k !== '' && mb_strlen($k) <= 60)), 0, 20),
                'intent' => in_array((string) ($entry['intent'] ?? ''), $intents, true) ? (string) $entry['intent'] : null,
                'campaign_ids' => $campaigns === [] ? null : $campaigns,
                'niche' => trim((string) ($entry['niche'] ?? '')) === '' ? null : mb_substr(trim((string) $entry['niche']), 0, 120),
            ];
        }

        return [$entries, $errors];
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
