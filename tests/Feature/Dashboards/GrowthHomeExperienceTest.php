<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * The Growth Home contract (the standard Home contract lives in the HomeV2* tests, on Core fixtures): its own sections, the
 * Ask card, and truthful empty states — and none of it on a Core Home.
 */
class GrowthHomeExperienceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    private function html(WorkspacePlanTier $tier, string $name): string
    {
        [$customer] = $this->tenant($tier, $name, $name . ' Account');
        $this->authenticateAs($customer);

        return $this->get(route('user.home'))->assertOk()->getContent();
    }

    public function test_growth_home_has_its_sections_the_ask_card_and_a_next_move_slot(): void
    {
        $html = $this->html(WorkspacePlanTier::Growth, 'Growth Sections');

        foreach (['data-role="growth-home"', 'data-role="growth-performance"', 'data-role="growth-activity"', 'data-band="ask"'] as $marker) {
            $this->assertStringContainsString($marker, $html, $marker);
        }
        $this->assertSame(1, substr_count($html, 'data-role="home-ask"'), 'One assistant, one box.');
        $this->assertStringContainsString('gh-root', $html, 'The scoped mobile styles hook is present.');
    }

    public function test_core_home_never_carries_growth_only_sections(): void
    {
        $html = $this->html(WorkspacePlanTier::Core, 'Core Sections');

        foreach (['data-role="growth-home"', 'data-role="growth-performance"', 'data-role="growth-evidence"'] as $marker) {
            $this->assertStringNotContainsString($marker, $html, $marker);
        }
        $this->assertStringContainsString('data-role="home-greeting"', $html);
    }
}
