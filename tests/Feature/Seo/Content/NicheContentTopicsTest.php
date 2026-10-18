<?php

namespace Tests\Feature\Seo\Content;

use App\Library\NicheBlueprint\Adapters\SeoStrategyComponentAdapter;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO Content Engine V1 — Niche Blueprints define article topic PATTERNS (never mass pages): validated at publish,
 * read through the one BlueprintConfigReader seam, and shipped for the Photo Booth niche.
 */
class NicheContentTopicsTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private function validate(array $topics): void
    {
        (new SeoStrategyComponentAdapter())->validateDescriptor(['content_topics' => $topics]);
    }

    public function test_a_valid_topic_pattern_is_accepted(): void
    {
        $this->validate([
            ['title' => 'How much does a booth cost in {city}?', 'intent' => 'cost', 'per' => 'city', 'supports' => 'packages', 'cluster' => 'Pricing', 'why' => 'People ask first.'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_malformed_or_money_page_style_topics_are_refused(): void
    {
        $bad = [
            'plain service search (no informational word)' => [['title' => 'Photo booth rental in {city}', 'intent' => 'guide', 'per' => 'city']],
            'unknown placeholder' => [['title' => 'How much does {price} cost?', 'intent' => 'cost']],
            'unknown intent' => [['title' => 'How much does it cost?', 'intent' => 'buy_now']],
            'per city without {city}' => [['title' => 'How much does it cost?', 'intent' => 'cost', 'per' => 'city']],
            'unknown scope' => [['title' => 'How much does it cost?', 'intent' => 'cost', 'per' => 'galaxy']],
            'not an object' => ['just a string'],
        ];

        foreach ($bad as $label => $topics) {
            try {
                $this->validate($topics);
                $this->fail("accepted: {$label}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_photo_booth_blueprint_ships_a_bounded_set_of_patterns_for_an_installed_business(): void
    {
        [, $business] = $this->photoBoothContentTenant(withBlueprint: true);

        $strategy = app(BlueprintConfigReader::class)->seoStrategy($business);
        $topics = SeoStrategyComponentAdapter::contentTopics($strategy);

        $this->assertGreaterThanOrEqual(12, count($topics));
        $this->assertLessThanOrEqual(30, count($topics), 'patterns, not hundreds of permutations');

        $titles = implode(' | ', array_column($topics, 'title'));
        foreach (['cost', 'Wedding photo booth ideas', 'corporate', '360 photo booth vs traditional', 'room', 'backdrop', 'guestbook', 'Prints vs digital', 'venue'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $titles);
        }
    }

    public function test_a_business_without_the_blueprint_gets_no_niche_topics(): void
    {
        [, $business] = $this->photoBoothContentTenant();

        $strategy = app(BlueprintConfigReader::class)->seoStrategy($business);

        $this->assertSame([], SeoStrategyComponentAdapter::contentTopics($strategy ?? []));
    }
}
