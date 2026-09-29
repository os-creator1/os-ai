<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §8.2/§8.4, completed by this lane.
 * Every test binds a Mockery double for WebsiteAiGenerationClient
 * (CreatesWebsiteFixtures::mockAiClient()) — no live provider call is
 * ever attempted.
 */
class GuidedGenerationCommitServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function validBatch(): string
    {
        return json_encode(['pages' => [
            [
                'page_type' => 'home',
                'title' => 'Home',
                'slug' => null,
                'sections' => [
                    ['type' => 'hero', 'data' => ['heading' => 'Welcome']],
                ],
            ],
        ]]);
    }

    public function test_a_valid_generation_commits_pages_and_marks_the_attempt_succeeded(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $this->mockAiClient($this->validBatch());

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-1');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame(1, $website->pages()->count());
        $this->assertTrue($website->pages()->where('is_home', true)->exists());
    }

    public function test_an_invalid_batch_retries_once_then_fails_with_zero_pages_created(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);

        // Always malformed — every attempt (initial + the one bounded
        // retry) fails the same way.
        $this->mockAiClient(json_encode(['pages' => [
            ['page_type' => 'not_a_real_page_type', 'title' => 'X', 'slug' => null, 'sections' => []],
        ]]));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-2');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(1, $attempt->retry_count);
        $this->assertSame(0, $website->pages()->count());
    }

    public function test_a_prohibited_claim_in_generated_copy_fails_the_batch(): void
    {
        [$customer, $business] = $this->entitledTenant();
        app(BusinessKnowledgeProfileManager::class)->updateFields($business, [
            'prohibited_claims' => ['guaranteed lowest price'],
        ], 'manual_edit', $customer->user_id, markVerified: true);

        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);

        $this->mockAiClient(json_encode(['pages' => [
            ['page_type' => 'home', 'title' => 'Home', 'slug' => null, 'sections' => [
                ['type' => 'hero', 'data' => ['heading' => 'Guaranteed lowest price in town!']],
            ]],
        ]]));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-3');

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame(0, $website->pages()->count());
    }

    public function test_a_repeated_idempotency_key_short_circuits_to_the_existing_attempt(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $mock = $this->mockAiClient($this->validBatch());

        $service = app(GuidedGenerationCommitService::class);
        $first = $service->generateFull($business, $website, $template, $customer->user_id, 'idem-4');
        $second = $service->generateFull($business, $website, $template, $customer->user_id, 'idem-4');

        $this->assertSame($first->id, $second->id);
        $mock->shouldHaveReceived('complete')->once();
    }
}
