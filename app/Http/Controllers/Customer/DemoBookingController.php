<?php

namespace App\Http\Controllers\Customer;

use App\Enums\GoogleAds\LeadAttributionEntrySurface;
use App\Enums\GoogleAds\LeadAttributionSubjectType;
use App\Exceptions\Calendar\BookingRefusedException;
use App\Library\Calendar\DemoBooking\DemoBookingContactResolver;
use App\Library\Calendar\DemoBooking\TurnstileVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * `/book-demo`: MotionGrove's own "Book a Demo" page.
 *
 * Not a second calendar. It is a thin adapter onto the same canonical public
 * booking engine PublicBookingController uses, pointed at ONE designated
 * Booking Type of the platform's internal Business (config
 * demo_booking.booking_type_uuid). Every availability, notice, buffer,
 * time-off, staff, entitlement and double-booking rule is inherited: the
 * inherited resolve() re-runs the six-check authority stack, and the booking
 * itself goes through AppointmentBookingService.
 *
 * What differs is only the form (business name, optional website, optional
 * phone), the Contact resolver, and the anti-abuse layer: a honeypot and a
 * server-verified Cloudflare Turnstile token, on top of the route throttle.
 * Anything not configured fails closed with the same refusal.
 */
class DemoBookingController extends PublicBookingController
{
    public const HONEYPOT_FIELD = 'demo_hp';

    public function page(Request $request, TurnstileVerifier $turnstile): View
    {
        $uuid = $this->designatedUuid($turnstile);
        $view = $this->show($request, $uuid);

        return $view->with('demo', [
            'showUrl' => route('demo.booking.show'),
            'storeUrl' => route('demo.booking.store'),
            'honeypot' => self::HONEYPOT_FIELD,
            'turnstileSiteKey' => $turnstile->isConfigured() ? $turnstile->siteKey() : null,
        ])->with('config', array_replace($view->getData()['config'], [
            'datesUrl' => route('demo.booking.dates'),
            'slotsUrl' => route('demo.booking.slots'),
            'storeUrl' => route('demo.booking.store'),
        ]))->with('offerSmsConsent', false);
    }

    public function demoDates(Request $request, TurnstileVerifier $turnstile): JsonResponse
    {
        return $this->dates($request, $this->designatedUuid($turnstile));
    }

    public function demoSlots(Request $request, TurnstileVerifier $turnstile): JsonResponse
    {
        return $this->slots($request, $this->designatedUuid($turnstile));
    }

    public function submit(
        Request $request,
        TurnstileVerifier $turnstile,
        DemoBookingContactResolver $contactResolver,
    ): RedirectResponse|JsonResponse {
        $uuid = $this->designatedUuid($turnstile);
        // Re-run the identical authority stack before inspecting any guest input.
        [$type, $location, $business] = $this->resolve($uuid);
        $wantsJson = $request->ajax();

        // A bot that fills the hidden field is told it succeeded; nothing is written or sent.
        if (trim((string) $request->input(self::HONEYPOT_FIELD)) !== '') {
            return $wantsJson
                ? $this->json(['message' => 'Please check your details.', 'errors' => []], 422)
                : redirect()->route('demo.booking.confirmed');
        }

        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'business_name' => ['required', 'string', 'max:190'],
            'website' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);
        $validator->after(function ($validator) use ($request, $type): void {
            $phone = trim((string) $request->input('phone'));
            $digits = trim(str_replace(['+', '-', '(', ')', ' '], '', $phone));
            if ($phone !== '' && ! $validator->errors()->has('phone') && ($digits === '' || strlen($digits) > 32 || ! ctype_digit($digits))) {
                $validator->errors()->add('phone', 'Enter a valid phone number.');
            }
            if (! $validator->errors()->has('website') && $this->normalizeWebsite($request->input('website')) === false) {
                $validator->errors()->add('website', 'Enter a valid website address, or leave it blank.');
            }
            if (! $validator->errors()->has('time') && ! $this->slots->onGrid((string) $request->input('time'), $type)) {
                $validator->errors()->add('time', 'Choose an available time.');
            }
        });
        if ($validator->fails()) {
            return $wantsJson
                ? $this->json(['message' => 'Please check your details.', 'errors' => $validator->errors()->toArray()], 422)
                : throw new ValidationException($validator);
        }

        // Token verification is server-side only. It comes after cheap validation so a bad form never spends a verification.
        if (! $turnstile->verify($request->input('cf-turnstile-response'), $request->ip(), $request->getHost())) {
            $message = 'We could not verify that you are human. Please try again.';

            return $wantsJson
                ? $this->json(['message' => $message, 'errors' => ['turnstile' => [$message]]], 422)
                : back()->withInput()->withErrors(['turnstile' => $message]);
        }

        $data = $validator->validated();
        $data['website'] = $this->normalizeWebsite($data['website'] ?? null) ?: null;
        $timezone = $this->timezoneFor($business);
        $start = $this->slots->instantFor($data['date'], $data['time'], $timezone);
        if ($start === null || ! $this->slots->mayBook($type, $timezone, $data['date'], $start)) {
            return $this->refused($wantsJson, 'Choose an available time.');
        }

        try {
            $appointment = $this->booking->bookWithRoundRobinContactResolver(
                $type,
                $start,
                fn (): int => $contactResolver->resolve($location, $business, $data),
            );
        } catch (BookingRefusedException) {
            return $this->refused($wantsJson, 'That time is no longer available. Choose another time.', 409);
        } catch (ValidationException $exception) {
            if ($wantsJson) {
                return $this->json(['message' => 'Please check your details.', 'errors' => $exception->errors()], 422);
            }
            throw $exception;
        }

        // After commit, never able to fail the booking.
        $this->attribution->record(
            $business,
            (int) $location->id,
            (int) $appointment->contact_id,
            LeadAttributionSubjectType::Appointment,
            (int) $appointment->id,
            LeadAttributionEntrySurface::Booking,
            $request,
        );
        try {
            $this->notifications->recordPublicBooking($appointment, $type, (string) $data['email'], trim((string) ($data['phone'] ?? '')), false);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $visitorTimezone = $this->visitorTimezone($request, $timezone, 'visitor_timezone');
        $summary = $this->summary($type, $business, $location, $start, $visitorTimezone)
            + ['notice' => $this->noticeLine($type, (string) $data['email'], false)];
        if ($wantsJson) {
            return $this->json(['status' => 'confirmed', 'booking' => $summary], 201);
        }
        $request->session()->flash('booking_confirmation', $summary);

        return redirect()->route('demo.booking.confirmed');
    }

    public function demoConfirmed(): View
    {
        // Read-only: it must keep working after a guest changes a booking, whatever the bot-check state.
        return $this->confirmed($this->designatedUuid());
    }

    /**
     * The designated Booking Type's public uuid, or the one refusal every
     * public booking failure uses. A missing designation, and (when a verifier
     * is passed, i.e. for anything that creates a booking) incomplete anti-bot
     * configuration, land here before anything else is read.
     */
    protected function designatedUuid(?TurnstileVerifier $turnstile = null): string
    {
        $uuid = config('demo_booking.booking_type_uuid');
        abort_unless(is_string($uuid) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid), 404);
        abort_unless($turnstile === null || $turnstile->isAvailable(), 404);

        return strtolower($uuid);
    }

    /** @return string|false|null  normalized https URL, null when blank, false when invalid */
    private function normalizeWebsite(mixed $raw): string|false|null
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://'.$value;
        }
        $parts = parse_url($value);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if ($host === '' || ! str_contains($host, '.') || ! preg_match('/^[a-z0-9.-]+$/i', $host)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return $value;
    }
}
