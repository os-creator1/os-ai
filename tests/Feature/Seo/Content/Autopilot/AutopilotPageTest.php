<?php

namespace Tests\Feature\Seo\Content\Autopilot;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\Seo\RunContentAutopilotJob;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Seo\Content\Autopilot\AutopilotPlanner;
use App\Library\Seo\Content\Autopilot\AutopilotSwitch;
use App\Library\Seo\Content\Autopilot\ContentProfile;
use App\Models\ContentAutopilotDecision as Decision;
use App\Models\ContentAutopilotSetting;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Content Autopilot, Slice 7 - the owner's home for Content: one switch, Next up, This month, Needs your input (only when
 * needed), Awaiting approval, Recently published, and the manual topic ideas one quiet link away. No AI on any page.
 */
class AutopilotPageTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private FakeAiCompletionClient $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeAiCompletionClient();
        $this->app->instance(AiCompletionClient::class, $this->fake);
        Http::preventStrayRequests();
    }

    private function owner(): array
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->authenticateAsSeoCustomer($t[0]);

        return $t;
    }

    private function url(array $t, string $name, array $extra = []): string
    {
        return rtrim((string) config('app.url'), '/') . route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$t[2]->uid, $t[1]->uid], $extra), false);
    }

    public function test_an_off_autopilot_invites_the_owner_to_turn_it_on_without_listing_topics(): void
    {
        $t = $this->owner();

        $html = $this->get($this->url($t, 'autopilot'))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="turn-on"', $html);
        $this->assertStringContainsString('data-state="off"', $html);
        $this->assertStringContainsString('data-role="next-up"', $html);
        $this->assertStringContainsString('data-role="this-month"', $html);
        $this->assertStringContainsString('0 published', $html);
        $this->assertStringContainsString('fewer is perfectly fine', $html);
        $this->assertStringNotContainsString('data-role="needs-input"', $html, 'only shown when something is needed');
        $this->assertStringNotContainsString('data-role="awaiting-approval"', $html);
        $this->assertStringNotContainsString('data-role="recently-published"', $html);
        $this->assertStringContainsString('Browse topic ideas', $html, 'the manual path stays one quiet link away');
        $this->assertStringNotContainsString('data-role="opportunity', $html, 'no topic list on the home page');
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_turning_it_on_asks_a_few_optional_questions_once_then_shows_it_running(): void
    {
        $t = $this->owner();

        $this->post($this->url($t, 'autopilot.enable'))->assertRedirect($this->url($t, 'autopilot.profile'));
        $setting = ContentAutopilotSetting::query()->where('business_id', $t[1]->id)->firstOrFail();
        $this->assertTrue($setting->enabled);
        $this->assertSame((int) $t[0]->user_id, $setting->enabled_by_user_id);

        $this->post($this->url($t, 'autopilot.profile.save'), [])->assertRedirect($this->url($t, 'autopilot'));

        $html = $this->get($this->url($t, 'autopilot'))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="on"', $html);
        $this->assertStringContainsString('data-role="pause"', $html);
        $this->assertStringContainsString('data-role="turn-off"', $html);

        // Already answered the questions: enabling again goes straight back.
        $this->post($this->url($t, 'autopilot.disable'))->assertRedirect($this->url($t, 'autopilot'));
        $this->post($this->url($t, 'autopilot.enable'))->assertRedirect($this->url($t, 'autopilot'));
    }

    public function test_pause_and_resume_and_off_never_touch_an_article(): void
    {
        $t = $this->owner();
        $article = $this->draftArticle($t[1]);
        $this->post($this->url($t, 'autopilot.enable'));

        $this->post($this->url($t, 'autopilot.pause'))->assertRedirect();
        $paused = $this->get($this->url($t, 'autopilot'))->getContent();
        $this->assertStringContainsString('data-state="paused"', $paused);
        $this->assertStringContainsString('Paused by you.', $paused);
        $this->assertStringContainsString('data-role="resume"', $paused);

        $this->post($this->url($t, 'autopilot.resume'));
        $this->assertTrue(app(AutopilotSwitch::class)->isRunning($t[1]));

        $this->post($this->url($t, 'autopilot.disable'));
        $this->assertFalse((bool) ContentAutopilotSetting::query()->first()->enabled);
        $this->assertSame($article->id, \App\Models\WebsiteArticle::query()->firstOrFail()->id);
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_the_one_question_appears_only_when_needed_and_answering_it_carries_on(): void
    {
        Bus::fake([RunContentAutopilotJob::class]);
        $t = $this->owner();
        app(AutopilotSwitch::class)->enable($t[1], (int) $t[0]->user_id);
        app(AutopilotPlanner::class)->evaluate($t[1]);

        $html = $this->get($this->url($t, 'autopilot'))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="needs-input"', $html);
        $this->assertStringContainsString('data-key="common_questions"', $html);
        $this->assertSame(1, substr_count($html, 'data-role="needs-input"'), 'one question at a time');

        $this->post($this->url($t, 'autopilot.answer'), ['key' => 'common_questions', 'answer' => 'How much space does a booth need?'])->assertRedirect($this->url($t, 'autopilot'));

        $this->assertSame(['How much space does a booth need?'], app(ContentProfile::class)->get($t[1])['common_questions']);
        $this->assertStringNotContainsString('data-role="needs-input"', $this->get($this->url($t, 'autopilot'))->getContent());
        Bus::assertDispatched(RunContentAutopilotJob::class, fn ($job) => $job->businessId === $t[1]->id);

        // Another answer adds to, never replaces, what is there.
        $this->post($this->url($t, 'autopilot.answer'), ['key' => 'common_questions', 'answer' => 'Do you travel?']);
        $this->assertSame(['How much space does a booth need?', 'Do you travel?'], app(ContentProfile::class)->get($t[1])['common_questions']);

        $this->post($this->url($t, 'autopilot.answer'), ['key' => 'avoid_topics', 'answer' => 'x'])->assertSessionHasErrors('key');
    }

    public function test_awaiting_approval_next_up_and_recently_published_show_the_real_articles(): void
    {
        $t = $this->owner();
        app(AutopilotSwitch::class)->enable($t[1], (int) $t[0]->user_id);
        $draft = $this->draftArticle($t[1], ['title' => 'A draft for you']);
        $draft->forceFill(['source' => 'autopilot', 'ai_generated' => true])->save();
        $live = $this->publishedArticle($t[1], ['title' => 'Already live article', 'primary_topic' => 'already live']);
        $live->forceFill(['source' => 'autopilot'])->save();

        foreach ([[$draft, Decision::STATE_AWAITING_APPROVAL, ['hard' => [], 'soft' => [], 'repaired' => false, 'ready' => true]], [$live, Decision::STATE_PUBLISHED, null]] as [$article, $state, $validation]) {
            Decision::query()->create([
                'business_id' => $t[1]->id, 'kind' => 'create', 'decision' => 'create', 'state' => $state, 'opportunity_key' => 'k:' . Str::random(6),
                'article_id' => $article->id, 'validation' => $validation, 'period_key' => now('UTC')->format('Y-m'), 'evaluated_at' => now(),
            ]);
        }

        $html = $this->get($this->url($t, 'autopilot'))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="awaiting-approval"', $html);
        $this->assertStringContainsString('A draft for you', $html);
        $this->assertStringContainsString('Ready for you to review.', $html);
        $this->assertStringContainsString('Articles in your field are always reviewed by you', $html, 'why it is not publishing itself (the niche declares no policy yet)');
        $this->assertStringContainsString('data-role="recently-published"', $html);
        $this->assertStringContainsString('Already live article', $html);
        $this->assertStringContainsString('1 published', $html);
        $this->assertStringContainsString('1 planned', $html);
    }

    public function test_a_budget_pause_is_explained_in_plain_words_without_amounts(): void
    {
        $t = $this->owner();
        app(AutopilotSwitch::class)->enable($t[1], (int) $t[0]->user_id);
        app(AutopilotSwitch::class)->markPaused($t[1], AutopilotSwitch::PAUSE_BUDGET);

        $html = $this->get($this->url($t, 'autopilot'))->getContent();

        $this->assertStringContainsString('allowance has been used', $html);
        $status = strip_tags(explode('</p>', explode('data-role="autopilot-status">', $html)[1] ?? '')[0]);
        $this->assertStringNotContainsString('$', $status, 'no dollar amounts');
    }

    public function test_the_sidebar_leads_with_autopilot_and_articles_and_the_manual_pages_stay_reachable(): void
    {
        $t = $this->owner();

        $html = $this->get($this->url($t, 'autopilot'))->assertOk()->getContent();

        $this->assertStringContainsString('data-nav-key="seo-content-autopilot"', $html);
        $this->assertStringContainsString('data-nav-key="seo-content-articles"', $html);
        $this->assertStringNotContainsString('data-nav-key="seo-content-plan"', $html);
        $this->assertStringNotContainsString('data-nav-key="seo-content-opportunities"', $html);

        foreach (['plan', 'opportunities'] as $name) {
            $this->get($this->url($t, $name))->assertOk()->assertSee('data-tab="autopilot"', false);
        }
    }

    public function test_core_businesses_have_no_autopilot_and_see_only_articles(): void
    {
        $this->platformAdminId();
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->authenticateAsSeoCustomer($customer);
        Website::create(['business_id' => $business->id, 'name' => 'Site', 'status' => \App\Enums\Website\WebsiteStatus::Draft]);
        $t = [$customer, $business, $workspace];

        foreach (['autopilot', 'autopilot.profile'] as $name) {
            $this->get($this->url($t, $name))->assertNotFound();
        }
        foreach (['autopilot.enable', 'autopilot.disable', 'autopilot.pause', 'autopilot.resume', 'autopilot.answer'] as $name) {
            $this->post($this->url($t, $name), ['key' => 'emphasis', 'answer' => 'x'])->assertNotFound();
        }

        $html = $this->get($this->url($t, 'articles.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-nav-key="seo-content-autopilot"', $html);
        $this->assertStringNotContainsString('data-tab="autopilot"', $html);
        $this->assertSame(0, ContentAutopilotSetting::query()->count());
    }

    public function test_a_business_without_a_website_is_told_to_create_one(): void
    {
        $t = $this->owner();
        Website::query()->where('business_id', $t[1]->id)->forceDelete();

        $this->get($this->url($t, 'autopilot'))->assertOk()->assertSee('website', false);
        $this->post($this->url($t, 'autopilot.enable'))->assertRedirect()->assertSessionHas('status', 'error');
        $this->assertSame(0, ContentAutopilotSetting::query()->count());
    }
}
