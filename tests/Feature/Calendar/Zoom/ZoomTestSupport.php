<?php

namespace Tests\Feature\Calendar\Zoom;

use App\Library\Calendar\Notifications\AppointmentNotificationScheduler;
use App\Library\Calendar\Notifications\AppointmentNotificationSender;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\BookingType;
use App\Models\User;
use App\Models\WorkspacePlanCatalog;
use App\Models\ZoomConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;

/** Shared fixtures for the Zoom tests: a Growth Business, a Zoom-enabled booking type and a faked Zoom. */
trait ZoomTestSupport
{
    use CreatesCalendarHttpFixtures;

    protected const JOIN_URL = 'https://us05web.zoom.us/j/81234567890?pwd=abc123';
    protected const START_URL = 'https://us05web.zoom.us/s/81234567890?zak=SECRET-HOST-TOKEN';

    protected BookingType $type;

    protected function bootZoom(string $tier = 'growth'): void
    {
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootCalendarHttpFixtures();
        $this->setTier($tier);

        config([
            'zoom.client_id' => 'zoom-client-id',
            'zoom.client_secret' => 'zoom-client-secret',
            'zoom.redirect' => 'https://app.example.test/zoom/oauth/callback',
        ]);

        $this->type = $this->bookingType(null, ['name' => 'Demo', 'notify_email' => true, 'meeting_location_type' => 'zoom']);
    }

    protected function setTier(string $tier): void
    {
        DB::table('workspace_plan_assignments')->where('workspace_id', $this->workspace->id)->update([
            'workspace_plan_catalog_id' => WorkspacePlanCatalog::query()->where('tier', $tier)->value('id'),
        ]);
    }

    protected function connect(User $user, ?int $businessId = null, string $token = 'access-token-A'): ZoomConnection
    {
        return ZoomConnection::query()->create([
            'business_id' => $businessId ?? $this->business->id,
            'user_id' => $user->id,
            'state' => ZoomConnection::STATE_ACTIVE,
            'access_token_encrypted' => $token,
            'refresh_token_encrypted' => 'refresh-token-A',
            'access_expires_at' => now()->addHour(),
            'zoom_account_email' => 'host@zoom.example',
            'connected_at' => now(),
        ]);
    }

    protected function fakeZoom(array $overrides = []): void
    {
        // A fresh client: the first matching fake wins, so a later call must replace (not append to) the earlier one.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake($overrides + [
            'https://api.zoom.us/v2/users/me/meetings' => Http::response([
                'id' => 81234567890, 'join_url' => self::JOIN_URL, 'start_url' => self::START_URL,
            ], 201),
            'https://api.zoom.us/v2/users/me/meetings?*' => Http::response(['meetings' => []]),
            'https://api.zoom.us/v2/users/me' => Http::response(['id' => 'zu1', 'email' => 'host@zoom.example', 'first_name' => 'Hal', 'last_name' => 'Host']),
            'https://api.zoom.us/v2/meetings/*' => Http::response('', 204),
            'https://zoom.us/oauth/token' => Http::response(['access_token' => 'access-token-NEW', 'refresh_token' => 'refresh-token-NEW', 'expires_in' => 3600]),
        ]);
    }

    protected function lines(\Illuminate\Notifications\Messages\MailMessage $mail): string
    {
        return implode(' ', array_map('strval', array_merge($mail->introLines, $mail->outroLines)));
    }

    protected function zoomCalls(string $method, string $needle = ''): int
    {
        return Http::recorded()->filter(fn ($pair) => $pair[0]->method() === $method && str_contains($pair[0]->url(), $needle))->count();
    }

    protected function bookZoom(User $staff, ?\Carbon\CarbonInterface $at = null, ?BookingType $type = null): Appointment
    {
        $type ??= $this->type;
        $appointment = $this->engine()->book($type, (int) $staff->id, $this->contactId(), $at ?? $this->slotStart());

        app(AppointmentNotificationScheduler::class)->recordPublicBooking($appointment, $type, 'guest@example.test', '', false);

        return $appointment->fresh();
    }

    protected function deliverDue(): void
    {
        foreach (AppointmentNotification::query()->where('status', 'pending')->where('scheduled_for', '<=', now('UTC'))->pluck('id') as $id) {
            app(AppointmentNotificationSender::class)->deliver((int) $id);
        }
    }
}
