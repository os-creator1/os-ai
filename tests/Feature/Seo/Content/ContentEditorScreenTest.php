<?php

namespace Tests\Feature\Seo\Content;

use App\Models\WebsiteArticle;
use App\Models\WebsiteAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * The redesigned article editor screen: what it shows for an ordinary draft, an AI draft and an overlapping draft, and that
 * what the owner types survives a save and a reload. Local fixtures only; no AI call is made.
 */
class ContentEditorScreenTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: \App\Models\Website} */
    private function owner(): array
    {
        $tenant = $this->photoBoothContentTenant();
        $this->authenticateAsSeoCustomer($tenant[0]);

        return $tenant;
    }

    private function url(array $tenant, string $name, array $extra = []): string
    {
        return rtrim((string) config('app.url'), '/') . route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$tenant[2]->uid, $tenant[1]->uid], $extra), false);
    }

    private function formFor(array $overrides = []): array
    {
        return array_merge([
            'title' => 'How Much Space Does a Photo Booth Need?',
            'slug' => '',
            'excerpt' => 'A practical look at the floor space, power and layout a photo booth needs at your venue.',
            'body' => $this->goodBody(self::PAGE_WEDDING),
            'seo_title' => '',
            'meta_description' => 'A practical look at the floor space, power and layout a photo booth needs at your venue, with tips for planning.',
            'primary_topic' => 'how much space does a photo booth need',
            'search_intent' => 'planning',
            'supports_page_uid' => self::PAGE_WEDDING,
            'noindex' => '0',
        ], $overrides);
    }

    public function test_the_editor_has_the_designed_regions_in_the_designed_order(): void
    {
        $t = $this->owner();
        $article = $this->draftArticle($t[1]);

        $html = $this->get($this->url($t, 'articles.edit', [$article->uid]))->assertOk()->getContent();

        $order = ['data-role="editor-top"', 'data-role="article-form"', 'data-role="md-toolbar"', 'data-role="word-stats"', 'data-role="search-appearance"',
            'data-role="publish-panel"', 'data-role="analysis"', 'data-role="featured-image"', 'data-role="link-suggestions"'];
        $last = -1;

        foreach ($order as $needle) {
            $at = strpos($html, $needle);
            $this->assertNotFalse($at, $needle . ' is on the page');
            $this->assertGreaterThan($last, $at, $needle . ' follows the previous region');
            $last = $at;
        }

        $this->assertSame(1, substr_count($html, 'name="title"'));
        $this->assertSame(1, substr_count($html, 'name="body"'));
        $this->assertStringNotContainsString('data-md="image"', $html, 'the Markdown body has no images, so there is no image button');
        $this->assertStringContainsString('data-md="italic"', $html);
        $this->assertStringContainsString('data-role="author-visibility"', $html);
        $this->assertMatchesRegularExpression('#<details class="ae-more" data-role="author-visibility"\s*>#', $html, 'author and visibility start collapsed');
    }

    public function test_the_status_pill_follows_a_real_save_and_an_ordinary_draft_has_no_overlap_warning(): void
    {
        $t = $this->owner();

        $this->get($this->url($t, 'articles.create'))->assertOk()->assertSee('New draft · not saved yet');

        $this->post($this->url($t, 'articles.store'), $this->formFor())->assertRedirect();
        $article = WebsiteArticle::query()->where('business_id', $t[1]->id)->sole();

        $html = $this->get($this->url($t, 'articles.edit', [$article->uid]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-role="save-status" data-state="draft"[^>]*>Draft · saved just now<#', $html);
        $this->assertStringNotContainsString('data-role="overlap-warning"', $html);
        $this->assertStringNotContainsString('data-role="ai-notice"', $html, 'a hand-written draft has no AI notice');
        $this->assertStringNotContainsString('data-requires-ack="#f-ack"', $html);

        // A refused save is shown as not saved, never as saved.
        $edit = $this->url($t, 'articles.edit', [$article->uid]);
        $this->from($edit)->post($this->url($t, 'articles.update', [$article->uid]), $this->formFor(['title' => '']))->assertRedirect($edit)->assertSessionHasErrors('title');
        $refused = $this->get($edit)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-role="save-status" data-state="error"[^>]*>Not saved<#', $refused);
        $this->assertStringContainsString('data-role="editor-errors"', $refused);
    }

    public function test_an_error_that_is_not_a_refused_save_does_not_claim_the_draft_is_not_saved(): void
    {
        $t = $this->owner();
        $article = $this->draftArticle($t[1]);

        // e.g. "Track this topic" without a topic redirects here with an error flash and no edited input.
        $html = $this->withSession(['status' => 'error', 'message' => 'Add a target topic to this article first, then you can track it.'])
            ->get($this->url($t, 'articles.edit', [$article->uid]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Not saved', $html);
        $this->assertMatchesRegularExpression('#data-role="save-status" data-state="draft"[^>]*>Draft · saved#', $html);
    }

    public function test_the_permalink_and_search_preview_use_the_canonical_custom_domain(): void
    {
        $t = $this->owner();
        $article = $this->draftArticle($t[1]);

        $html = $this->get($this->url($t, 'articles.edit', [$article->uid]))->assertOk()->getContent();

        $this->assertStringContainsString(self::DOMAIN . '/blog/', $html);
        $this->assertStringNotContainsString('/sites/', $html, 'the noindex platform path is never shown as the address');
    }

    public function test_title_body_and_search_description_persist_across_save_and_reload(): void
    {
        $t = $this->owner();
        $article = $this->draftArticle($t[1]);
        $body = "## What is included?\n\nEvery rental comes with setup, props and instant prints.\n\n" . $this->goodBody(self::PAGE_WEDDING);

        $this->post($this->url($t, 'articles.update', [$article->uid]), $this->formFor([
            'title' => 'What is included in a photo booth rental package?',
            'body' => $body,
            'meta_description' => 'Discover what is included in a photo booth rental package for your event.',
            'seo_title' => "What's Included in a Photo Booth Rental Package?",
        ]))->assertRedirect($this->url($t, 'articles.edit', [$article->uid]));

        $html = $this->get($this->url($t, 'articles.edit', [$article->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('What is included in a photo booth rental package?</textarea>', $html);
        $this->assertStringContainsString('Every rental comes with setup, props and instant prints.', $html);
        $this->assertStringContainsString('Discover what is included in a photo booth rental package for your event.</textarea>', $html);
        $this->assertStringContainsString('value="What&#039;s Included in a Photo Booth Rental Package?"', $html);

        $fresh = $article->fresh();
        $this->assertSame('What is included in a photo booth rental package?', $fresh->title);
        $this->assertSame('Discover what is included in a photo booth rental package for your event.', $fresh->meta_description);
    }

    public function test_the_ai_notice_shows_only_for_an_ai_draft_and_the_overlap_warning_only_when_one_is_detected(): void
    {
        $t = $this->owner();
        $first = $this->draftArticle($t[1], ['title' => 'Wedding photo booth ideas', 'primary_topic' => 'wedding photo booth ideas']);
        $second = $this->draftArticle($t[1], ['title' => 'Wedding Photo Booth Ideas', 'primary_topic' => 'wedding photo booth ideas']);
        $second->forceFill(['ai_generated' => true])->save();

        $html = $this->get($this->url($t, 'articles.edit', [$second->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="ai-notice"', $html);
        $this->assertStringContainsString('Written by AI from your services and prices', $html);
        $this->assertStringContainsString('data-role="overlap-warning"', $html);
        $this->assertStringContainsString('Overlaps an existing article', $html);
        $this->assertStringContainsString($this->url($t, 'articles.edit', [$first->uid]), $html, 'the warning links to the article it overlaps');
        $this->assertStringContainsString('name="acknowledge_overlap"', $html);
        $this->assertStringContainsString('data-requires-ack="#f-ack"', $html);

        // Publishing without the override is still refused; with it, the existing publish rules apply.
        $this->post($this->url($t, 'articles.publish', [$second->uid]), $this->formFor(['title' => 'Wedding Photo Booth Ideas', 'primary_topic' => 'wedding photo booth ideas']))->assertRedirect();
        $this->assertSame('draft', $second->fresh()->status->value);

        $this->post($this->url($t, 'articles.publish', [$second->uid]), $this->formFor(['title' => 'Wedding Photo Booth Ideas', 'primary_topic' => 'wedding photo booth ideas']) + ['acknowledge_overlap' => '1'])->assertRedirect();
        $this->assertSame('published', $second->fresh()->status->value);
    }

    public function test_featured_image_selection_persists_and_internal_link_references_are_offered(): void
    {
        $t = $this->owner();
        $article = $this->draftArticle($t[1]);
        $asset = WebsiteAsset::create([
            'website_id' => $t[3]->id, 'path' => 'images/websites/x/booth.jpg', 'filename' => 'booth.jpg', 'mime_type' => 'image/jpeg',
            'size_bytes' => 10, 'purpose' => 'gallery', 'alt_text' => 'A photo booth at a wedding',
        ]);

        $before = $this->get($this->url($t, 'articles.edit', [$article->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="image-needed"', $before);
        $this->assertStringContainsString('value="' . $asset->uid . '"', $before);

        $this->post($this->url($t, 'articles.update', [$article->uid]), $this->formFor(['featured_asset_uid' => $asset->uid]))->assertRedirect();

        $after = $this->get($this->url($t, 'articles.edit', [$article->uid]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#value="' . preg_quote($asset->uid, '#') . '" form="article-form" checked#', $after);
        $this->assertStringNotContainsString('data-role="image-needed"', $after);
        $this->assertStringContainsString('data-insert="[', $after);
        $this->assertStringContainsString('page:' . self::PAGE_PACKAGES, $after);
    }
}
