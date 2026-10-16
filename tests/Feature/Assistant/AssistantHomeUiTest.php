<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/** Home renders the new Ask MotionGrove where the approved designs put it, calls no model on load, and keeps the other cards. */
class AssistantHomeUiTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
    }

    public function test_ask_sits_directly_beneath_the_greeting_and_loading_home_calls_no_model(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Layout Venue', 'Layout Account');
        $this->authenticateAs($customer);

        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $greeting = strpos($html, 'data-role="home-greeting"');
        $ask = strpos($html, 'data-role="home-ask"');
        $move = strpos($html, 'data-role="next-best-move"');
        $this->assertNotFalse($greeting);
        $this->assertGreaterThan($greeting, $ask);
        $this->assertLessThan(strpos($html, 'data-band="needs_attention"') ?: PHP_INT_MAX, $ask);
        $this->assertSame(1, substr_count($html, 'data-role="home-ask"'), 'One assistant, one box.');
        $this->assertSame(0, $this->aiCalls());
        $this->assertStringContainsString('js/home/ask-assistant.js', $html);
        $this->assertStringContainsString('data-role="ask-conversation"', $html);
        $this->assertStringContainsString('Back to dashboard', $html);
    }

    public function test_launch_and_growth_homes_offer_different_contextual_suggestions(): void
    {
        [$launching] = $this->bareTenant(WorkspacePlanTier::Growth, 'Launch Venue', 'Launch Account');
        $this->authenticateAs($launching);
        $launch = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-home-state="setup"', $launch);
        $this->assertStringContainsString('What should I set up first?', $launch);
        $this->assertStringNotContainsString('How is my business doing this month?', $launch);

        [$growing] = $this->tenant(WorkspacePlanTier::Growth, 'Grow Venue', 'Grow Account');
        $this->authenticateAs($growing);
        $grow = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-home-state="setup"', $grow);
        $this->assertStringContainsString('How is my business doing this month?', $grow);
        $this->assertStringContainsString('Which keywords improved recently?', $grow);
        $this->assertStringNotContainsString('What should I set up first?', $grow);
    }

    public function test_a_suggestion_the_actor_cannot_use_is_absent_not_disabled(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Perm Venue', 'Perm Account');
        $this->authenticateAs($customer, ['view_reports']);

        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertStringContainsString('How is my business doing this month?', $html);
        $this->assertStringNotContainsString('Which keywords improved recently?', $html, 'Needs SEO access.');
        $this->assertStringNotContainsString("Show customers who haven't received a reply", $html, 'Needs conversation access.');
    }

    public function test_the_existing_cards_are_still_on_the_page(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Cards Venue', 'Cards Account');
        $this->authenticateAs($customer);

        $html = $this->get(route('user.home'))->assertOk()->getContent();

        foreach (['data-role="home-greeting"', 'data-band="next_best_move"', 'data-band="needs_attention"', 'data-band="headlines"', 'data-band="handling"', 'data-role="command-center"'] as $marker) {
            $this->assertStringContainsString($marker, $html, $marker);
        }
    }

    public function test_the_ask_script_never_builds_markup_from_text(): void
    {
        $js = (string) file_get_contents(public_path('js/home/ask-assistant.js'));

        $this->assertStringNotContainsString('innerHTML', $js);
        $this->assertStringNotContainsString('insertAdjacentHTML', $js);
        $this->assertStringNotContainsString('eval(', $js);
    }
}
