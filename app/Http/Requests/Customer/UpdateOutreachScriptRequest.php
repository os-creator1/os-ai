<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for Outreach > Script & Settings. Who may save is decided by
 * the controller (Workspace owner/Admin + entitlement, 404 otherwise); what is
 * stored, how tokens are canonicalised and how URLs are vetted is decided by
 * OutreachScriptManager::save().
 */
class UpdateOutreachScriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $message = ['nullable', 'string', 'max:1600'];
        $answer = ['nullable', 'string', 'max:1000'];

        return [
            'message_1' => $message,
            'message_2' => $message,
            'message_3' => $message,
            'pricing_answer' => $answer,
            'location_answer' => $answer,
            'found_you_answer' => $answer,
            'what_we_do_answer' => $answer,
            'website_answer' => $answer,
            'clarify_answer' => $answer,
            'followup_message' => $message,
            'followup_enabled' => ['nullable', 'boolean'],
            'follow_up_delay_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'ai_enabled' => ['nullable', 'boolean'],
            'agency_name' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'string', 'max:2048'],
            'booking_url' => ['nullable', 'string', 'max:2048'],

            // Outreach V2: ordered, variable-length lists (normalised and bounded by OutreachScriptManager).
            'sequence_steps_present' => ['nullable', 'boolean'],
            'sequence_steps' => ['nullable', 'array', 'max:50'],
            'sequence_steps.*.id' => ['nullable', 'string', 'max:64'],
            'sequence_steps.*.body' => ['nullable', 'string', 'max:1600'],
            'sequence_steps.*.advance_on' => ['nullable', 'in:reply,time'],
            'sequence_steps.*.delay_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'sequence_steps.*.enabled' => ['nullable', 'boolean'],
            'sequence_paused' => ['nullable', 'boolean'],
            'faq_entries_present' => ['nullable', 'boolean'],
            'faq_entries' => ['nullable', 'array', 'max:100'],
            'faq_entries.*.id' => ['nullable', 'string', 'max:64'],
            'faq_entries.*.question' => ['nullable', 'string', 'max:300'],
            'faq_entries.*.answer' => ['nullable', 'string', 'max:1000'],
            'faq_entries.*.keywords' => ['nullable', 'string', 'max:600'],
            'faq_entries.*.intent' => ['nullable', 'string', 'max:32'],
            'faq_entries.*.niche' => ['nullable', 'string', 'max:120'],
            'faq_entries.*.campaign_ids' => ['nullable', 'array'],
            'faq_entries.*.campaign_ids.*' => ['integer'],
            'faq_fallback' => ['nullable', 'in:ai,continue,handoff'],
        ];
    }
}
