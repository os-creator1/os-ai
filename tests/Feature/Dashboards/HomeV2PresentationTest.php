<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Presentation guards for the final Home: the open Ask conversation can never leave a blank screen, the redundant page heading
 * recedes, and Done for you stays concise.
 */
class HomeV2PresentationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function home(): string
    {
        return $this->get(route('user.home'))->assertOk()->getContent();
    }

    public function test_the_ask_card_is_nested_so_the_open_state_must_keep_its_ancestors_visible(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth, 'Blank Guard', 'Blank Account');
        $this->authenticateAs($owner);
        $html = $this->home();

        // The root cause: the legacy rule hides every DIRECT child of .home-command except .home-ask, but in Home V2 the Ask card lives inside
        // the layout. So the layout wraps it...
        $layout = strpos($html, 'class="hv2-layout"');
        $ask = strpos($html, 'class="mb-2 home-ask"');
        $this->assertNotFalse($layout);
        $this->assertGreaterThan($layout, $ask, 'The Ask card is nested inside the layout, not a direct child of the command center.');
        $this->assertStringContainsString('.home-command.is-chat-open > *:not(.home-ask) { display: none !important; }', $html, 'The legacy rule is still present.');

        // ...and the V2 styles must exempt the layout and every ancestor of the card, while receding only their OTHER children.
        $this->assertStringContainsString('.home-command.hv2.is-chat-open > .hv2-layout { display: block !important; }', $html);
        $this->assertStringContainsString('.home-command.hv2.is-chat-open .hv2-layout > *:not(:has(.home-ask)) { display: none !important; }', $html);
        $this->assertStringContainsString('.home-command.hv2.is-chat-open .hv2-main > *:not(.home-ask)', $html);
        $this->assertStringContainsString('.home-command.hv2.is-chat-open .home-ask { display: block !important; }', $html);
    }

    public function test_the_assistant_script_returns_to_home_if_the_open_conversation_is_not_actually_visible(): void
    {
        $script = (string) file_get_contents(public_path('js/home/ask-assistant.js'));

        $this->assertStringContainsString('function ensureVisible()', $script);
        $this->assertStringContainsString('window.requestAnimationFrame(ensureVisible)', $script);
        $this->assertStringContainsString("root.classList.remove('is-chat-open')", $script, 'A presentation state can never leave the screen blank.');
    }

    public function test_the_redundant_page_heading_recedes_visually_but_stays_for_screen_readers(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth, 'Heading Venue', 'Heading Account');
        $this->authenticateAs($owner);
        $html = $this->home();

        $this->assertStringContainsString('customer-dashboard--hv2', $html);
        $this->assertStringContainsString('.customer-dashboard--hv2 > [data-role="dashboard-header"]', $html);
        $this->assertMatchesRegularExpression('#<h1[^>]*id="dashboard-title"#', $html, 'The page outline keeps its heading.');
        $this->assertStringContainsString('data-role="home-salutation"', $html, 'The greeting leads.');
    }

    public function test_many_website_publications_show_one_latest_outcome_on_home(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Publish Venue', 'Publish Account');
        $this->website($business, 'published');
        foreach ([1, 2, 3] as $version) {
            $this->websiteRevision($business, $version, \Carbon\CarbonImmutable::now()->subHours(10 - $version)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s'));
        }
        $this->authenticateAs($owner);

        $html = $this->home();
        $done = substr($html, (int) strpos($html, 'data-role="home-handling"'), 6000);

        $this->assertSame(1, substr_count($done, 'data-role="recent-work-item"'), 'One concise latest-published outcome, not one row per version.');
        $this->assertStringContainsString('data-role="handling-view-activity"', $done, 'The full history stays one click away.');
    }

    public function test_suggested_rows_carry_an_icon_and_only_real_suggestions(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth, 'Icon Venue', 'Icon Account');
        $this->authenticateAs($owner);
        $html = $this->home();

        $items = substr_count($html, 'data-role="suggested-item"');
        $this->assertGreaterThan(0, $items);
        $this->assertLessThanOrEqual(3, $items);
        $this->assertSame($items, substr_count($html, 'class="hv2-suggest__icon"'));
    }
    private function waitingCard(string $html): string
    {
        preg_match('#<(a|div)\b[^>]*data-role="kpi" data-kpi="waiting".*?</\1>#s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_the_third_card_is_the_real_waiting_count_with_honest_states(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Waiting Venue', 'Waiting Account');
        $this->authenticateAs($owner);

        // None waiting: a real zero, calm.
        $none = $this->waitingCard($this->home());
        $this->assertStringContainsString('data-role="headline-figure">0<', $none);
        $this->assertStringContainsString('All replied', $none);

        // Two customers wrote last and are waiting: the figure, "Need reply", and the inbox's own waiting list.
        $old = \Carbon\CarbonImmutable::now()->subHours(2)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
        $this->conversationWith($business, [['incoming', $old]]);
        $this->conversationWith($business, [['incoming', $old]]);
        \Illuminate\Support\Facades\Cache::flush();
        $two = $this->waitingCard($this->home());
        $this->assertStringContainsString('data-role="headline-figure">2<', $two);
        $this->assertStringContainsString('Need reply', $two);
        $this->assertStringContainsString('filter=waiting', html_entity_decode($two));
        $this->assertStringNotContainsString('▲', $two, 'A current state has no period comparison.');

        // An unreadable source is unavailable, never zero.
        \Illuminate\Support\Facades\DB::listen(function ($query) {
            if (str_contains($query->sql, 'chat_box_messages')) {
                throw new \RuntimeException('inbox store unavailable');
            }
        });
        \Illuminate\Support\Facades\Cache::flush();
        $failed = $this->waitingCard($this->home());
        $this->assertStringContainsString('data-role="headline-figure">—<', $failed);
        $this->assertStringContainsString('Unavailable', $failed);
    }

    public function test_a_quiet_visit_leads_with_the_plain_greeting_line(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet Greeting', 'Quiet Greeting Account');
        $this->authenticateAs($owner);

        $this->assertStringContainsString('Here&#039;s what&#039;s happening with your business.', $this->home());
    }
}
