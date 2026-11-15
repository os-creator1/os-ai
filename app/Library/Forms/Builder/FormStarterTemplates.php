<?php

namespace App\Library\Forms\Builder;

/**
 * The few starting points offered when creating a form. Plain element lists in the
 * same shape the editor and FormDefinitionNormalizer use — a starter is not a
 * second form model, just a convenient first version.
 */
final class FormStarterTemplates
{
    public const BLANK = 'blank';

    public const CONTACT = 'contact';

    public const AVAILABILITY = 'availability';

    /**
     * The New form dialog's cards: a short title, a one-line description and the
     * element labels the card's thumbnail is drawn from. Display copy only — what a
     * starter actually creates is content().
     *
     * @return array<string, array{title: string, description: string, lines: list<int>}>
     */
    public static function cards(): array
    {
        return [
            self::CONTACT => ['title' => 'Contact me', 'description' => 'Name, email, phone and a message', 'lines' => [60, 85, 85, 70]],
            self::AVAILABILITY => ['title' => 'Check availability', 'description' => 'Event date and type, plus contact details', 'lines' => [60, 85, 85, 70]],
            self::BLANK => ['title' => 'Blank', 'description' => 'Just name, email and phone', 'lines' => [60, 85]],
        ];
    }

    /** @return array<string, string> id => label */
    public static function choices(): array
    {
        return [
            self::CONTACT => 'Contact me — name, email, phone and a message',
            self::AVAILABILITY => 'Check availability — event date, event type, name, email, phone',
            self::BLANK => 'Blank — just name, email and phone',
        ];
    }

    public static function exists(string $id): bool
    {
        return array_key_exists($id, self::choices());
    }

    /**
     * @return array{fields: list<array<string, mixed>>, submit_label: string}
     */
    public static function content(string $id): array
    {
        $name = ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'contact_name' => true];
        $email = ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true];
        $phone = ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => true];

        return match ($id) {
            self::CONTACT => [
                'fields' => [$name, $email, $phone, ['key' => 'message', 'label' => 'How can we help?', 'type' => 'textarea', 'required' => false]],
                'submit_label' => 'Send',
            ],
            self::AVAILABILITY => [
                'fields' => [
                    ['key' => 'event_date', 'label' => 'Event date', 'type' => 'date', 'required' => true],
                    ['key' => 'event_type', 'label' => 'Event type', 'type' => 'select', 'required' => true, 'options' => ['Wedding', 'Birthday', 'Corporate', 'Other']],
                    $name, $email, $phone,
                ],
                'submit_label' => 'Check availability',
            ],
            default => ['fields' => [$name, $email, $phone], 'submit_label' => 'Send'],
        };
    }
}
