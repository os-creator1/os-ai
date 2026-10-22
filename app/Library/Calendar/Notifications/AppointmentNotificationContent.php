<?php

namespace App\Library\Calendar\Notifications;

use App\Models\BookingType;

/**
 * The editable wording of a booking confirmation / reminder (Calendar
 * Notifications V2): templates with a closed set of variables, rendered with the
 * appointment's real details at SEND time (so an edit applies to everything not
 * yet sent), and the same renderer drives the editor's preview.
 *
 * Variables: {{customer_name}} {{business_name}} {{booking_type}} {{date}}
 * {{time}} {{time_range}} {{timezone}} {{location}} {{instructions}}.
 * An unknown variable renders blank; nothing is evaluated. A text message always
 * ends with the opt-out line, whatever the template says.
 */
final class AppointmentNotificationContent
{
    public const VARIABLES = [
        'customer_name' => 'Customer name',
        'business_name' => 'Business name',
        'booking_type' => 'Booking type',
        'date' => 'Appointment date',
        'time' => 'Start time',
        'time_range' => 'Start and end time',
        'timezone' => 'Time zone',
        'location' => 'Location',
        'instructions' => 'Your meeting instructions',
        'join_url' => 'Zoom join link (blank unless the appointment has a Zoom meeting)',
    ];

    private const OPT_OUT = 'Reply STOP to opt out.';

    /** The defaults a customer starts from; they match the built-in wording. @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'confirmation_email_subject' => 'Booking confirmed: {{booking_type}} with {{business_name}} on {{date}}',
            'confirmation_email_body' => "Hi {{customer_name}},\nYour appointment with {{business_name}} is confirmed.\n\nWhat: {{booking_type}}\nWhen: {{date}}, {{time_range}} ({{timezone}})\nWhere: {{location}}\n{{instructions}}",
            'confirmation_sms_body' => '{{business_name}}: your {{booking_type}} is booked for {{date}} at {{time}}. Where: {{location}}.',
            'reminder_email_subject' => 'Reminder: {{booking_type}} with {{business_name}} on {{date}}',
            'reminder_email_body' => "Hi {{customer_name}},\nThis is a reminder of your appointment with {{business_name}}.\n\nWhat: {{booking_type}}\nWhen: {{date}}, {{time_range}} ({{timezone}})\nWhere: {{location}}\n{{instructions}}",
            'reminder_sms_body' => '{{business_name}}: reminder, your {{booking_type}} is on {{date}} at {{time}}. Where: {{location}}.',
        ];
    }

    public static function render(string $template, AppointmentNotificationDetails $d, string $customerName = ''): string
    {
        $values = [
            'customer_name' => trim($customerName) !== '' ? trim($customerName) : 'there',
            'business_name' => $d->businessName,
            'booking_type' => $d->bookingType,
            'date' => $d->date,
            'time' => $d->startTime . ' ' . $d->zoneAbbr,
            'time_range' => $d->timeRange,
            'timezone' => $d->timezone,
            'location' => (string) $d->where,
            'instructions' => (string) $d->instructions,
            'join_url' => (string) $d->joinUrl,
        ];

        $out = preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            static fn (array $m): string => $values[strtolower($m[1])] ?? '',
            $template,
        ) ?? $template;

        // A "Label:" line whose variable rendered blank (no location / instructions) disappears.
        $lines = array_filter(
            preg_split('/\R/', $out) ?: [],
            static fn (string $line): bool => ! preg_match('/^\s*[A-Za-z ]{2,20}:\s*\.?\s*$/', $line),
        );

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? $out);
    }

    /** An SMS body: rendered, bounded, and always carrying the opt-out line. */
    public static function renderSms(string $template, AppointmentNotificationDetails $d, string $customerName = ''): string
    {
        $text = trim(preg_replace('/\s+/', ' ', self::render($template, $d, $customerName)) ?? '');
        $text = trim(preg_replace('/\s*Where:\s*\.(?=\s|$)/', '', $text) ?? $text);
        $text = mb_strimwidth($text, 0, 420, '...');

        return stripos($text, 'STOP') === false ? trim($text . ' ' . self::OPT_OUT) : $text;
    }

    /**
     * The template for one kind/channel from the booking type: the reminder step
     * that owns this offset, else the type's confirmation text; null = built-in.
     *
     * @return array{subject: ?string, body: ?string}
     */
    public static function templateFor(BookingType $type, string $kind, string $channel, ?int $offsetMinutes): array
    {
        if ($kind === 'confirmation') {
            return $channel === 'email'
                ? ['subject' => self::blankToNull($type->confirmation_email_subject), 'body' => self::blankToNull($type->confirmation_email_body)]
                : ['subject' => null, 'body' => self::blankToNull($type->confirmation_sms_body)];
        }

        foreach ($type->reminderSteps() as $step) {
            if ($step['enabled'] && (int) $step['offset_minutes'] === (int) $offsetMinutes) {
                return $channel === 'email'
                    ? ['subject' => self::blankToNull($step['email_subject'] ?? null), 'body' => self::blankToNull($step['email_body'] ?? null)]
                    : ['subject' => null, 'body' => self::blankToNull($step['sms_body'] ?? null)];
            }
        }

        return ['subject' => null, 'body' => null];
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
