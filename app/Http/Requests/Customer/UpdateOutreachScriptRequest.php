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
        ];
    }
}
