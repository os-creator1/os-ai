<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Library\Calendar\BookingTypeManager;
use App\Library\NicheBlueprint\Workspace\BlueprintChecksum;
use App\Models\Business;
use App\Models\BookingType;
use App\Models\BusinessLocation;
use InvalidArgumentException;
use RuntimeException;

/**
 * Blueprint V2 — a Booking Type TEMPLATE: duration, notice, buffers, slot
 * interval guidance. Delegates to `BookingTypeManager::create`.
 *
 * Created INACTIVE: a Booking Type is publicly reachable by its UUID once it is
 * active and has staff, and an installed template has neither staff nor a
 * reviewing owner. A Booking Type belongs to exactly one Location, so the
 * install needs one: the Business's first non-archived Location. A Business
 * with no Location yet makes this component `failed` (retryable), which the
 * `blueprint:install-missing` sweep re-attempts once a Location exists — it is
 * never papered over by inventing a Location.
 */
final class BookingTypeComponentAdapter implements BlueprintComponentAdapter, BlueprintComponentDefinition, FingerprintsInstalledComponent
{
    use InteractsWithBlueprintPayload;

    public const TYPE = 'booking_type';

    public function __construct(private readonly BookingTypeManager $bookingTypes) {}

    public function componentType(): string
    {
        return self::TYPE;
    }

    public function validateDescriptor(array $payload): void
    {
        $this->parse($payload);
    }

    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
    {
        $attributes = $this->parse($payload);

        $location = BusinessLocation::query()
            ->where('business_id', $business->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first();

        if ($location === null) {
            throw new RuntimeException('blueprint_booking_type_needs_a_location');
        }

        $owner = $actorUserId ?? (int) $business->workspace()->value('owner_user_id');

        $bookingType = $this->bookingTypes->create($location, $attributes + ['is_active' => false], $owner);

        return new InstalledComponentReference('booking_type', (int) $bookingType->id);
    }

    public function fingerprint(Business $business, InstalledComponentReference $reference, array $payload): ?string
    {
        $type = BookingType::query()->whereKey($reference->recordId)->first();

        if ($type === null) {
            return null;
        }

        return BlueprintChecksum::of([
            $type->name, (int) $type->duration_minutes, (int) $type->minimum_notice_minutes,
            (int) $type->buffer_before_minutes, (int) $type->buffer_after_minutes, (int) $type->slot_interval_minutes,
            (int) $type->booking_window_days,
        ]);
    }

    /** @return array<string, mixed> */
    private function parse(array $payload): array
    {
        $out = [
            'name' => $this->requireString($payload, 'name', 120),
            'duration_minutes' => $this->intInRange($payload['duration_minutes'] ?? null, 'duration_minutes', 5, 1440)
                ?? throw new InvalidArgumentException('"duration_minutes" is required.'),
            'description' => $this->optionalString($payload, 'description', 2000),
            'meeting_instructions' => $this->optionalString($payload, 'meeting_instructions', 2000),
        ];

        $color = $this->optionalString($payload, 'color', 16);

        if ($color !== null) {
            $out['color'] = $color;
        }

        foreach ([
            'booking_window_days' => [1, 365],
            'minimum_notice_minutes' => [0, 525600],
            'buffer_before_minutes' => [0, 480],
            'buffer_after_minutes' => [0, 480],
            'slot_interval_minutes' => [5, 480],
        ] as $key => [$min, $max]) {
            $value = $this->intInRange($payload[$key] ?? null, $key, $min, $max);

            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public function surface(): string
    {
        return 'calendar';
    }

    public function updatePolicy(): BlueprintUpdatePolicy
    {
        return BlueprintUpdatePolicy::Copy;
    }

    public function featureKey(): string
    {
        return 'calendar';
    }

    public function typeLabel(): string
    {
        return 'Booking type';
    }

    public function summary(array $payload): string
    {
        return ($payload['name'] ?? '?').': '.($payload['duration_minutes'] ?? '?').' min, notice '
            .($payload['minimum_notice_minutes'] ?? 0).' min (inactive on install)';
    }

    public function formFields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Booking type name', 'type' => 'text', 'required' => true],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => false],
            ['name' => 'duration_minutes', 'label' => 'Duration (minutes)', 'type' => 'number', 'required' => true],
            ['name' => 'minimum_notice_minutes', 'label' => 'Minimum notice (minutes)', 'type' => 'number', 'required' => false],
            ['name' => 'buffer_before_minutes', 'label' => 'Buffer before (minutes)', 'type' => 'number', 'required' => false],
            ['name' => 'buffer_after_minutes', 'label' => 'Buffer after (minutes)', 'type' => 'number', 'required' => false],
            ['name' => 'slot_interval_minutes', 'label' => 'Slot interval (minutes)', 'type' => 'number', 'required' => false],
            ['name' => 'booking_window_days', 'label' => 'Bookable this many days ahead', 'type' => 'number', 'required' => false],
            ['name' => 'meeting_instructions', 'label' => 'Meeting instructions', 'type' => 'textarea', 'required' => false],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $payload = [];

        foreach ($this->formFields() as $field) {
            $value = trim((string) ($input[$field['name']] ?? ''));

            if ($value !== '') {
                $payload[$field['name']] = $field['type'] === 'number' ? (int) $value : $value;
            }
        }

        $this->parse($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        return $payload;
    }
}
