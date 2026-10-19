<?php

namespace App\Library\Calendar\Zoom;

use App\Exceptions\Calendar\ZoomHostUnavailableException;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BookingType;
use App\Models\BusinessLocation;
use App\Models\ZoomConnection;

/**
 * Meeting-location rules and Zoom host selection, shared by the booking engine and the provisioning job.
 *
 * HOST RULE. A Booking Type either has NO shared host (the staff member assigned to each booking hosts it, using THEIR OWN
 * connection in THIS Business) or one explicitly configured shared host (a connection that must belong to the same Business).
 * Nothing falls back to another staff member, another Business or an Agency owner's connection.
 */
final class ZoomHostResolver
{
    public const TYPES = ['zoom', 'in_person', 'phone', 'custom'];

    /** @param  array{type?: ?string, custom?: ?string}|null  $override */
    public static function effective(BookingType $type, ?array $override = null): array
    {
        $chosen = $override['type'] ?? null;

        if (in_array($chosen, self::TYPES, true)) {
            return ['type' => $chosen, 'custom' => $chosen === 'custom' ? self::cleanCustom($override['custom'] ?? null) : null];
        }

        $default = in_array($type->meeting_location_type, self::TYPES, true) ? $type->meeting_location_type : null;

        return ['type' => $default, 'custom' => $default === 'custom' ? self::cleanCustom($type->meeting_location_custom) : null];
    }

    /** Attributes copied onto the new appointment. A non-Zoom appointment gets no Zoom state at all. */
    public static function snapshot(BookingType $type, ?array $override = null): array
    {
        $effective = self::effective($type, $override);

        return [
            'meeting_location_type' => $effective['type'],
            'meeting_location_custom' => $effective['custom'],
            'zoom_status' => $effective['type'] === 'zoom' ? 'pending' : null,
        ];
    }

    /** @return array|null the override, unchanged, once it is known the appointment can get a Zoom host */
    public static function assertBookable(BookingType $type, int $staffUserId, ?array $override): ?array
    {
        if (self::effective($type, $override)['type'] === 'zoom' && self::hostFor($type, $staffUserId) === null) {
            throw ZoomHostUnavailableException::forStaff($staffUserId);
        }

        return $override;
    }

    /** @param  array<int, int>  $pool */
    public static function assertPoolBookable(BookingType $type, array $pool, ?array $override): ?array
    {
        if (self::effective($type, $override)['type'] === 'zoom') {
            foreach ($pool as $staffUserId) {
                if (self::hostFor($type, (int) $staffUserId) === null) {
                    throw ZoomHostUnavailableException::forStaff((int) $staffUserId);
                }
            }
        }

        return $override;
    }

    /** The connection that would host a booking for this staff member under this type, or null. */
    public static function hostFor(BookingType $type, int $staffUserId): ?ZoomConnection
    {
        $business = self::businessOf($type);

        if ($business === null) {
            return null;
        }

        $manager = app(ZoomConnectionManager::class);

        if (! $manager->isEnabledFor($business)) {
            return null;
        }

        return $type->zoom_host_connection_id !== null
            ? $manager->activeInBusiness((int) $type->zoom_host_connection_id, (int) $business->id)
            : $manager->activeFor((int) $business->id, $staffUserId);
    }

    /** The host for an existing appointment: the connection it was created under, else the current rule. */
    public static function hostForAppointment(Appointment $appointment): ?ZoomConnection
    {
        $type = BookingType::query()->find($appointment->booking_type_id);
        $business = $type === null ? null : self::businessOf($type);

        if ($type === null || $business === null) {
            return null;
        }

        if ($appointment->zoom_connection_id !== null) {
            // The recorded host, but only while it still belongs to this Business: the meeting lives in that Zoom account.
            return ZoomConnection::query()->whereKey($appointment->zoom_connection_id)->where('business_id', $business->id)->first();
        }

        return self::hostFor($type, (int) $appointment->staff_user_id);
    }

    public static function businessOf(BookingType $type): ?Business
    {
        $location = BusinessLocation::query()->find($type->business_location_id);

        return $location === null ? null : Business::query()->find($location->business_id);
    }

    /** Readiness for public booking: a Zoom type is only bookable when EVERY configured host can actually host. */
    public static function readinessProblem(BookingType $type, array $staffUserIds): ?string
    {
        if (self::effective($type)['type'] !== 'zoom') {
            return null;
        }

        $business = self::businessOf($type);

        if ($business === null || ! app(ZoomConnectionManager::class)->isEnabledFor($business)) {
            return 'Zoom is not available for this calendar yet.';
        }

        if ($type->zoom_host_connection_id !== null) {
            return self::hostFor($type, 0) === null ? 'The Zoom host account is disconnected. Reconnect it in Settings → Integrations → Zoom.' : null;
        }

        foreach ($staffUserIds as $id) {
            if (self::hostFor($type, (int) $id) === null) {
                return 'Every host for this booking type must connect Zoom (Settings → Integrations → Zoom).';
            }
        }

        return $staffUserIds === [] ? 'Add a host who has connected Zoom.' : null;
    }

    private static function cleanCustom(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
