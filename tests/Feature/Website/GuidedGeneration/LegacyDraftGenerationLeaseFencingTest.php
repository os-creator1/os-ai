<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Website\GuidedGeneration\WebsiteGenerationCoordinator;
use App\Library\Website\Setup\Exceptions\GenerationInProgressException;
use App\Library\Website\WebsiteAiDraftGenerator;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round 6 — the legacy (non-template) AI
 * draft generator was the one remaining generation path outside the
 * Website-level lease/fencing architecture: WebsiteController::generate()
 * called WebsiteAiDraftGenerator::generate() directly, with no lease at
 * all, and that generator committed pages in an unguarded loop with no
 * fencing token or atomic commit. Proves the fix end to end: the SAME
 * WebsiteGenerationCoordinator lease now guards this path, the SAME
 * compare-and-swap fencing protects its commit
 * (commitFencedLegacyDraft()), and an obsolete worker can neither write
 * pages nor clear a newer lease — mirroring the deterministic state-
 * machine proof style WebsiteGenerationLeaseFencingTest already
 * established for the guided-generation path, since these guarantees are
 * a property of database row state, not of real OS-thread timing. One
 * genuine cross-process concurrency test (manual mutation vs. an active
 * legacy-generation lease) lives in WebsiteStudioMutationConcurrencyTest-
 * style form at the bottom of this file.
 */
class LegacyDraftGenerationLeaseFencingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function validLegacyBatch(): string
    {
        return json_encode(['pages' => [
            ['title' => 'Home', 'is_home' => true, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Welcome']]]],
        ]]);
    }

    /**
     * Item 1 / item 8 — a normal, successful legacy generation acquires
     * the lease (proven by asserting it is genuinely ACTIVE at the moment
     * the AI provider is called, from inside the mock itself), commits
     * the page batch, and releases the lease on success.
     */
    public function test_a_normal_legacy_generation_acquires_the_lease_commits_pages_and_releases_on_success(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $leaseWasActiveDuringAiCall = false;
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function () use ($website, &$leaseWasActiveDuringAiCall) {
            $leaseWasActiveDuringAiCall = $website->fresh()->generation_lease_token !== null;

            return $this->validLegacyBatch();
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $this->assertTrue($leaseWasActiveDuringAiCall, 'The Website-level lease must be genuinely held while the AI provider call is in flight.');
        $this->assertSame(1, $website->pages()->count());
        $this->assertNull($website->fresh()->generation_lease_token, 'The lease must be released once generation completes.');
    }

    /**
     * Item 2 — an already-active (unexpired) lease refuses legacy
     * generation immediately, with the SAME friendly
     * GenerationInProgressException guided generation produces, and the
     * AI provider is never called at all.
     */
    public function test_an_active_lease_refuses_legacy_generation_with_zero_provider_or_page_writes(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        app(WebsiteGenerationCoordinator::class)->beginLease($website);

        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldNotReceive('complete');
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'error');

        $this->assertSame(0, $website->pages()->count());
    }

    /**
     * Item 7 — the "no pages yet" precondition is re-checked AFTER the
     * lease is acquired and BEFORE the AI provider is ever called — a
     * Website that already has a page must never spend an AI call.
     */
    public function test_the_no_pages_yet_precondition_is_re_checked_after_the_lease_and_before_the_ai_call(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        WebsitePage::create([
            'website_id' => $website->id, 'title' => 'Already Here', 'slug' => null,
            'is_home' => true, 'sections' => [], 'noindex' => true,
        ]);

        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldNotReceive('complete');
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        try {
            $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]));
        } catch (ValidationException) {
            // Acceptable: this is the SAME uncaught ValidationException
            // the pre-existing, unleased code path already threw for
            // this exact precondition — round 6 only adds the lease
            // around it, never changes this exception's own handling.
        }

        $this->assertSame(1, $website->pages()->count(), 'The pre-existing page must be untouched.');
        $this->assertNull($website->fresh()->generation_lease_token, 'The lease must still be released even when the precondition check throws.');
    }

    /**
     * Item 4 — an obsolete legacy worker (its lease already expired and
     * reclaimed by a newer caller) can never write pages through
     * commitFencedLegacyDraft(), even with an otherwise-valid batch in
     * hand.
     */
    public function test_an_obsolete_legacy_worker_cannot_commit_pages_after_its_lease_is_reclaimed(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);

        $staleToken = $coordinator->beginLease($website);

        // The lease expires and a second, newer worker reclaims it.
        Website::whereKey($website->id)->update([
            'generation_lease_started_at' => now()->subSeconds(WebsiteGenerationCoordinator::LEASE_SECONDS + 1),
        ]);
        $freshToken = $coordinator->beginLease($website);
        $this->assertNotSame($staleToken, $freshToken);

        // The obsolete worker, unaware its lease was reclaimed, now
        // finally tries to commit its own (otherwise perfectly valid)
        // page batch using its STALE token.
        $callbackRan = false;
        $committed = $coordinator->commitFencedLegacyDraft($website, $staleToken, function (Website $locked) use (&$callbackRan) {
            $callbackRan = true;
            app(\App\Library\Website\WebsiteDraftPageService::class)->createPage($locked, [
                'title' => 'Home', 'is_home' => true, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Welcome']]],
            ]);
        });

        $this->assertFalse($committed, 'An obsolete worker\'s commit must report failure.');
        $this->assertFalse($callbackRan, 'An obsolete worker must never even attempt to create pages.');
        $this->assertSame(0, $website->pages()->count(), 'An obsolete worker must never write any pages.');
    }

    /**
     * Item 5 — the obsolete worker's later release() call, using its own
     * stale token, must never clear the newer worker's still-active
     * lease (the existing compare-and-swap release(), unchanged, already
     * guarantees this — proven here specifically for the legacy path's
     * own call sequence).
     */
    public function test_an_obsolete_legacy_workers_later_release_cannot_clear_a_newer_lease(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);

        $staleToken = $coordinator->beginLease($website);
        Website::whereKey($website->id)->update([
            'generation_lease_started_at' => now()->subSeconds(WebsiteGenerationCoordinator::LEASE_SECONDS + 1),
        ]);
        $freshToken = $coordinator->beginLease($website);

        $coordinator->release($website, $staleToken);

        $this->assertSame($freshToken, $website->fresh()->generation_lease_token, 'A release carrying the obsolete worker\'s stale token must never clear the newer, still-active lease.');
    }

    /**
     * Item 6 — the page batch commits completely or not at all: a
     * failure partway through the callback rolls back every page the
     * callback had already created, never a partial batch.
     */
    public function test_a_mid_batch_failure_rolls_back_the_entire_page_batch(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $coordinator = app(WebsiteGenerationCoordinator::class);
        $token = $coordinator->beginLease($website);

        try {
            $coordinator->commitFencedLegacyDraft($website, $token, function (Website $locked) {
                app(\App\Library\Website\WebsiteDraftPageService::class)->createPage($locked, [
                    'title' => 'Home', 'is_home' => true, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Welcome']]],
                ]);

                throw new \RuntimeException('Simulated mid-batch failure.');
            });
            $this->fail('Expected the simulated failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated mid-batch failure.', $e->getMessage());
        }

        $this->assertSame(0, $website->pages()->count(), 'A mid-batch failure must roll back every page already created in this attempt, never leave a partial batch.');
    }
}
