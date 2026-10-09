<?php

namespace Tests\Feature\Seo\Content\Autopilot;

use App\Library\NicheBlueprint\Adapters\SeoStrategyComponentAdapter;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Library\Seo\Content\Autopilot\ContentPolicy;
use App\Models\BusinessKnowledgeProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Content Autopilot, Slice 3 - the Niche Blueprint configures ONE universal engine: risk tier, automatic-publishing
 * eligibility, prohibited phrases, preferred terms, and per-topic seasonality / journey stage. Unknown => fail closed.
 */
class BlueprintContentPolicyTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private const TOPIC = ['title' => 'How much does a booth cost in {city}?', 'intent' => 'cost', 'per' => 'city'];

    private function adapter(): SeoStrategyComponentAdapter
    {
        return new SeoStrategyComponentAdapter();
    }

    private function validatePolicy(mixed $policy): void
    {
        $this->adapter()->validateDescriptor(['content_topics' => [self::TOPIC], 'content_policy' => $policy]);
    }

    public function test_a_valid_policy_is_accepted_and_a_missing_one_is_optional(): void
    {
        $this->validatePolicy(['risk_tier' => 'standard', 'auto_publish' => 'allowed', 'prohibited_phrases' => ['guaranteed results'], 'preferred_terms' => ['photo booth']]);
        $this->validatePolicy(['risk_tier' => 'regulated', 'auto_publish' => 'never']);
        $this->validatePolicy(null);
        $this->addToAssertionCount(1);
    }

    public function test_an_invalid_policy_is_refused(): void
    {
        $bad = [
            'unknown tier' => ['risk_tier' => 'wild', 'auto_publish' => 'allowed'],
            'no tier' => ['auto_publish' => 'allowed'],
            'unknown auto_publish' => ['risk_tier' => 'standard', 'auto_publish' => 'yolo'],
            'sensitive cannot auto-publish' => ['risk_tier' => 'sensitive', 'auto_publish' => 'allowed'],
            'regulated cannot auto-publish' => ['risk_tier' => 'regulated', 'auto_publish' => 'allowed'],
            'a list, not an object' => ['standard'],
            'phrase not text' => ['risk_tier' => 'standard', 'prohibited_phrases' => [5]],
        ];

        foreach ($bad as $label => $policy) {
            try {
                $this->validatePolicy($policy);
                $this->fail("accepted: {$label}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_topic_seasonality_and_stage_are_validated_and_only_present_when_set(): void
    {
        $plain = SeoStrategyComponentAdapter::contentTopics(['content_topics' => [self::TOPIC]]);
        $this->assertSame(['title', 'intent', 'per', 'supports', 'cluster', 'why'], array_keys($plain[0]), 'existing topics keep their exact shape');

        $seasonal = SeoStrategyComponentAdapter::contentTopics(['content_topics' => [self::TOPIC + ['months' => '5,3,3,4', 'stage' => 'Consideration']]]);
        $this->assertSame([3, 4, 5], $seasonal[0]['months']);
        $this->assertSame('consideration', $seasonal[0]['stage']);

        foreach ([['months' => [13]], ['months' => [0]], ['months' => ['spring']], ['stage' => 'purchase']] as $extra) {
            $this->assertSame([], SeoStrategyComponentAdapter::contentTopics(['content_topics' => [self::TOPIC + $extra]]), 'a malformed topic is skipped by the tolerant read');

            try {
                $this->adapter()->validateDescriptor(['content_topics' => [self::TOPIC + $extra]]);
                $this->fail('validation accepted ' . json_encode($extra));
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_form_round_trips_the_new_fields_and_leaves_old_topics_untouched(): void
    {
        $adapter = $this->adapter();
        $input = [
            'content_topics' => "How much does a booth cost in {city}? | cost | city | packages | Pricing | People ask first.\nWedding photo booth ideas | ideas | none | | Weddings | Why | 4,5,6 | awareness",
            'content_risk_tier' => 'standard',
            'content_auto_publish' => 'allowed',
            'content_prohibited_phrases' => "guaranteed results\nbest in the world",
            'content_preferred_terms' => 'photo booth',
        ];

        $payload = $adapter->payloadFromInput($input);
        $this->assertSame(['risk_tier' => 'standard', 'auto_publish' => 'allowed', 'prohibited_phrases' => ['guaranteed results', 'best in the world'], 'preferred_terms' => ['photo booth']], $payload['content_policy']);
        $this->assertArrayNotHasKey('months', $payload['content_topics'][0]);
        $this->assertSame('4,5,6', $payload['content_topics'][1]['months']);

        $back = $adapter->inputFromPayload($payload);
        $lines = explode("\n", $back['content_topics']);
        $this->assertSame('How much does a booth cost in {city}? | cost | city | packages | Pricing | People ask first.', $lines[0], 'a topic without the new parts round-trips byte for byte');
        $this->assertStringEndsWith('| 4,5,6 | awareness', $lines[1]);
        $this->assertSame('standard', $back['content_risk_tier']);
        $this->assertSame("guaranteed results\nbest in the world", $back['content_prohibited_phrases']);

        $names = array_column($adapter->formFields(), 'name');
        foreach (['content_risk_tier', 'content_auto_publish', 'content_prohibited_phrases', 'content_preferred_terms'] as $field) {
            $this->assertContains($field, $names);
        }

        // Naming phrases without a tier must not quietly become "standard".
        $cautious = $adapter->payloadFromInput(['content_topics' => $input['content_topics'], 'content_prohibited_phrases' => 'cure']);
        $this->assertSame('sensitive', $cautious['content_policy']['risk_tier']);
        $this->assertSame('approval_required', $cautious['content_policy']['auto_publish']);
    }

    public function test_unknown_or_unreadable_policy_fails_closed(): void
    {
        $closed = ['risk_tier' => 'unspecified', 'auto_publish' => 'approval_required', 'prohibited_phrases' => [], 'preferred_terms' => []];

        $this->assertSame($closed, SeoStrategyComponentAdapter::contentPolicy(null));
        $this->assertSame($closed, SeoStrategyComponentAdapter::contentPolicy(['content_topics' => []]));
        $this->assertSame($closed, SeoStrategyComponentAdapter::contentPolicy(['content_policy' => 'standard']));
        $this->assertSame($closed, SeoStrategyComponentAdapter::contentPolicy(['content_policy' => ['risk_tier' => 'regulated', 'auto_publish' => 'allowed']]), 'an invalid stored policy never reads as permissive');
        $this->assertSame('standard', SeoStrategyComponentAdapter::contentPolicy(['content_policy' => ['risk_tier' => 'standard', 'auto_publish' => 'allowed']])['risk_tier']);
    }

    public function test_only_an_explicit_standard_allowed_niche_is_auto_publish_eligible(): void
    {
        [, $business] = $this->photoBoothContentTenant(withBlueprint: true);

        // The shipped Photo Booth Blueprint declares no content policy yet: fail closed until a platform owner publishes one.
        $this->assertFalse(app(ContentPolicy::class)->autoPublishAllowed($business));
        $this->assertSame('unspecified', app(ContentPolicy::class)->forBusiness($business)['risk_tier']);

        foreach ([
            [['risk_tier' => 'standard', 'auto_publish' => 'allowed'], true],
            [['risk_tier' => 'standard', 'auto_publish' => 'approval_required'], false],
            [['risk_tier' => 'sensitive', 'auto_publish' => 'approval_required'], false],
            [['risk_tier' => 'standard', 'auto_publish' => 'never'], false],
        ] as [$policy, $expected]) {
            $reader = $this->partialMock(BlueprintConfigReader::class, fn ($mock) => $mock->shouldReceive('seoStrategy')->andReturn(['content_policy' => $policy]));
            $this->app->instance(BlueprintConfigReader::class, $reader);

            $this->assertSame($expected, app(ContentPolicy::class)->autoPublishAllowed($business), json_encode($policy));
        }
    }

    public function test_prohibited_phrases_merge_the_niche_and_the_owner_without_duplicates(): void
    {
        [$customer, $business] = $this->photoBoothContentTenant(withBlueprint: true);
        app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->updateFields($business, ['prohibited_claims' => ['Cheapest in Chicago', 'guaranteed results']], 'manual_edit', (int) $customer->user_id, true);

        $reader = $this->partialMock(BlueprintConfigReader::class, fn ($mock) => $mock->shouldReceive('seoStrategy')->andReturn(['content_policy' => ['risk_tier' => 'standard', 'prohibited_phrases' => ['Guaranteed Results', 'miracle']]]));
        $this->app->instance(BlueprintConfigReader::class, $reader);

        $this->assertSame(['Guaranteed Results', 'miracle', 'Cheapest in Chicago'], app(ContentPolicy::class)->prohibitedPhrases($business));
    }

    public function test_seasonality_opens_a_lead_window_and_wraps_the_year_end(): void
    {
        $policy = app(ContentPolicy::class);

        $this->assertTrue($policy->inSeason([], 7), 'no months means evergreen');
        $this->assertTrue($policy->inSeason([5, 6], 5));
        $this->assertTrue($policy->inSeason([5, 6], 3), 'two months ahead');
        $this->assertFalse($policy->inSeason([5, 6], 2));
        $this->assertFalse($policy->inSeason([5, 6], 8), 'already past');
        $this->assertTrue($policy->inSeason([1], 12), 'wraps December to January');
        $this->assertTrue($policy->inSeason([2], 12), 'wraps with the lead window');
        $this->assertFalse($policy->inSeason([3], 12));
    }
}
