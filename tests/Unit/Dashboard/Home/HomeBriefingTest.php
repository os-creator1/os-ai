<?php

namespace Tests\Unit\Dashboard\Home;

use App\Library\Dashboard\Home\HomeBriefing;
use App\Library\Dashboard\HomeActivityWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Home V2 §A — the briefing says what the counts prove and nothing more.
 */
class HomeBriefingTest extends TestCase
{
    private function window(string $mode = HomeActivityWindow::MODE_SINCE_LAST_VISIT): HomeActivityWindow
    {
        $now = CarbonImmutable::parse('2026-09-10 15:00:00', 'UTC');

        return new HomeActivityWindow($mode, $now->subDay(), $now, 'UTC', $now->subDay(), 7);
    }

    public function test_it_composes_one_natural_sentence_from_real_counts(): void
    {
        $briefing = (new HomeBriefing())->compose([
            'new_contacts' => 8,
            'appointments_booked' => 3,
            'automations_completed' => 4,
        ], $this->window(), 'Acme');

        $this->assertFalse($briefing['quiet']);
        $this->assertSame(
            'Since your last visit, you gained 8 new contacts, 3 appointments were booked and MotionGrove completed 4 automated tasks.',
            $briefing['text'],
        );
    }

    public function test_it_never_calls_contacts_leads_or_automation_runs_follow_ups(): void
    {
        $text = (new HomeBriefing())->compose([
            'new_contacts' => 1,
            'automations_completed' => 2,
        ], $this->window(), 'Acme')['text'];

        $this->assertStringNotContainsStringIgnoringCase('lead', $text);
        $this->assertStringNotContainsStringIgnoringCase('follow-up', $text);
        $this->assertStringNotContainsStringIgnoringCase('revenue', $text);
        $this->assertStringContainsString('1 new contact', $text);
    }

    public function test_a_quiet_window_is_stated_truthfully(): void
    {
        $briefing = (new HomeBriefing())->compose([], $this->window(HomeActivityWindow::MODE_TODAY), 'Acme');

        $this->assertTrue($briefing['quiet']);
        $this->assertStringStartsWith('No new activity today so far.', $briefing['text']);
    }

    public function test_a_first_visit_has_no_invented_comparison(): void
    {
        $briefing = (new HomeBriefing())->compose(null, null, 'Acme');

        $this->assertTrue($briefing['firstVisit']);
        $this->assertStringNotContainsString('Since your last visit', $briefing['text']);
    }

    public function test_failures_are_reported_as_unfinished_not_hidden(): void
    {
        $text = (new HomeBriefing())->compose([
            'automations_failed' => 1,
        ], $this->window(), 'Acme')['text'];

        $this->assertStringContainsString('1 automated task did not finish', $text);
    }
}
