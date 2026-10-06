<?php

namespace Tests\Feature\Seo\Content;

use App\Library\Seo\Content\ArticleCannibalizationGuard;
use App\Library\Seo\Content\ArticleTopicSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO Content Engine V1 — deterministic topic signatures, money-page cannibalization and duplicate-topic
 * detection. Nothing here involves an AI.
 */
class ArticleCannibalizationGuardTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    public function test_signatures_split_the_subject_from_the_intent_modifiers(): void
    {
        $cost = ArticleTopicSignature::of('How much does a photo booth rental cost in Chicago?');
        $this->assertSame(['booth', 'chicago', 'photo', 'rental'], $cost['core']);
        $this->assertSame(['cost', 'how', 'much'], $cost['modifiers']);

        $plain = ArticleTopicSignature::of('Photo Booth Rental Chicago');
        $this->assertSame($cost['core'], $plain['core']);
        $this->assertSame([], $plain['modifiers']);

        $this->assertSame(ArticleTopicSignature::of('Photo booths')['core'], ArticleTopicSignature::of('photo booth')['core'], 'plurals fold');
        $this->assertSame(ArticleTopicSignature::key('360 photo booth VS traditional photo booth'), ArticleTopicSignature::key('360  Photo Booth vs. Traditional Photo Booth'), 'case, spacing and punctuation never matter');
    }

    public function test_the_exact_money_page_search_is_flagged_as_cannibalization(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $guard = app(ArticleCannibalizationGuard::class);

        foreach (['photo booth rental chicago', 'Wedding Photo Booth Rental', 'Chicago photo booth rental', '360 photo booth rental'] as $phrase) {
            $result = $guard->check($business, [$phrase]);
            $this->assertTrue($result->conflictsWithPage(), $phrase);
            $this->assertTrue($result->strong(), $phrase);
        }
    }

    public function test_informational_topics_support_the_page_instead_of_competing(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $guard = app(ArticleCannibalizationGuard::class);

        foreach ([
            'How much does a photo booth rental cost in Chicago?',
            '360 photo booth vs traditional photo booth',
            'Wedding photo booth ideas',
            'How much room does a photo booth need?',
            'Are photo booths worth it for corporate events?',
        ] as $phrase) {
            $this->assertFalse($guard->check($business, [$phrase])->conflictsWithPage(), $phrase);
        }
    }

    public function test_a_duplicate_article_is_a_strong_overlap_and_a_different_angle_is_only_related(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $existing = $this->draftArticle($business, ['title' => 'Wedding photo booth ideas', 'primary_topic' => 'wedding photo booth ideas']);
        $guard = app(ArticleCannibalizationGuard::class);

        $duplicate = $guard->check($business, ['Wedding Photo Booth Ideas']);
        $this->assertTrue($duplicate->strong());
        $this->assertSame($existing->uid, $duplicate->strongFindings()[0]['uid']);

        $angle = $guard->check($business, ['How much does a wedding photo booth cost?']);
        $this->assertFalse($angle->strong());
        $this->assertSame([$existing->uid], array_column($angle->related(), 'uid'), 'same subject, different angle: a link candidate, not a warning');

        $this->assertSame([], $guard->check($business, ['How much does a corporate photo booth cost?'])->related(), 'a different subject is unrelated');

        $this->assertFalse($guard->check($business, ['Wedding photo booth ideas'], (int) $existing->id)->strong(), 'an article never overlaps itself');
    }

    public function test_archived_and_other_business_articles_are_ignored(): void
    {
        [, $a] = $this->photoBoothContentTenant();
        [, $b] = $this->photoBoothContentTenant();

        $this->draftArticle($b, ['title' => 'Wedding photo booth ideas', 'primary_topic' => 'wedding photo booth ideas']);
        $archived = $this->publishedArticle($a, ['title' => 'Backdrop ideas for parties', 'primary_topic' => 'backdrop ideas for parties']);
        $this->manager()->archive((int) $a->customer_id, $a, $archived);

        $guard = app(ArticleCannibalizationGuard::class);
        $this->assertFalse($guard->check($a, ['Wedding photo booth ideas'])->strong(), 'another Business never blocks this one');
        $this->assertFalse($guard->check($a, ['Backdrop ideas for parties'])->strong(), 'an archived article frees its topic');
    }

    public function test_publishing_a_strongly_overlapping_article_needs_an_explicit_acknowledgement(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $this->publishedArticle($business, ['title' => 'Wedding photo booth ideas', 'primary_topic' => 'wedding photo booth ideas']);
        $dupe = $this->draftArticle($business, ['title' => 'Wedding photo booth ideas', 'primary_topic' => 'wedding photo booth ideas', 'slug' => 'wedding-booth-ideas-two']);

        try {
            $this->manager()->publish((int) $business->customer_id, $business, $dupe);
            $this->fail('expected an overlap refusal');
        } catch (\App\Exceptions\Seo\ArticleException $e) {
            $this->assertSame(\App\Exceptions\Seo\ArticleException::OVERLAP_UNACKNOWLEDGED, $e->reason);
        }

        $this->assertTrue($this->manager()->publish((int) $business->customer_id, $business, $dupe, true)->isPublished());
    }

    public function test_a_money_page_topic_blocks_nothing_by_itself_but_the_warning_is_surfaced(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $article = $this->draftArticle($business, ['title' => 'Photo booth rental chicago', 'primary_topic' => 'photo booth rental chicago']);

        $analysis = app(\App\Library\Seo\Content\ArticleAnalyzer::class)->analyze($business, $article);
        $row = collect($analysis['checks'])->firstWhere('key', 'duplication');

        $this->assertSame('attention', $row['status']);
        $this->assertSame('attention', $analysis['overall']);
    }
}
