<?php

namespace App\Library\AgencyOutreach;

/**
 * Neutral, niche-free starting copy for the Agency's sales script (contract §3).
 *
 * Nothing here names a niche, a price, a commission, a platform, a city or a
 * company: those belong to an Agency's own saved settings. A default is used
 * ONLY while the Agency has saved nothing for that field, and is never written
 * to the database until the Agency saves its script — so improving this copy
 * later changes what an Agency that never edited sees, and nothing else.
 *
 * Copy is written in the canonical merge grammar (`{{agency.name}}` ...), the
 * same text the renderer and the editor handle for saved copy.
 */
final class OutreachScriptDefaults
{
    /** The script fields, in the order the editor presents them. */
    public const FIELDS = [
        'message_1',
        'message_2',
        'message_3',
        'pricing_answer',
        'location_answer',
        'found_you_answer',
        'what_we_do_answer',
        'website_answer',
        'clarify_answer',
        'followup_message',
    ];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'message_1' => "Hi, it's {{agency.name}}. We help local businesses get booked customers, and you only pay when we deliver. Would you be open to hearing how it works?",
            'message_2' => 'Great. The easiest way to explain it is a short call. Are you open to a quick call this week?',
            'message_3' => 'Perfect. You can book a quick call here: {{agency.calendar_link}}',
            'pricing_answer' => "You only pay when we deliver, and we'll walk you through the details on the call.",
            'location_answer' => 'We work with local businesses, and we can go over the details of your area on the call.',
            'found_you_answer' => 'We came across your business while looking at local businesses in your area.',
            'what_we_do_answer' => 'We help local businesses get booked customers, and you only pay when we deliver.',
            'website_answer' => "Happy to share more about {{agency.name}} on a quick call. {{agency.website}}",
            'clarify_answer' => "Happy to go through the specifics with you on a quick call.",
            'followup_message' => 'Just following up. You can book a quick call here: {{agency.calendar_link}}',
        ];
    }
}
