<?php

namespace Tests\Feature\Website\Setup;

use App\Models\QuestionnaireResponse;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV2Seeder;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The wizard's durable-save contract that makes in-page navigation safe:
 * autosave drafts, compare-and-swap revisions, Continue/Back JSON, resume.
 * (The browser half — the swap without a document reload — is exercised by
 * tests/Js/wizard-shell.test.mjs against the real script.)
 */
class WizardDurableNavigationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private $customer;

    private $business;

    private $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);

        [$this->customer, $this->business, $this->workspace] = $this->entitledTenant(['phone' => null]);
        $this->authenticateAsCustomer($this->customer);
        $this->get($this->url('start'));
    }

    /** A real browser form submission (multipart/urlencoded) that asks for JSON back. */
    protected function jpost(string $url, array $data = [])
    {
        return $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->post($url, $data);
    }

    protected function url(string $name, ?string $step = null): string
    {
        $params = [$this->workspace->uid, $this->business->uid];
        if ($step !== null) {
            $params[] = $step;
        }

        return route('customer.workspaces.businesses.website.setup.' . $name, $params);
    }

    protected function wizardSession(): QuestionnaireResponse
    {
        return QuestionnaireResponse::where('business_id', $this->business->id)->where('status', 'in_progress')->firstOrFail();
    }

    /** A basics-screen draft body (the screen has four steps, so fields are namespaced). */
    protected function basics(array $overrides = [], ?int $revision = null): array
    {
        return array_replace_recursive([
            'answers_revision' => $revision ?? $this->wizardSession()->answers_revision,
            '__present' => ['business_name', 'phone', 'email', 'primary_cta'],
            's' => [
                'business_name' => ['value' => 'Glow Booths'],
                'phone' => ['value' => '555-0100'],
                'email' => ['value' => 'hello@glowbooths.test'],
                'primary_cta' => ['value' => 'quote_request'],
            ],
        ], $overrides);
    }

    public function test_the_screen_ships_the_in_page_navigation_hooks(): void
    {
        $this->get($this->url('step', 'business_name'))
            ->assertOk()
            ->assertSee('data-wizard-root', false)
            ->assertSee('data-draft-url', false)
            ->assertSee('data-visit-url', false)
            ->assertSee('data-save-status', false)
            ->assertSee('Save &amp; exit', false)
            ->assertSee('name="__present[]"', false);
    }

    public function test_a_draft_saves_without_advancing_and_bumps_the_revision(): void
    {
        $before = $this->wizardSession();

        $this->jpost($this->url('draft', 'business_name'), $this->basics())
            ->assertOk()
            ->assertJson(['ok' => true, 'revision' => $before->answers_revision + 1]);

        $after = $this->wizardSession();
        $this->assertSame('business_name', $after->current_step_key, 'A draft never moves the resume position.');
        $this->assertSame('555-0100', $after->answers['phone']);
        $this->assertSame('Glow Booths', $after->answers['business_name']);
    }

    public function test_an_identical_draft_does_not_churn_the_revision(): void
    {
        $this->jpost($this->url('draft', 'business_name'), $this->basics())->assertOk();
        $revision = $this->wizardSession()->answers_revision;

        $this->jpost($this->url('draft', 'business_name'), $this->basics())
            ->assertOk()
            ->assertJson(['revision' => $revision]);
    }

    public function test_a_stale_draft_is_refused_and_never_overwrites_the_newer_answer(): void
    {
        $revision = $this->wizardSession()->answers_revision;

        $this->jpost($this->url('draft', 'business_name'), $this->basics([], $revision))->assertOk();

        // A slower, earlier request still carrying the old revision arrives late.
        $this->jpost($this->url('draft', 'business_name'), $this->basics(['s' => ['phone' => ['value' => 'STALE']]], $revision))
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'kind' => 'conflict']);

        $this->assertSame('555-0100', $this->wizardSession()->answers['phone']);
    }

    public function test_a_draft_may_be_incomplete_but_continue_still_demands_a_complete_answer(): void
    {
        $body = $this->basics(['s' => ['email' => ['value' => 'half@typed'], 'business_name' => ['value' => '']]]);

        $this->jpost($this->url('draft', 'business_name'), $body)->assertOk();
        $this->assertNull($this->wizardSession()->answers['business_name']);

        // Continue is strict: required name missing and the email is not an address.
        $this->jpost($this->url('autosave', 'business_name'), $this->basics(['s' => ['business_name' => ['value' => '']]], $this->wizardSession()->answers_revision))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'kind' => 'invalid']);
    }

    public function test_a_draft_only_touches_the_steps_it_carries_so_nothing_unrelated_is_erased(): void
    {
        $this->jpost($this->url('draft', 'business_name'), $this->basics())->assertOk();

        // A body that carries no step markers at all (a screen that failed to render) must not blank anything.
        $this->jpost($this->url('draft', 'business_name'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertOk();

        $this->assertSame('Glow Booths', $this->wizardSession()->answers['business_name']);
        $this->assertSame('555-0100', $this->wizardSession()->answers['phone']);

        // A marker for ONE step only changes that step.
        $this->jpost($this->url('draft', 'business_name'), [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => ['phone'],
            's' => ['phone' => ['value' => '555-0199']],
        ])->assertOk();

        $this->assertSame('555-0199', $this->wizardSession()->answers['phone']);
        $this->assertSame('Glow Booths', $this->wizardSession()->answers['business_name']);
        $this->assertSame('hello@glowbooths.test', $this->wizardSession()->answers['email']);
    }

    public function test_continue_returns_the_next_screen_as_json_and_back_returns_the_previous_with_answers_intact(): void
    {
        $this->jpost($this->url('autosave', 'business_name'), $this->basics())
            ->assertOk()
            ->assertJson(['ok' => true, 'full_page' => false])
            ->assertJsonPath('next_url', $this->url('step', 'service_area_cities'));

        $this->assertSame('service_area_cities', $this->wizardSession()->current_step_key);

        $this->jpost($this->url('back', 'service_area_cities'))
            ->assertOk()
            ->assertJson(['ok' => true, 'url' => $this->url('step', 'business_name')]);

        $this->assertSame('business_name', $this->wizardSession()->current_step_key);
        $this->get($this->url('step', 'business_name'))
            ->assertOk()
            ->assertSee('value="Glow Booths"', false)
            ->assertSee('value="555-0100"', false);
    }

    public function test_visiting_a_screen_records_the_resume_position_without_changing_answers(): void
    {
        $this->jpost($this->url('autosave', 'business_name'), $this->basics())->assertOk();
        $revision = $this->wizardSession()->answers_revision;

        $this->jpost($this->url('visit', 'business_name'))->assertOk();

        $this->assertSame('business_name', $this->wizardSession()->current_step_key);
        $this->assertSame($revision, $this->wizardSession()->answers_revision);
    }

    public function test_unfinished_drafts_survive_leaving_and_resume_on_the_same_screen(): void
    {
        $this->jpost($this->url('draft', 'business_name'), $this->basics(['s' => ['phone' => ['value' => '555-0123']]]))->assertOk();

        // "Close the browser and come back tomorrow": start() resumes exactly there, prefilled.
        $this->get($this->url('start'))->assertRedirect($this->url('step', 'business_name'));
        $this->get($this->url('step', 'business_name'))->assertSee('value="555-0123"', false);
    }

    public function test_a_service_area_list_keeps_its_exact_entries_and_order_through_a_draft_and_a_reload(): void
    {
        $this->jpost($this->url('autosave', 'business_name'), $this->basics())->assertOk();

        $areas = ['Irvine, CA', 'Anaheim, CA', 'Newport Beach, CA', 'Tustin, CA', 'Orange, CA'];
        $this->jpost($this->url('draft', 'service_area_cities'), [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => ['service_area_cities'],
            'value' => $areas,
        ])->assertOk();

        // Remove two, reorder: the exact list and order is what is stored.
        $reordered = ['Orange, CA', 'Irvine, CA', 'Newport Beach, CA'];
        $this->jpost($this->url('draft', 'service_area_cities'), [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => ['service_area_cities'],
            'value' => $reordered,
        ])->assertOk();

        $this->assertSame($reordered, $this->wizardSession()->answers['service_area_cities']);
    }

    public function test_another_business_cannot_draft_into_this_session(): void
    {
        [$otherCustomer] = $this->entitledTenant();
        $this->authenticateAsCustomer($otherCustomer);

        $this->jpost($this->url('draft', 'business_name'), $this->basics())->assertStatus(404);

        $this->assertArrayNotHasKey('business_name', $this->wizardSession()->answers ?? []);
    }

    public function test_the_plain_form_post_still_works_without_javascript(): void
    {
        $this->post($this->url('autosave', 'business_name'), $this->basics())
            ->assertRedirect($this->url('step', 'service_area_cities'));
    }
}
