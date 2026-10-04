<?php

namespace Tests\Feature\Calendar;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * Calendar event rendering — short appointments (15/20/30 minutes) were drawn
 * as thin boxes whose text was white on a pale background, padded so tightly
 * that the first line was cut off.
 *
 * The fix is presentation only: a box keeps its TRUE duration height, and the
 * content adapts (compact padding/line height, time and title on one line for
 * the shortest). This file pins (a) that the server still sends exact
 * durations — nothing pads an appointment to look longer — and (b) the
 * rendering contract in the page's script and styles. The pixel behaviour
 * itself (no clipped time at 1440, 1024 and 768 px, day and week, overlaps)
 * is verified in a real browser; see the lane report.
 */
class CalendarEventRenderingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->entitledCalendar();
    }

    private function weekHtml(): string
    {
        $url = $this->calendarUrl('schedule', $this->scopeFor($this->locationA)) . '?' . http_build_query(['view' => 'week', 'date' => '2027-03-01']);

        return $this->get($url)->assertOk()->getContent();
    }

    /** @return list<array<string, mixed>> */
    private function events(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" data-role="calendar-events">(.*?)</script>#s', $html, $m));

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_every_duration_reaches_the_calendar_unpadded(): void
    {
        $this->authenticate($this->owner->user);
        $staff = $this->bookableStaff($this->locationA);

        $bookings = [15 => '10:00', 20 => '11:00', 30 => '12:00', 45 => '13:00', 60 => '14:00'];

        foreach ($bookings as $minutes => $time) {
            $this->engine()->book(
                $this->bookingType($this->locationA, ['name' => "Visit {$minutes}", 'duration_minutes' => $minutes]),
                (int) $staff->id,
                $this->contactId(),
                Carbon::parse("2027-03-01 {$time}", 'America/New_York')->utc()
            );
        }

        $events = collect($this->events($this->weekHtml()))->keyBy(fn ($e) => (int) explode(' ', $e['title'])[1]);

        foreach ($bookings as $minutes => $time) {
            $start = Carbon::parse("2027-03-01 {$time}");
            $this->assertSame($start->format('Y-m-d\TH:i:s'), $events[$minutes]['start']);
            $this->assertSame($start->copy()->addMinutes($minutes)->format('Y-m-d\TH:i:s'), $events[$minutes]['end'], "a {$minutes}-minute appointment must end exactly {$minutes} minutes after it starts");
        }
    }

    public function test_the_script_classifies_events_by_duration_and_renders_time_then_title(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->weekHtml();

        $this->assertStringContainsString('COMPACT_MAX_MINUTES = 30', $html);
        $this->assertStringContainsString('SINGLE_LINE_MAX_MINUTES = 25', $html);
        $this->assertStringContainsString('calendar-event--compact', $html);
        $this->assertStringContainsString('calendar-event--single', $html);
        $this->assertStringContainsString('eventClassNames:', $html);
        $this->assertStringContainsString('eventContent:', $html);
        // The full details ride on the tooltip so a hidden title is never lost.
        $this->assertStringContainsString("info.el.setAttribute('title'", $html);
        // Start–end in 24 h, the same format as the agenda list below the grid.
        $this->assertStringContainsString('function clock(d)', $html);
    }

    public function test_the_slot_height_that_fixes_every_events_true_size_is_unchanged(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->weekHtml();

        $this->assertMatchesRegularExpression('/\.fc-timegrid-slot\s*\{\s*height:\s*2\.6em;/', $html);
        $this->assertStringNotContainsString('slotDuration', $html);
        $this->assertStringNotContainsString('slotEventOverlap', $html);
    }

    public function test_short_event_styles_shrink_the_content_never_the_box(): void
    {
        $this->authenticate($this->owner->user);

        $html = $this->weekHtml();

        // Text colour: FullCalendar's stylesheet forces white, unreadable on the pale status colours.
        $this->assertMatchesRegularExpression('/\.fc-event \.fc-event-main\s*\{[^}]*color:\s*inherit;/', $html);
        // Compact content: reduced padding and line height.
        $this->assertMatchesRegularExpression('/\.fc-event\.calendar-event--compact\s*\{[^}]*padding:\s*0;/', $html);
        $this->assertMatchesRegularExpression('/\.calendar-event--compact \.calendar-event-body\s*\{[^}]*line-height:\s*1\.15;/', $html);
        // The shortest share one line, time first, and the TITLE takes the ellipsis.
        $this->assertMatchesRegularExpression('/\.calendar-event--single \.calendar-event-body\s*\{[^}]*flex-direction:\s*row;/', $html);
        $this->assertMatchesRegularExpression('/\.calendar-event--compact \.calendar-event-title\s*\{[^}]*text-overflow:\s*ellipsis;/', $html);
        // No rule may stretch an event to a minimum height: that would make the timeline lie.
        $this->assertDoesNotMatchRegularExpression('/calendar-event[^{]*\{[^}]*min-height/', $html);
        $this->assertDoesNotMatchRegularExpression('/\.fc-timegrid-event[^{]*\{[^}]*min-height/', $html);
    }
}
