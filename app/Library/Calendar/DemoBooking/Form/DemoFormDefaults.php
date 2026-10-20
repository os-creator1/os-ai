<?php

namespace App\Library\Calendar\DemoBooking\Form;

/** The Book a Demo form exactly as the approved Claude Design ships it. Used until the Platform Owner publishes a change. */
final class DemoFormDefaults
{
    /**
     * The single definition of what the Platform Owner cannot change. Everything else about a form is editable.
     *
     * SYSTEM:          cannot be removed and keep their built-in type (key and type are what booking, the Contact
     *                  and the answer history rely on). Label, helper text, options and layout stay editable.
     * ALWAYS_VISIBLE:  can never be hidden, whether or not an answer is required.
     * ALWAYS_REQUIRED: must be answered. The others in SYSTEM keep their design default (optional) until edited.
     */
    public const SYSTEM = ['first_name', 'last_name', 'email', 'phone', 'business_name', 'industry'];

    public const ALWAYS_VISIBLE = ['first_name', 'last_name', 'email', 'phone', 'business_name', 'industry'];

    public const ALWAYS_REQUIRED = ['first_name', 'email', 'business_name'];

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
