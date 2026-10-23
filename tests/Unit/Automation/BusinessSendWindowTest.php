<?php

namespace Tests\Unit\Automation;

use App\Library\Automation\Workflow\Runtime\BusinessSendWindow;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class BusinessSendWindowTest extends TestCase
{
    private const TZ = 'America/Denver';

    private function at(string $local): Carbon
    {
        return Carbon::parse($local, self::TZ);
    }

    private function hours(): array
    {
        $d = [['open' => '09:00', 'close' => '17:00']];

        return ['monday' => $d, 'tuesday' => $d, 'wednesday' => $d, 'thursday' => $d, 'friday' => $d, 'saturday' => [], 'sunday' => []];
    }

    public function test_quiet_hours_are_overnight_in_the_business_timezone(): void
    {
        $w = new BusinessSendWindow(self::TZ, null);

        $this->assertTrue($w->isQuiet($this->at('2026-11-03 21:00')));
        $this->assertTrue($w->isQuiet($this->at('2026-11-03 02:30')));
        $this->assertTrue($w->isQuiet($this->at('2026-11-03 07:59')));
        $this->assertFalse($w->isQuiet($this->at('2026-11-03 08:00')));
        $this->assertFalse($w->isQuiet($this->at('2026-11-03 20:59')));
        // 15:00 UTC is 08:00 in Denver (MST): the SAME instant is judged by local time, never UTC.
        $this->assertFalse($w->isQuiet(Carbon::parse('2026-11-03 15:00', 'UTC')));
        $this->assertTrue($w->isQuiet(Carbon::parse('2026-11-03 14:59', 'UTC')));
    }

    public function test_next_allowed_after_quiet_hours_is_the_next_morning_local(): void
    {
        $w = new BusinessSendWindow(self::TZ, null);

        $next = $w->nextAllowed($this->at('2026-11-03 22:15'));
        $this->assertSame('2026-11-04 08:00', $next->setTimezone(self::TZ)->format('Y-m-d H:i'));

        $this->assertSame('2026-11-03 08:00', $w->nextAllowed($this->at('2026-11-03 03:00'))->setTimezone(self::TZ)->format('Y-m-d H:i'));
        $already = $this->at('2026-11-03 12:00');
        $this->assertTrue($w->nextAllowed($already)->equalTo($already), 'An allowed instant is returned unchanged.');
    }

    public function test_daylight_saving_never_shifts_the_window(): void
    {
        $w = new BusinessSendWindow(self::TZ, null);

        // Clocks fall back Sun 2026-11-01 02:00 MDT -> 01:00 MST. 22:00 Saturday -> 08:00 Sunday local either way.
        $next = $w->nextAllowed($this->at('2026-10-31 22:00'));
        $this->assertSame('2026-11-01 08:00', $next->setTimezone(self::TZ)->format('Y-m-d H:i'));
        // Spring forward Sun 2027-03-14.
        $next = $w->nextAllowed($this->at('2027-03-13 23:00'));
        $this->assertSame('2027-03-14 08:00', $next->setTimezone(self::TZ)->format('Y-m-d H:i'));
    }

    public function test_business_hours_are_required_only_when_asked_and_recorded(): void
    {
        $open = new BusinessSendWindow(self::TZ, $this->hours(), requireBusinessHours: true);

        $this->assertTrue($open->isAllowed($this->at('2026-11-03 10:00')), 'Tuesday 10:00 is open.');
        $this->assertFalse($open->isAllowed($this->at('2026-11-03 18:00')), 'After close, though not quiet hours.');
        $this->assertFalse($open->isAllowed($this->at('2026-11-07 10:00')), 'Saturday is closed.');
        $this->assertSame('2026-11-09 09:00', $open->nextAllowed($this->at('2026-11-07 10:00'))->setTimezone(self::TZ)->format('Y-m-d H:i'), 'Next opening is Monday 09:00.');
        $this->assertSame('2026-11-04 09:00', $open->nextAllowed($this->at('2026-11-03 18:00'))->setTimezone(self::TZ)->format('Y-m-d H:i'));

        $notAsked = new BusinessSendWindow(self::TZ, $this->hours(), requireBusinessHours: false);
        $this->assertTrue($notAsked->isAllowed($this->at('2026-11-03 18:00')), 'Only quiet hours bind when business hours are not requested.');

        $unknown = new BusinessSendWindow(self::TZ, null, requireBusinessHours: true);
        $this->assertTrue($unknown->isAllowed($this->at('2026-11-07 10:00')), 'No recorded hours: unknown is never treated as closed.');
    }

    public function test_hours_that_never_open_still_resolve_instead_of_parking_forever(): void
    {
        $closed = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], []);
        $w = new BusinessSendWindow(self::TZ, $closed, requireBusinessHours: true);

        $next = $w->nextAllowed($this->at('2026-11-03 10:00'));
        $this->assertTrue($next->greaterThan($this->at('2026-11-03 10:00')));
        $this->assertFalse($w->isQuiet($next));
    }
}
