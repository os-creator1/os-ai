<?php

namespace App\Library\Calendar\DemoBooking\Form;

/** The Book a Demo form exactly as the approved Claude Design ships it. Used until the Platform Owner publishes a change. */
final class DemoFormDefaults
{
    /** Fields the booking, Contact and notifications depend on: never removable, type never changes. */
    public const SYSTEM = ['first_name', 'last_name', 'email', 'phone', 'business_name'];

    /** System fields that must always be shown and required. */
    public const LOCKED = ['first_name', 'email', 'business_name'];

    /** @return array<int, array<string, mixed>> */
    public static function fields(): array
    {
        $opts = static fn (array $labels): array => array_map(
            static fn (string $label): array => ['value' => DemoFormSchema::slug($label), 'label' => $label],
            $labels,
        );

        return [
            self::field('first_name', 'short_text', 'First name', ['half' => true, 'required' => true]),
            self::field('last_name', 'short_text', 'Last name', ['half' => true]),
            self::field('email', 'email', 'Work email', ['required' => true]),
            self::field('phone', 'phone', 'Phone', ['show_optional' => true]),
            self::field('business_name', 'short_text', 'Business name', ['half' => true, 'required' => true]),
            self::field('industry', 'dropdown', 'Industry', [
                'half' => true, 'placeholder' => 'Choose one',
                'options' => $opts(['Event services', 'Photo booth rentals', 'Home services', 'Health & wellness', 'Salons & studios', 'Professional services', 'Marketing agency', 'Other']),
            ]),
            self::field('help_with', 'checkboxes', 'What would you most like help with?', [
                'options' => [
                    ['value' => 'website', 'label' => 'Website'],
                    ['value' => 'followups', 'label' => 'Follow-ups'],
                    ['value' => 'bookings', 'label' => 'Bookings'],
                    ['value' => 'marketing', 'label' => 'SEO & ads'],
                    ['value' => 'crm', 'label' => 'Organizing leads'],
                    ['value' => 'agency', 'label' => 'Agency clients'],
                ],
            ]),
            self::field('message', 'long_text', 'Anything else?', ['show_optional' => true]),
        ];
    }

    /** @return array<string, mixed> */
    private static function field(string $key, string $type, string $label, array $extra = []): array
    {
        return $extra + [
            'key' => $key, 'type' => $type, 'label' => $label, 'placeholder' => '', 'help' => '',
            'required' => false, 'visible' => true, 'half' => false, 'show_optional' => false, 'options' => [],
            'system' => in_array($key, self::SYSTEM, true),
        ];
    }
}
