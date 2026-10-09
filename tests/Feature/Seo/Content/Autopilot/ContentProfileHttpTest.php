<?php

namespace Tests\Feature\Seo\Content\Autopilot;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Website\WebsiteStatus;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Models\ContentAutopilotSetting;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Content Autopilot, Slice 2 — the first-enable Content Profile over HTTP: SeoModule only, no AI, honest copy.
 */
class ContentProfileHttpTest extends TestCase
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

    private function url(array $tenant, string $name): string
    {
        return rtrim((string) config('app.url'), '/') . route('customer.workspaces.businesses.seo.content.' . $name, [$tenant[2]->uid, $tenant[1]->uid], false);
    }

    public function test_the_owner_sees_the_short_flow_and_saves_it_without_any_ai_call(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->authenticateAsSeoCustomer($t[0]);

        $html = $this->get($this->url($t, 'autopilot.profile'))->assertOk()->getContent();
        $this->assertStringContainsString('What we already know', $html);
        $this->assertStringContainsString('data-field="differentiators"', $html);
        $this->assertStringContainsString('data-field="common_questions"', $html);
        $this->assertStringContainsString('data-field="emphasis"', $html);
        $this->assertStringContainsString('data-field="avoid_topics"', $html);

        $this->post($this->url($t, 'autopilot.profile.save'), [
            'differentiators' => "Same-day setup",
            'common_questions' => "How much space do you need?\nDo you travel?",
            'emphasis' => 'Weddings',
            'avoid_topics' => '',
        ])->assertRedirect($this->url($t, 'autopilot'));

        $setting = ContentAutopilotSetting::query()->where('business_id', $t[1]->id)->firstOrFail();
        $this->assertSame(['How much space do you need?', 'Do you travel?'], $setting->profile['common_questions']);
        $this->assertNotNull($setting->profile_completed_at);

        $after = $this->get($this->url($t, 'autopilot.profile'))->assertOk()->getContent();
        $this->assertStringContainsString('data-field="differentiators-known"', $after, 'already known, so not asked again');
        $this->assertStringContainsString('How much space do you need?', $after);
        $this->assertSame(0, $this->fake->callCount());
    }

    public function test_over_limit_input_is_refused_with_a_friendly_message(): void
    {
        $t = $this->photoBoothContentTenant(withBlueprint: true);
        $this->authenticateAsSeoCustomer($t[0]);

        $this->post($this->url($t, 'autopilot.profile.save'), ['common_questions' => implode("\n", array_map(fn ($i) => "Question {$i}?", range(1, 12)))])
            ->assertSessionHasErrors('common_questions');
        $this->assertSame(0, ContentAutopilotSetting::query()->count());
    }

    public function test_a_business_without_the_seo_module_gets_a_404(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->authenticateAsSeoCustomer($customer);

        $url = rtrim((string) config('app.url'), '/') . route('customer.workspaces.businesses.seo.content.autopilot.profile', [$workspace->uid, $business->uid], false);
        $this->get($url)->assertNotFound();
        $this->post(rtrim((string) config('app.url'), '/') . route('customer.workspaces.businesses.seo.content.autopilot.profile.save', [$workspace->uid, $business->uid], false), ['emphasis' => 'x'])->assertNotFound();
    }
}
