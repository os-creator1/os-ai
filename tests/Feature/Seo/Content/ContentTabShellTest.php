<?php

namespace Tests\Feature\Seo\Content;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\ArticleStatus;
use App\Library\Seo\Content\Autopilot\AutopilotSwitch;
use App\Library\Seo\SeoPhraseNormalizer;
use App\Models\SeoKeyword;
use App\Models\WebsiteArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO -> Content polish: ONE stable shell (title, subtitle, tab strip) around a swappable region, the shared SectionRouter
 * mounted once, real server-rendered URLs for every tab, and the Autopilot / Articles screens driven only by canonical state.
 */
class ContentTabShellTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: \App\Models\Website} */
    private function owner(WorkspacePlanTier $tier = WorkspacePlanTier::Growth): array
    {
        $tenant = $this->photoBoothContentTenant($tier);
        $this->authenticateAsSeoCustomer($tenant[0]);

        return $tenant;
    }

    private function url(array $t, string $name, array $query = []): string
    {
        return rtrim((string) config('app.url'), '/') . route('customer.workspaces.businesses.seo.content.' . $name, [$t[2]->uid, $t[1]->uid], false) . ($query ? '?' . http_build_query($query) : '');
    }

    private function article(array $t, string $title, ArticleStatus $status, ?string $topic = null): WebsiteArticle
    {
        $article = $this->manager()->create((int) $t[0]->user_id, $t[1], ['title' => $title, 'primary_topic' => $topic, 'body' => 'Plain body.']);

        if ($status !== ArticleStatus::Draft) {
            $article->forceFill(['status' => $status, 'published_at' => now()])->save();
        }

        return $article->fresh();
    }

    // ------------------------------------------------------------------ shell + tabs

    public function test_every_section_renders_the_same_header_and_the_four_tabs_with_real_hrefs_and_the_right_active_tab(): void
    {
        $t = $this->owner();
        $expected = [
            'autopilot' => 'Autopilot',
            'articles.index' => 'Articles',
            'plan' => 'Content Plan',
            'opportunities' => 'Opportunities',
        ];

        foreach ($expected as $route => $label) {
            $html = $this->get($this->url($t, $route))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<h4 class="mb-25">Content</h4>'), "one Content title on {$route}");
            $this->assertSame(4, substr_count($html, 'data-content-nav data-content-key'), "four tabs on {$route}");
            $this->assertSame(1, substr_count($html, 'class="content-tab is-active"'), "one active tab on {$route}");
            $this->assertMatchesRegularExpression('#class="content-tab is-active" href="[^"]+" data-content-nav data-content-key="[a-z]+" data-tab="[a-z]+"\s+aria-current="page"\s*>' . preg_quote($label, '#') . '<#', $html);

            foreach ($expected as $other => $_) {
                $this->assertStringContainsString('href="' . $this->url($t, $other) . '"', $html, "{$other} tab is a real link on {$route}");
            }
        }
    }

    public function test_the_fragment_is_just_the_region_with_the_subtitle_and_header_action_and_the_full_page_has_neither_twice(): void
    {
        $t = $this->owner();

        $fragment = $this->get($this->url($t, 'articles.index', ['fragment' => 1]))->assertOk()->getContent();

        $this->assertStringStartsWith('<div id="seo-content-region"', $fragment);
        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('data-nav-key', $fragment, 'no sidebar in a fragment');
        $this->assertStringNotContainsString('class="content-tabs"', $fragment, 'no tab strip in a fragment');
        $this->assertStringContainsString('data-content-section="articles"', $fragment);
        $this->assertStringContainsString('data-content-subtitle="Articles that answer what customers search for, published on your website."', $fragment);
        $this->assertStringContainsString('<template data-content-action>', $fragment);
        $this->assertStringContainsString('data-role="new-article"', $fragment);

        $full = $this->get($this->url($t, 'articles.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($full, 'data-role="new-article"'), 'the header action is rendered once in a full page');
        $this->assertStringNotContainsString('<template data-content-action>', $full);
    }

    public function test_the_shared_section_router_is_loaded_and_mounted_once_and_the_sections_define_no_scripts_of_their_own(): void
    {
        $t = $this->owner();

        foreach (['autopilot', 'articles.index', 'plan', 'opportunities'] as $route) {
            $html = $this->get($this->url($t, $route))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'window.SectionRouter = { mount: mount'), "the router is defined once on {$route}");
            $this->assertSame(1, substr_count($html, 'SectionRouter.mount({'), "the Content router is mounted once on {$route}");
            $this->assertSame(1, substr_count($html, "region.__contentRouterBound = true"), "one listener set on {$route}");
        }
    }

    public function test_a_core_business_sees_the_articles_tab_only(): void
    {
        $t = $this->owner(WorkspacePlanTier::Core);

        $html = $this->get($this->url($t, 'articles.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-content-nav data-content-key'));
        $this->assertStringNotContainsString('data-tab="autopilot"', $html);
        $this->assertStringContainsString('data-role="growth-note"', $html);
    }

    // ------------------------------------------------------------------ autopilot

    public function test_autopilot_shows_the_canonical_switch_state_counts_and_real_quick_action_routes(): void
    {
        $t = $this->owner();

        $off = $this->get($this->url($t, 'autopilot'))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="off"', $off);
        $this->assertStringContainsString('Nothing is being written right now.', $off);
        $this->assertStringContainsString('No articles planned', $off);
        $this->assertSame(['Decides', 'Writes', 'Keeps it current'], array_values(array_filter(['Decides', 'Writes', 'Keeps it current'], fn ($s) => str_contains($off, '<strong>' . $s . '</strong>'))));

        foreach (['autopilot.profile', 'opportunities', 'plan'] as $route) {
            $this->assertStringContainsString('href="' . $this->url($t, $route) . '"', $off, "quick action to {$route}");
        }

        $max = (int) config('seo.content_autopilot.max_new_articles_per_month', 4);
        $this->assertStringContainsString("Up to {$max} a month", $off, 'the monthly maximum is the configured one, not a literal');
        $this->assertSame($max, substr_count($off, '<i class="'), 'one meter segment per allowed article');

        app(AutopilotSwitch::class)->enable($t[1], (int) $t[0]->user_id);

        $on = $this->get($this->url($t, 'autopilot'))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="on"', $on);
        $this->assertMatchesRegularExpression('#data-role="autopilot-badge">On<#', $on);
        $this->assertStringContainsString('data-role="turn-off"', $on);
        $this->assertStringNotContainsString('data-role="turn-on"', $on);
    }

    // ------------------------------------------------------------------ articles

    public function test_articles_show_real_counts_canonical_status_and_the_right_edit_actions(): void
    {
        $t = $this->owner();
        $published = $this->article($t, 'How much does a photo booth cost', ArticleStatus::Published);
        $draft = $this->article($t, 'Wedding photo booth ideas', ArticleStatus::Draft);

        $html = $this->get($this->url($t, 'articles.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-role="filter-all"[^>]*>All 2<#', $html);
        $this->assertMatchesRegularExpression('#data-role="filter-published"[^>]*>Published 1<#', $html);
        $this->assertMatchesRegularExpression('#data-role="filter-draft"[^>]*>Drafts 1<#', $html);
        $this->assertStringNotContainsString('data-role="filter-scheduled"', $html, 'a state with no articles gets no pill');

        $this->assertMatchesRegularExpression('#data-article="' . $published->uid . '" data-status="published"#', $html);
        $this->assertMatchesRegularExpression('#data-article="' . $draft->uid . '" data-status="draft"#', $html);

        $edit = route('customer.workspaces.businesses.seo.content.articles.edit', [$t[2]->uid, $t[1]->uid, $published->uid]);
        $continue = route('customer.workspaces.businesses.seo.content.articles.edit', [$t[2]->uid, $t[1]->uid, $draft->uid]);

        $this->assertStringContainsString('href="' . $edit . '" data-role="edit-article"', $html);
        $this->assertStringContainsString('data-role="open-live"', $html, 'a published article links to its live page');
        $this->assertStringContainsString('href="' . $continue . '" data-role="continue-editing"', $html);
        $this->assertSame(1, substr_count($html, 'data-role="continue-editing"'), 'only the draft says Continue editing');
        $this->assertSame(1, substr_count($html, 'data-role="open-live"'));
    }

    public function test_articles_filter_by_status_and_search_by_title_or_topic(): void
    {
        $t = $this->owner();
        $this->article($t, 'How much does a photo booth cost', ArticleStatus::Published);
        $this->article($t, 'Wedding photo booth ideas', ArticleStatus::Draft, 'guest favours');

        $only = fn (string $html) => preg_match_all('#data-article="([0-9a-f-]{36})"#', $html);

        $this->assertSame(1, $only($this->get($this->url($t, 'articles.index', ['status' => 'published']))->getContent()));
        $this->assertSame(1, $only($this->get($this->url($t, 'articles.index', ['status' => 'draft']))->getContent()));
        $this->assertSame(1, $only($this->get($this->url($t, 'articles.index', ['q' => 'wedding']))->getContent()));
        $this->assertSame(1, $only($this->get($this->url($t, 'articles.index', ['q' => 'favours']))->getContent()), 'the topic is searched too');
        $this->assertSame(0, $only($this->get($this->url($t, 'articles.index', ['q' => 'wedding', 'status' => 'published']))->getContent()));

        $html = $this->get($this->url($t, 'articles.index', ['q' => 'wedding', 'status' => 'draft']))->getContent();
        $this->assertStringContainsString('name="status" value="draft"', $html, 'a search keeps the status filter');
        $this->assertStringContainsString('value="wedding"', $html);
        $this->assertStringContainsString('status=published&amp;q=wedding', $html, 'filter pills keep the search');
    }

    public function test_supports_keyword_shows_a_tracked_keyword_only_when_the_canonical_relation_exists(): void
    {
        $t = $this->owner();
        $linked = $this->article($t, 'Photo booth rental cost', ArticleStatus::Published, 'Photo Booth Rental Cost');
        $this->article($t, 'No topic article', ArticleStatus::Draft);
        $this->article($t, 'Topic without keyword', ArticleStatus::Draft, 'something untracked');

        $keyword = new SeoKeyword(['phrase' => 'photo booth rental cost', 'phrase_normalized' => SeoPhraseNormalizer::normalize('photo booth rental cost')]);
        $keyword->business_id = $t[1]->id;
        $keyword->forceFill(['lifecycle_state' => 'active'])->save();

        $html = $this->get($this->url($t, 'articles.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>photo booth rental cost</a>'), 'the linked keyword is named');
        $this->assertSame(2, substr_count($html, 'None linked'), 'the others honestly say none');
        $this->assertSame($linked->uid, WebsiteArticle::query()->where('title', 'Photo booth rental cost')->value('uid'));
    }

    public function test_the_empty_articles_state_keeps_the_shell_and_offers_writing(): void
    {
        $t = $this->owner();

        $html = $this->get($this->url($t, 'articles.index'))->assertOk()->getContent();

        $this->assertStringContainsString('No articles yet.', $html);
        $this->assertStringContainsString('content-tabs', $html);
        $this->assertStringContainsString('Write an article', $html);
    }

    public function test_pagination_links_never_carry_the_fragment_flag(): void
    {
        $t = $this->owner();

        for ($i = 0; $i < 22; $i++) {
            $this->article($t, 'Article number ' . $i, ArticleStatus::Draft);
        }

        $html = $this->get($this->url($t, 'articles.index', ['fragment' => 1, 'status' => 'draft']))->assertOk()->getContent();

        $this->assertStringContainsString('page=2', $html);
        $this->assertStringNotContainsString('fragment=1', preg_replace('#<template.*?</template>#s', '', $html) ?? '');
        $this->assertStringContainsString('status=draft', $html);
    }
}
