<?php

namespace Tests\Feature\Seo\Rank;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Models\Business;
use App\Models\SeoKeyword;
use App\Models\SeoRankTarget;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankObservations;
use Tests\TestCase;

/**
 * Search keywords page: the three designed states (start, checking, results), the
 * best-performers-first order, and the track flows the redesign must not break.
 * Fake provider only: no request leaves the process.
 */
class SeoKeywordsPageStatesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;
    use CreatesRankObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    private function url(string $name, Workspace $workspace, Business $business, ?string $uid = null): string
    {
        return route('customer.workspaces.businesses.seo.' . $name, array_filter([$workspace->uid, $business->uid, $uid]));
    }

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    /** @return list<string> */
    private function texts(string $html, string $query): array
    {
        $out = [];

        foreach ($this->xpath($html)->query($query) as $node) {
            $out[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
        }

        return $out;
    }

    /** Keyword uids in the order the table lists them. @return list<string> */
    private function order(string $html): array
    {
        return $this->texts($html, "//table[@data-role='rank-table']/tbody/tr/@data-uid");
    }

    private function role(string $html, string $role): array
    {
        return $this->texts($html, "//*[@data-role='{$role}']");
    }

    private function page(Workspace $workspace, Business $business): string
    {
        return (string) $this->get($this->url('keywords.index', $workspace, $business))->assertOk()->getContent();
    }

    private function strategy(string ...$patterns): void
    {
        $reader = Mockery::mock(BlueprintConfigReader::class);
        $reader->shouldReceive('seoStrategy')->andReturn([
            'keyword_patterns' => array_map(fn (string $p) => ['pattern' => $p, 'intent' => 'transactional'], $patterns),
            'faq_topics' => [], 'schema_types' => [], 'internal_links' => [],
        ]);
        $this->app->instance(BlueprintConfigReader::class, $reader);
    }

    // ------------------------------------------------------------------
    // Sorting, end to end
    // ------------------------------------------------------------------

    public function test_the_best_performers_are_listed_first_whatever_order_they_were_created_in(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant();
        $this->authenticateAsSeoCustomer($owner);

        $none = $this->keyword($owner, $business, 'no result keyword');
        $this->track($owner, $business, $none);
        $notTop = $this->keyword($owner, $business, 'not in top keyword');
        $this->obs($this->track($owner, $business, $notTop), 'organic', null, null, 'not_found');
        $maps9 = $this->keyword($owner, $business, 'maps nine');
        $this->obs($this->track($owner, $business, $maps9), 'local', 9);
        $both = $this->keyword($owner, $business, 'organic two maps seven');
        $bothTarget = $this->track($owner, $business, $both);
        $this->obs($bothTarget, 'organic', 2);
        $this->obs($bothTarget, 'local', 7);
        $organic14 = $this->keyword($owner, $business, 'organic fourteen');
        $this->obs($this->track($owner, $business, $organic14), 'organic', 14);
        $untracked = $this->keyword($owner, $business, 'untracked keyword');

        $order = $this->order($this->page($workspace, $business));

        $this->assertSame(
            [$both->uid, $maps9->uid, $organic14->uid, $none->uid, $notTop->uid, $untracked->uid],
            $order,
            'Ranked rows ascending by their better rank (2, 9, 14), then rows with no numeric rank in created order.'
        );
    }

    // ------------------------------------------------------------------
    // Start
    // ------------------------------------------------------------------

    public function test_the_start_state_offers_suggestions_and_track_all(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant();
        $this->createLocation($business, true, BusinessServiceMode::Storefront, ['city' => 'Naperville']);
        $this->strategy('photo booth rental {city}', 'wedding photo booth {city}');
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->page($workspace, $business);

        $this->assertNotEmpty($this->role($html, 'page-subtitle'));
        $this->assertSame(['Track all 2 suggestions'], $this->role($html, 'track-all'));
        $this->assertStringContainsString('Start tracking your searches', $html);
        $this->assertStringContainsString('Suggested for you', $html);
        $this->assertSame(['Track', 'Track'], $this->role($html, 'suggestion-add'));
        $this->assertSame([], $this->role($html, 'rank-table'), 'Nothing to list yet: no table, no summary cards.');
        $this->assertSame([], $this->texts($html, "//*[@data-section='rank-summary']"));
        $this->assertNotEmpty($this->texts($html, "//*[@data-section='add-keyword']//input[@name='phrase']"), 'The add-a-keyword panel is always there.');
        $this->assertSame(['Track keyword'], $this->texts($html, "//*[@data-section='add-keyword']//button[@type='submit']"));
        $this->assertSame(['0 of 20 used · rank is checked automatically'], $this->role($html, 'slots-used'));
    }

    public function test_the_start_state_has_no_track_all_for_a_viewer_who_cannot_manage(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant();
        $this->createLocation($business, true, BusinessServiceMode::Storefront, ['city' => 'Naperville']);
        $this->strategy('photo booth rental {city}');
        $this->authenticateAsCustomer($owner, ['view_seo']);

        $html = $this->page($workspace, $business);

        $this->assertSame([], $this->role($html, 'track-all'));
        $this->assertSame([], $this->role($html, 'suggestion-add'));
        $this->assertStringNotContainsString('Start tracking your searches', $html);
        $this->assertSame([], $this->texts($html, "//*[@data-section='add-keyword']"));
    }

    // ------------------------------------------------------------------
    // Checking
    // ------------------------------------------------------------------

    public function test_the_checking_state_shows_the_banner_skeletons_and_dashes(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant(WorkspacePlanTier::Growth, null);
        $keyword = $this->keyword($owner, $business);
        $this->authenticateAsSeoCustomer($owner);
        FakeSeoRankProvider::$completeImmediately = false;
        $this->post($this->url('keywords.rank.track', $workspace, $business, $keyword->uid), ['search_location_code' => self::CHICAGO])->assertRedirect();

        $html = $this->page($workspace, $business);

        $this->assertSame(['Checking your first rankings — usually takes a few minutes. You can leave this page.'], $this->role($html, 'checking-banner'));
        $this->assertSame(['—'], $this->role($html, 'summary-local-top3'));
        $this->assertSame(['—'], $this->role($html, 'summary-best-local'));
        $this->assertSame(['Needs your domain'], $this->role($html, 'summary-best-organic'));
        $this->assertNotEmpty($this->role($html, 'domain-callout'), 'No domain: the "publish on your domain" callout explains the Google search column.');
        $this->assertStringContainsString('checking…', $html);
        $this->assertGreaterThan(0, $this->xpath($html)->query("//tr[@data-rank-state='checking']//*[contains(@class,'kw-sk')]")->length, 'Checking rows render the skeleton.');
    }

    // ------------------------------------------------------------------
    // Results
    // ------------------------------------------------------------------

    public function test_the_results_state_shows_pills_summary_and_no_checking_banner(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant();
        $this->authenticateAsSeoCustomer($owner);
        $a = $this->keyword($owner, $business, 'corporate photo booth');
        $this->obs($this->track($owner, $business, $a), 'local', 6);
        $b = $this->keyword($owner, $business, 'magic mirror photo booth');
        $this->obs($this->track($owner, $business, $b), 'local', 2);

        $html = $this->page($workspace, $business);

        $this->assertSame([], $this->role($html, 'checking-banner'));
        $this->assertSame([], $this->role($html, 'domain-callout'), 'A connected domain needs no callout.');
        $this->assertSame(['Local #2', 'Local #6'], $this->role($html, 'rank-value'), 'Best pill first.');
        $this->assertSame(['#2'], $this->role($html, 'summary-best-local'));
        $this->assertSame(['1'], $this->role($html, 'summary-local-top3'));
        $this->assertSame(['2 / 20'], $this->role($html, 'summary-tracked'));
        $this->assertSame(['green', 'blue'], array_map(
            fn (string $class) => preg_match('/kw-rk-(green|blue|amber)/', $class, $m) ? $m[1] : '',
            $this->texts($html, "//*[@data-role='rank-value']/@class")
        ));
    }

    // ------------------------------------------------------------------
    // Existing flows
    // ------------------------------------------------------------------

    public function test_tracking_a_single_suggestion_adds_the_keyword_and_starts_rank_tracking(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant();
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->url('keywords.store', $workspace, $business), [
            'phrase' => 'photo booth rental toronto', 'track_rank' => '1', 'search_location_code' => (string) self::CHICAGO,
        ])->assertRedirect()->assertSessionHas('status', 'success');

        $this->assertSame(1, SeoKeyword::query()->count());
        $this->assertSame(1, SeoRankTarget::query()->count());
        $this->assertStringStartsWith('Keyword added and rank tracking started.', (string) session('message'));
    }

    public function test_track_all_saves_and_tracks_every_suggestion_from_the_posted_location(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant();
        $this->createLocation($business, true, BusinessServiceMode::Storefront, ['city' => 'Naperville']);
        $this->strategy('photo booth rental {city}', 'wedding photo booth {city}');
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->url('keywords.rank.track-suggestions', $workspace, $business), ['search_location_code' => (string) self::CHICAGO])
            ->assertRedirect($this->url('keywords.index', $workspace, $business))->assertSessionHas('status', 'success');

        $this->assertEqualsCanonicalizing(
            ['photo booth rental Naperville', 'wedding photo booth Naperville'],
            SeoKeyword::query()->pluck('phrase')->all()
        );
        $this->assertSame(2, SeoRankTarget::query()->count());
        $this->assertStringStartsWith('2 keywords added, 2 now rank-tracked.', (string) session('message'));

        // Nothing left to suggest: a second press is a no-op, never a duplicate.
        $this->post($this->url('keywords.rank.track-suggestions', $workspace, $business))->assertRedirect();
        $this->assertSame(2, SeoKeyword::query()->count());
        $this->assertSame('There are no suggestions left to add.', (string) session('message'));
    }

    public function test_track_all_ignores_any_phrase_posted_by_the_client(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant();
        $this->createLocation($business, true, BusinessServiceMode::Storefront, ['city' => 'Naperville']);
        $this->strategy('photo booth rental {city}');
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->url('keywords.rank.track-suggestions', $workspace, $business), [
            'search_location_code' => (string) self::CHICAGO, 'phrase' => 'injected phrase', 'phrases' => ['injected phrase'],
        ])->assertRedirect();

        $this->assertSame(['photo booth rental Naperville'], SeoKeyword::query()->pluck('phrase')->all());
    }

    public function test_track_all_without_a_location_saves_the_keywords_and_says_tracking_is_off(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant();
        $this->createLocation($business, true, BusinessServiceMode::Storefront, ['city' => 'Naperville']);
        $this->strategy('photo booth rental {city}');
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->url('keywords.rank.track-suggestions', $workspace, $business))->assertRedirect();

        $this->assertSame(1, SeoKeyword::query()->count());
        $this->assertSame(0, SeoRankTarget::query()->count());
        $this->assertStringStartsWith('1 keyword added. Rank tracking is off', (string) session('message'));
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);
    }

    public function test_track_all_needs_manage_seo(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant();
        $this->createLocation($business, true, BusinessServiceMode::Storefront, ['city' => 'Naperville']);
        $this->strategy('photo booth rental {city}');
        $this->authenticateAsCustomer($owner, ['view_seo']);

        $this->post($this->url('keywords.rank.track-suggestions', $workspace, $business), ['search_location_code' => (string) self::CHICAGO])->assertUnauthorized();

        $this->assertSame(0, SeoKeyword::query()->count());
    }

    public function test_the_search_location_chip_uses_the_tracked_location(): void
    {
        [$owner, $business, $workspace] = $this->rankTenant();
        $this->authenticateAsSeoCustomer($owner);
        $this->track($owner, $business, $this->keyword($owner, $business));

        $html = $this->page($workspace, $business);

        $this->assertSame(['Chicago, Illinois, United States'], $this->role($html, 'search-location-pill'));
    }
}
