<?php

namespace Tests\Unit\Dashboard\Home;

use App\Library\Dashboard\Home\HomeSetupJourney;
use PHPUnit\Framework\TestCase;

/**
 * The Launch journey: progress is counted from canonical facts, never stored or invented, and the
 * Launch → Growth rule (foundation AND reach) is the only gate.
 */
class HomeSetupJourneyV1Test extends TestCase
{
    /** A Business that finished onboarding with no website, no form and no calendar yet. */
    private function facts(array $o = []): array
    {
        return $o + [
            'policy' => HomeSetupJourney::POLICY_SEO_FIRST,
            'hasIdentity' => true, 'hasLocation' => true, 'hasPrimaryService' => true,
            'websiteCapable' => true, 'formsCapable' => true, 'calendarCapable' => false, 'bookingPathReady' => false,
            'websiteStatus' => null, 'websiteMode' => null,
            'hasCompletedExternalAudit' => false, 'hasLiveForm' => false, 'hasActiveWebsiteForm' => false,
            'calendar' => false, 'typeCount' => 0, 'activeTypeCount' => 0, 'readyCount' => 0, 'problemReason' => null,
        ];
    }

    private function urls(array $o = []): array
    {
        return $o + ['locations' => '/l', 'bookingType' => '/bt', 'fixBooking' => '/fix', 'website' => '/w', 'forms' => '/f', 'business' => '/ob', 'service' => '/os'];
    }

    private function build(array $facts = [], array $urls = []): array
    {
        return (new HomeSetupJourney())->build($this->facts($facts), $this->urls($urls));
    }

    private function step(array $journey, string $key): array
    {
        return collect($journey['steps'])->firstWhere('key', $key) ?? $this->fail("No [{$key}] step.");
    }

    public function test_completed_onboarding_shows_every_foundation_step_done_and_never_asks_again(): void
    {
        $j = $this->build();

        foreach (['business', 'location', 'service'] as $key) {
            $this->assertTrue($this->step($j, $key)['done'], "{$key} is a completed foundation.");
            $this->assertNull($this->step($j, $key)['action'], "{$key} is never requested again.");
        }
        $this->assertTrue($j['foundationComplete']);
    }

    public function test_a_missing_foundation_is_asked_for_with_a_link_only_where_one_exists(): void
    {
        $j = $this->build(['hasIdentity' => false, 'hasLocation' => false, 'hasPrimaryService' => false]);

        $this->assertFalse($j['foundationComplete']);
        $this->assertTrue($j['essentialIncomplete']);
        $this->assertSame('business', $j['nextKey']);
        $this->assertSame('/os', $this->step($j, 'service')['action']['url']);
        $this->assertSame('/l', $this->step($j, 'location')['action']['url']);

        $none = $this->build(['hasPrimaryService' => false], ['service' => null]);
        $this->assertNull($this->step($none, 'service')['action'], 'No dead link when the actor cannot reach the fix.');
    }

    public function test_no_website_makes_setting_one_up_the_next_step_and_keeps_home_in_launch(): void
    {
        $j = $this->build();

        $this->assertTrue($j['essentialIncomplete']);
        $this->assertFalse($j['reachComplete']);
        $this->assertSame('website', $j['nextKey']);
        $this->assertSame('Set up your website', $this->step($j, 'website')['title']);
    }

    public function test_the_four_website_states_are_described_accurately_and_only_a_published_site_is_done(): void
    {
        $cases = [
            'hosted, no site yet' => [['websiteMode' => 'hosted'], 'Create your website', false],
            'hosted draft' => [['websiteMode' => 'hosted', 'websiteStatus' => 'draft'], 'Finish and publish your website', false],
            'archived' => [['websiteMode' => 'hosted', 'websiteStatus' => 'archived'], 'Republish your website', false],
            'published' => [['websiteMode' => 'hosted', 'websiteStatus' => 'published'], 'Your website is live', true],
            'existing, unchecked' => [['websiteMode' => 'external'], 'Review your existing website', false],
            'existing, checked' => [['websiteMode' => 'external', 'hasCompletedExternalAudit' => true], 'Your existing website', true],
            'later' => [['websiteMode' => 'none'], 'Set up your website', false],
        ];

        foreach ($cases as $label => [$facts, $title, $done]) {
            $step = $this->step($this->build($facts), 'website');
            $this->assertSame($title, $step['title'], $label);
            $this->assertSame($done, $step['done'], $label);
        }
    }

    public function test_a_saved_existing_address_is_not_evidence_of_reach(): void
    {
        $j = $this->build(['websiteMode' => 'external']);

        $this->assertFalse($j['reachComplete']);
        $this->assertTrue($j['essentialIncomplete']);
    }

    public function test_a_published_site_or_a_checked_existing_site_leaves_launch(): void
    {
        $this->assertFalse($this->build(['websiteStatus' => 'published', 'websiteMode' => 'hosted'])['essentialIncomplete']);
        $this->assertFalse($this->build(['websiteMode' => 'external', 'hasCompletedExternalAudit' => true])['essentialIncomplete']);
    }

    public function test_a_stale_audit_of_a_site_that_is_no_longer_the_primary_source_counts_for_nothing(): void
    {
        $j = $this->build(['websiteMode' => 'hosted', 'hasCompletedExternalAudit' => true]);

        $this->assertFalse($this->step($j, 'website')['done']);
        $this->assertTrue($j['essentialIncomplete']);
    }

    public function test_lead_capture_reflects_a_working_path_not_the_existence_of_a_booking_type(): void
    {
        $unready = $this->build(['calendarCapable' => true, 'calendar' => true, 'typeCount' => 1, 'activeTypeCount' => 1, 'readyCount' => 0, 'problemReason' => 'No one is assigned.']);
        $this->assertFalse($this->step($unready, 'lead_capture')['done'], 'A booking type that cannot take bookings is not a path.');
        $this->assertSame('No one is assigned.', $this->step($unready, 'bookable')['note']);
        $this->assertTrue($unready['essentialIncomplete']);

        $ready = $this->build(['calendarCapable' => true, 'bookingPathReady' => true, 'calendar' => true, 'typeCount' => 1, 'activeTypeCount' => 1, 'readyCount' => 1]);
        $this->assertTrue($this->step($ready, 'lead_capture')['done']);
        $this->assertFalse($ready['essentialIncomplete'], 'A working booking path is reach.');

        $form = $this->build(['hasLiveForm' => true]);
        $this->assertTrue($this->step($form, 'lead_capture')['done']);
        $this->assertFalse($form['essentialIncomplete']);

        $notEntitled = $this->build(['hasLiveForm' => true, 'formsCapable' => false]);
        $this->assertFalse($this->step($notEntitled, 'lead_capture')['done'], 'A form the plan no longer covers is not a path.');
    }

    public function test_a_website_form_counts_only_when_its_site_is_published(): void
    {
        $this->assertTrue($this->step($this->build(['hasActiveWebsiteForm' => true, 'websiteStatus' => 'published', 'websiteMode' => 'hosted']), 'lead_capture')['done']);
        $this->assertFalse($this->step($this->build(['hasActiveWebsiteForm' => true, 'websiteStatus' => 'draft', 'websiteMode' => 'hosted']), 'lead_capture')['done']);
    }

    public function test_calendar_is_optional_and_never_essential_on_its_own(): void
    {
        $none = $this->build(['calendarCapable' => true, 'calendar' => true]);
        $this->assertNull(collect($none['steps'])->firstWhere('key', 'bookable'), 'No booking steps until the customer chooses Calendar.');
        $this->assertNull(collect($none['steps'])->firstWhere('key', 'booking_type'));

        $chosen = $this->build(['calendarCapable' => true, 'calendar' => true, 'typeCount' => 1, 'activeTypeCount' => 1, 'readyCount' => 0]);
        $this->assertFalse($this->step($chosen, 'bookable')['essential']);
    }

    public function test_a_photobooth_without_calendar_progresses_through_launch(): void
    {
        $launch = $this->build();
        $this->assertTrue($launch['essentialIncomplete']);

        $grown = $this->build(['websiteStatus' => 'published', 'websiteMode' => 'hosted']);
        $this->assertFalse($grown['essentialIncomplete']);
        $this->assertNull(collect($grown['steps'])->firstWhere('key', 'bookable'));
    }

    public function test_a_plan_with_no_route_to_reach_is_not_held_in_launch_but_missing_permission_never_waives_reach(): void
    {
        // No route at ALL for this Business (plan/module capability): nothing to ask for.
        $none = $this->build(['websiteCapable' => false, 'formsCapable' => false, 'calendarCapable' => false]);
        $this->assertTrue($none['reachComplete']);
        $this->assertFalse($none['essentialIncomplete']);
        $this->assertNull(collect($none['steps'])->firstWhere('key', 'website'));

        // The same Business seen by an actor with NO links: identical classification, steps without actions.
        $owner = $this->build();
        $employee = $this->build([], ['website' => null, 'forms' => null, 'locations' => null, 'service' => null, 'business' => null]);
        $this->assertSame($owner['essentialIncomplete'], $employee['essentialIncomplete']);
        $this->assertSame($owner['reachComplete'], $employee['reachComplete']);
        $this->assertSame($owner['nextKey'], $employee['nextKey']);
        $this->assertNull($this->step($employee, 'website')['action'], 'No unauthorised link.');
        $this->assertNull($this->step($employee, 'lead_capture')['action']);
    }

    public function test_calendar_detail_is_display_only_and_never_changes_readiness(): void
    {
        $withDetail = $this->build(['calendarCapable' => true, 'calendar' => true, 'typeCount' => 3, 'activeTypeCount' => 3, 'readyCount' => 2]);
        $without = $this->build(['calendarCapable' => true, 'calendar' => false]);

        $this->assertSame($without['essentialIncomplete'], $withDetail['essentialIncomplete'], 'Actor-scoped counts must not decide readiness.');
    }

    public function test_optional_integrations_are_never_steps(): void
    {
        $keys = array_column($this->build(['calendarCapable' => true, 'calendar' => true, 'typeCount' => 1, 'activeTypeCount' => 1])['steps'], 'key');

        foreach (['google', 'gbp', 'search_console', 'mailbox', 'sms', 'stripe', 'ads'] as $integration) {
            $this->assertNotContains($integration, $keys);
        }
    }

    public function test_progress_is_counted_from_the_steps(): void
    {
        $j = $this->build(['websiteStatus' => 'published', 'websiteMode' => 'hosted', 'hasLiveForm' => true]);

        $this->assertSame($j['total'], $j['done']);
        $this->assertNull($j['nextKey']);
    }
}
