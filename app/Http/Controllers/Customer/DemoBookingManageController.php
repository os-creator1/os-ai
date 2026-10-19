<?php

namespace App\Http\Controllers\Customer;

use App\Enums\Calendar\AppointmentStatus;
use App\Exceptions\Calendar\BookingRefusedException;
use App\Models\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * A guest's cancel / reschedule of their own /book-demo appointment.
 *
 * Authority is the signed, expiring link (`signed` middleware), and then four
 * more checks on every request: the inherited six-check public stack for the
 * designated Booking Type, the appointment must BELONG to that Booking Type,
 * still be scheduled in the future, and the link's reschedule count must equal
 * the appointment's current one (so a link dies when it is used to move the
 * appointment). Every refusal is the same 404. The change itself is the
 * canonical AppointmentBookingService::reschedule / ::cancel, which take the
 * staff locks, re-verify availability, and fire the lifecycle events that keep
 * reminders and CRM automations in step. A per-appointment cache lock
 * serialises two clicks on one link so the count check cannot be raced.
 */
class DemoBookingManageController extends DemoBookingController
{
    public function manage(Request $request, string $appointmentUid): \Illuminate\Http\Response
    {
        [$appointment, $type, $location, $business] = $this->manageable($request, $appointmentUid);
        $timezone = $this->timezoneFor($business);

        $date = $request->query('date');
        $slots = [];
        if ($date !== null) {
            abort_unless(is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);
            [$year, $month, $day] = array_map('intval', explode('-', $date));
            abort_unless(checkdate($month, $day, $year), 404);
            [$first, $last] = $this->slots->window($timezone, $type);
            if ($date >= $first && $date <= $last) {
                $slots = $this->slots->slotsForDay($location, $type, $this->eligibleStaffIds($type, $location), $date, $timezone, $timezone);
            }
        }
        [$first, $last] = $this->slots->window($timezone, $type);

        return $this->noStore(view('public.booking.manage', [
            'type' => $type,
            'brand' => $this->brand($type, $business, $location, []),
            'timezone' => $timezone,
            'current' => $this->summary($type, $business, $location, $appointment->start_at->copy(), $timezone),
            // The participant join link only (never the host link), and only while the meeting is ready and at the current time.
            'joinUrl' => \App\Library\Calendar\Zoom\ZoomLinkGate::joinUrl($appointment),
            'date' => is_string($date) ? $date : null,
            'slots' => $slots,
            'min' => $first,
            'max' => $last,
            'actionUrl' => $request->fullUrl(),
            'baseUrl' => $request->url(),
            'signed' => $request->only(['expires', 'rc', 'signature']),
            'done' => null,
        ]));
    }

    public function act(Request $request, string $appointmentUid): RedirectResponse|\Illuminate\Http\Response
    {
        [$appointment, $type, $location, $business] = $this->manageable($request, $appointmentUid);
        $action = $request->input('action');
        abort_unless(in_array($action, ['cancel', 'reschedule'], true), 404);

        $lock = Cache::lock('demo-booking-manage:'.$appointment->id, 15);
        if (! $lock->get()) {
            return back()->withErrors(['time' => 'Please try again in a moment.']);
        }

        try {
            // Re-read under the lock: a concurrent use of this link may have just moved or cancelled it.
            [$appointment] = $this->manageable($request, $appointmentUid);

            if ($action === 'cancel') {
                try {
                    $this->booking->cancel($appointment, null, 'Cancelled by guest');
                } catch (BookingRefusedException) {
                    abort(404); // resolved by someone else between the check and the write
                }

                return $this->noStore(view('public.booking.manage', [
                    'type' => $type, 'brand' => $this->brand($type, $business, $location, []), 'done' => 'cancelled',
                ]));
            }

            $timezone = $this->timezoneFor($business);
            $data = $request->validate([
                'date' => ['required', 'date_format:Y-m-d'],
                'time' => ['required', 'date_format:H:i'],
            ]);
            $start = $this->slots->onGrid($data['time'], $type) ? $this->slots->instantFor($data['date'], $data['time'], $timezone) : null;
            if ($start === null || ! $this->slots->mayBook($type, $timezone, $data['date'], $start)) {
                return back()->withErrors(['time' => 'Choose an available time.']);
            }

            try {
                $this->booking->reschedule($appointment, $start);
            } catch (BookingRefusedException) {
                return back()->withErrors(['time' => 'That time is no longer available. Choose another time.']);
            }
        } finally {
            $lock->release();
        }

        // The old link is dead (the reschedule count moved); the confirmation page needs no authority.
        $moved = Appointment::query()->findOrFail($appointment->id);
        $request->session()->flash('booking_confirmation', $this->summary($type, $business, $location, $moved->start_at->copy(), $timezone)
            + ['notice' => 'Your appointment was moved. Your reminders now follow the new time.']);

        return redirect()->route('demo.booking.confirmed');
    }

    /**
     * @return array{0: Appointment, 1: \App\Models\BookingType, 2: \App\Models\BusinessLocation, 3: \App\Models\Business}
     */
    private function manageable(Request $request, string $appointmentUid): array
    {
        // The date the guest is browsing is the only parameter allowed to change under a signature.
        abort_unless($request->hasValidSignatureWhileIgnoring(['date']), 404);

        [$type, $location, $business] = $this->resolve($this->designatedUuid());

        $appointment = Appointment::query()
            ->where('uid', $appointmentUid)
            ->where('booking_type_id', $type->id)
            ->first() ?? abort(404);

        abort_unless(
            $appointment->status === AppointmentStatus::Scheduled
                && $appointment->start_at->greaterThan(Carbon::now())
                && (string) $request->query('rc') === (string) (int) $appointment->reschedule_count,
            404
        );

        return [$appointment, $type, $location, $business];
    }

    /** A page about one guest's appointment is never stored by a browser cache or a shared one. */
    private function noStore(View $view): \Illuminate\Http\Response
    {
        return response($view)->header('Cache-Control', 'no-store, private');
    }
}
