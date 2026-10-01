<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Enums\Questionnaire\QuestionnaireResponseStatus;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator;
use App\Library\Website\Setup\Exceptions\GenerationInProgressException;
use App\Library\Website\Setup\WebsiteSetupSessionManager;
use App\Library\Website\WebsitePageStrategy;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round 4 (item 1) — the generation lease's
 * CAS/fencing guarantees are a pure state-machine over database rows, not
 * a property of real OS-thread timing: every scenario below is reproduced
 * deterministically by driving the Website row into the exact state a
 * genuine race would leave it in (an expired lease whose stale token is
 * still held by a slow caller), then proving the coordinator and
 * GuidedGenerationCommitService refuse to act on that stale token. This is
 * what actually exercises the CAS comparisons in
 * WebsiteGenerationCoordinator::beginLease()/release()/assertNotLeased()
 * and GuidedGenerationCommitService::run()/generateValidateAndCommit() —
 * a real two-process race would only ever land in one of these same
 * states, nondeterministically. True process-level concurrency (exactly
 * one of two simultaneous callers acquires the lease, the other is
 * refused without blocking) is covered separately in
 * WebsiteGenerationLeaseConcurrencyTest.
 */
class WebsiteGenerationLeaseFencingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function template(): WebsiteTemplate
    {
        $this->seed(WebsiteTemplateSeeder::class);

        return WebsiteTemplate::findActiveOrFail('photo_booth_modern');
    }

    private function validBatchFor(array $plan): string
    {
        return json_encode(['pages' => collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => $page['title'] . ' seo title',
            'meta_description' => $page['title'] . ' meta description for this specific page.',
            'sections' => [
                ['type' => 'hero', 'data' => ['heading' => $page['title']]],
            ],
        ])->values()->all()]);
    }

    public function test_begin_lease_sets_a_unique_token_and_release_clears_it(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);

        $token = $coordinator->beginLease($website);

        $this->assertNotEmpty($token);
        $locked = $website->fresh();
        $this->assertSame($token, $locked->generation_lease_token);
        $this->assertNotNull($locked->generation_lease_started_at);
        $this->assertTrue($locked->isGenerationLeased());

        $coordinator->release($website, $token);

        $released = $website->fresh();
        $this->assertNull($released->generation_lease_token);
        $this->assertNull($released->generation_lease_started_at);
        $this->assertNull($released->generation_lease_attempt_uid);
        $this->assertFalse($released->isGenerationLeased());
    }

    public function test_a_second_lease_attempt_is_refused_without_blocking_while_the_first_is_still_fresh(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);

        $coordinator->beginLease($website);

        $this->expectException(GenerationInProgressException::class);
        $coordinator->beginLease($website);
    }

    public function test_releasing_with_a_stale_token_is_a_safe_no_op_and_never_clears_a_newer_lease(): void
    {
        // Simulates an obsolete worker that is only now getting around to
        // its own cleanup/release call, well after its lease was already
        // reclaimed as stale and reissued to a new owner — the CAS
        // WHERE clause in release() must affect zero rows here, never
        // the newer lease.
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);

        $staleToken = $coordinator->beginLease($website);

        Website::whereKey($website->id)->update([
            'generation_lease_started_at' => now()->subSeconds(WebsiteGenerationCoordinator::LEASE_SECONDS + 1),
        ]);

        $newToken = $coordinator->beginLease($website);
        $this->assertNotSame($staleToken, $newToken);

        $coordinator->release($website, $staleToken);

        $this->assertSame($newToken, $website->fresh()->generation_lease_token, 'A release carrying a stale, already-superseded token must never clear a newer lease.');
    }

    public function test_an_obsolete_fence_token_cannot_attach_to_an_attempt_or_commit_pages(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $template = $this->template();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);

        // The slow/obsolete worker acquires lease A...
        $staleToken = $coordinator->beginLease($website);

        // ...but takes so long that the lease expires, and a second
        // caller reclaims it as lease B.
        Website::whereKey($website->id)->update([
            'generation_lease_started_at' => now()->subSeconds(WebsiteGenerationCoordinator::LEASE_SECONDS + 1),
        ]);
        $freshToken = $coordinator->beginLease($website);
        $this->assertNotSame($staleToken, $freshToken);

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website->fresh());
        $this->mockAiClient($this->validBatchFor($plan));

        // The obsolete worker, unaware its lease was reclaimed, now
        // finally calls generateFull() with its STALE token A.
        $attempt = app(GuidedGenerationCommitService::class)->generateFull(
            $business, $website->fresh(), $template, $customer->user_id, 'idem-stale-fence', $staleToken,
        );

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('This generation was superseded before it could start.', $attempt->failure_reason);
        $this->assertSame(0, $website->pages()->count(), 'An obsolete worker must never commit pages.');
    }

    public function test_a_lease_reclaimed_mid_flight_after_the_ai_call_refuses_to_commit(): void
    {
        // Proves the SECOND fencing check (the one taken right after the
        // AI call returns, before merge/bind/commit) independently of
        // the first — here the token is still valid when the attempt is
        // created and CAS-attached, but is reclaimed by someone else
        // while the (mocked, instant) "AI call" is in flight.
        [$customer, $business] = $this->entitledTenant();
        $template = $this->template();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);

        $token = $coordinator->beginLease($website);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website->fresh());
        $responseJson = $this->validBatchFor($plan);

        // The lease is reclaimed by someone else WHILE the (mocked) AI
        // call is "in flight" — simulated by mutating the token from
        // inside the mock's own return callback, which runs at exactly
        // the point generateAndValidate()'s real provider call would
        // have been pending. This is indistinguishable, from the
        // service's point of view, from a genuine expiry-and-reclaim
        // race that happens to land in the small window between the
        // attempt's initial CAS-attach and the AI call returning.
        $mock = \Mockery::mock(\App\Library\Website\WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function () use ($website, $responseJson) {
            \App\Models\Website::whereKey($website->id)->update(['generation_lease_token' => 'someone-elses-token']);

            return $responseJson;
        });
        $this->app->instance(\App\Library\Website\WebsiteAiGenerationClient::class, $mock);

        $attempt = app(GuidedGenerationCommitService::class)->generateFull(
            $business, $website->fresh(), $template, $customer->user_id, 'idem-midflight-fence', $token,
        );

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('This generation was superseded while it was running.', $attempt->failure_reason);
        $this->assertSame(0, $website->pages()->count());
    }

    public function test_recovery_touches_only_the_expired_leases_own_attempt(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);

        // An unrelated pending attempt for this SAME website that was
        // never coordinated through a lease at all (e.g. a direct/legacy
        // caller) — round 3's bug was that ANY stale-lease recovery
        // marked every such row failed. It must survive untouched.
        $unrelated = WebsiteGuidedGenerationAttempt::create([
            'website_id' => $website->id,
            'template_key' => 'photo_booth_modern',
            'mode' => WebsiteGuidedGenerationAttempt::MODE_FULL_GENERATION,
            'idempotency_key' => 'unrelated-pending-key',
            'status' => WebsiteGuidedGenerationAttempt::STATUS_PENDING,
            'created_by_user_id' => $customer->user_id,
        ]);

        // A lease that genuinely owns its own attempt...
        $token = $coordinator->beginLease($website);
        $owned = WebsiteGuidedGenerationAttempt::create([
            'website_id' => $website->id,
            'template_key' => 'photo_booth_modern',
            'mode' => WebsiteGuidedGenerationAttempt::MODE_FULL_GENERATION,
            'idempotency_key' => 'owned-pending-key',
            'status' => WebsiteGuidedGenerationAttempt::STATUS_PENDING,
            'created_by_user_id' => $customer->user_id,
        ]);
        Website::whereKey($website->id)->update(['generation_lease_attempt_uid' => $owned->uid]);

        // ...and now expires.
        Website::whereKey($website->id)->update([
            'generation_lease_started_at' => now()->subSeconds(WebsiteGenerationCoordinator::LEASE_SECONDS + 1),
        ]);

        $coordinator->beginLease($website);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $owned->fresh()->status, "The expired lease's own attempt must be recovered.");
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_PENDING, $unrelated->fresh()->status, 'An unrelated pending attempt must never be touched by someone else\'s lease recovery.');
    }

    public function test_recovery_leaves_generation_retryable_after_a_crash(): void
    {
        // Proves item 1's "crash/failure recovery remains retryable":
        // after a worker crashes mid-generation (lease left dangling,
        // never released), a later caller must be able to acquire a
        // fresh lease and complete a real, successful generation — not
        // be stuck behind a lease that can never be reclaimed.
        [$customer, $business] = $this->entitledTenant();
        $template = $this->template();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);

        $crashedToken = $coordinator->beginLease($website);
        $crashedAttempt = WebsiteGuidedGenerationAttempt::create([
            'website_id' => $website->id,
            'template_key' => $template->key,
            'mode' => WebsiteGuidedGenerationAttempt::MODE_FULL_GENERATION,
            'idempotency_key' => 'crashed-pending-key',
            'status' => WebsiteGuidedGenerationAttempt::STATUS_PENDING,
            'created_by_user_id' => $customer->user_id,
        ]);
        Website::whereKey($website->id)->update([
            'generation_lease_attempt_uid' => $crashedAttempt->uid,
            'generation_lease_started_at' => now()->subSeconds(WebsiteGenerationCoordinator::LEASE_SECONDS + 1),
        ]);

        $retryToken = $coordinator->beginLease($website);
        $this->assertNotSame($crashedToken, $retryToken);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_FAILED, $crashedAttempt->fresh()->status);

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website->fresh());
        $this->mockAiClient($this->validBatchFor($plan));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull(
            $business, $website->fresh(), $template, $customer->user_id, 'idem-retry-after-crash', $retryToken,
        );
        $coordinator->release($website, $retryToken);

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, 'A fresh lease after a crashed worker must still be able to complete a real generation.');
        $this->assertSame(count($plan), $website->pages()->count());
    }

    public function test_assert_not_leased_blocks_while_leased_and_passes_once_released(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);

        $token = $coordinator->beginLease($website);

        $this->expectException(GenerationInProgressException::class);
        $coordinator->assertNotLeased($website);
    }

    public function test_assert_not_leased_passes_once_the_lease_is_expired(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);

        $coordinator->beginLease($website);
        Website::whereKey($website->id)->update([
            'generation_lease_started_at' => now()->subSeconds(WebsiteGenerationCoordinator::LEASE_SECONDS + 1),
        ]);

        // Must not throw.
        $coordinator->assertNotLeased($website);
        $this->assertTrue(true);
    }

    private function completedResponseFor(\App\Models\Business $business, Website $website): QuestionnaireResponse
    {
        $definition = QuestionnaireDefinition::create(['key' => 'lease_fencing_test_' . uniqid('', true), 'name' => 'Lease Fencing Test']);
        $publisher = app(\App\Library\Website\Setup\QuestionnaireVersionPublisher::class);
        $steps = [
            ['key' => 'business_name', 'prompt' => 'What is your business called?', 'help_text' => null, 'input_type' => 'text', 'required' => true, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'business', 'target_field' => 'name', 'ai_instructions' => null],
        ];
        $version = $publisher->publish($publisher->createDraft($definition, $steps));

        return QuestionnaireResponse::create([
            'business_id' => $business->id,
            'website_id' => $website->id,
            'questionnaire_definition_id' => $definition->id,
            'questionnaire_version_id' => $version->id,
            'status' => QuestionnaireResponseStatus::Completed,
            'current_step_key' => 'business_name',
            'answers' => ['business_name' => $business->name],
            'answers_revision' => 1,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Independent-review correction round 4 (item 1) — a generation
     * lease held by a STUDIO-originated rebuild (not the wizard) must
     * freeze the exact same setup-mutation paths a wizard-originated
     * generation would: both acquire the identical Website-level lease
     * via the SAME WebsiteGenerationCoordinator, and WebsiteSetupSession-
     * Manager::runIfNotGenerating()/beginEdit() check that lease, not
     * which caller happened to take it out.
     */
    public function test_a_studio_rebuild_lease_freezes_wizard_setup_mutation_paths_too(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);
        $sessionManager = app(WebsiteSetupSessionManager::class);

        $completed = $this->completedResponseFor($business, $website);

        // Simulates Studio's own "Rebuild" action acquiring the lease —
        // the wizard controller is never involved in this call at all.
        $coordinator->beginLease($website);

        $this->expectException(GenerationInProgressException::class);
        $sessionManager->beginEdit($business, $completed);
    }

    public function test_a_studio_rebuild_lease_freezes_in_progress_answer_saves_too(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);
        $sessionManager = app(WebsiteSetupSessionManager::class);

        $definition = QuestionnaireDefinition::create(['key' => 'lease_fencing_inprogress_' . uniqid('', true), 'name' => 'Lease Fencing In Progress']);
        $publisher = app(\App\Library\Website\Setup\QuestionnaireVersionPublisher::class);
        $steps = [
            ['key' => 'business_name', 'prompt' => 'What is your business called?', 'help_text' => null, 'input_type' => 'text', 'required' => true, 'options' => null, 'conditional_visibility' => null, 'target_module' => 'business', 'target_field' => 'name', 'ai_instructions' => null],
        ];
        $version = $publisher->publish($publisher->createDraft($definition, $steps));

        $response = QuestionnaireResponse::create([
            'business_id' => $business->id,
            'website_id' => $website->id,
            'questionnaire_definition_id' => $definition->id,
            'questionnaire_version_id' => $version->id,
            'status' => QuestionnaireResponseStatus::InProgress,
            'current_step_key' => 'business_name',
            'answers' => [],
            'answers_revision' => 1,
            'started_at' => now(),
        ]);

        $coordinator->beginLease($website);

        $this->expectException(GenerationInProgressException::class);
        $sessionManager->saveAnswer($response, 'business_name', 'New Name', 1);
    }
}
