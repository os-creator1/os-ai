<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\CrmContactStatus;
use App\Enums\Crm\CrmOpportunityStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Entitlement\EntitlementManager;
use App\Library\ViewAs\ViewAsRouteClass;
use App\Library\ViewAs\ViewAsRouteClassification;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * CRM Opportunities over HTTP: the board, adding and moving deals, customising
 * stages — and the tenancy boundary around all of it.
 */
class CrmOpportunitiesHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    public function test_setting_up_shows_a_board_with_the_pipeline_selector_and_columns_in_order(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);

        $this->post($this->crmRoute('setup', $workspace, $business))->assertRedirect();
        $this->post($this->crmRoute('setup', $workspace, $business))->assertRedirect();
        $this->assertSame(1, CrmPipeline::query()->forBusiness($business)->count(), 'Set up twice, copied once.');

        $html = $this->get($this->crmRoute('board', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="crm-pipeline-select"', $html);
        $this->assertStringContainsString('data-async-region="crm-board"', $html);
        $this->assertStringContainsString('data-async-form="crm-board"', $html);
        preg_match_all('/data-role="crm-column-name">([^<]+)</', $html, $columns);
        $this->assertSame(['New inquiry', 'Qualified', 'Proposal sent', 'Negotiating'], $columns[1]);
    }

    public function test_cards_link_to_the_deal_and_to_the_contact_and_filters_narrow_the_board(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $ana = $this->crmContact($business, ['FIRST_NAME' => 'Ana', 'LAST_NAME' => 'Petrauskaite'], '14155550101');
        $ben = $this->crmContact($business, ['FIRST_NAME' => 'Ben'], '14155550202');
        $kitchen = $this->deal($business, $pipeline, $ana, 'Kitchen renovation', valueMinor: 450000);
        $bathroom = $this->deal($business, $pipeline, $ben, 'Bathroom refit');
        $won = $this->deal($business, $pipeline, $ben, 'Deck build');
        app(CrmOpportunityService::class)->markWon($won);
        app(CrmOpportunityService::class)->setContactStatus($bathroom, CrmContactStatus::InContact);
        $this->authenticateAs($customer);
        $board = $this->crmRoute('board', $workspace, $business);

        $html = $this->get($board)->assertOk()->getContent();
        $this->assertStringContainsString('href="' . $this->crmRoute('opportunities.show', $workspace, $business, [$kitchen->uid]) . '"', $html);
        $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $ana->uid]) . '"', $html);
        $this->assertStringContainsString('Ana Petrauskaite', $html);
        $this->assertSame(['Bathroom refit', 'Kitchen renovation'], $this->cardTitles($html), 'Open deals only, by default.');

        $this->assertSame(['Deck build'], $this->cardTitles($this->get($board . '?status=won')->getContent()));
        $this->assertSame(['Kitchen renovation'], $this->cardTitles($this->get($board . '?q=kitchen')->getContent()));
        $this->assertSame(['Bathroom refit'], $this->cardTitles($this->get($board . '?q=' . urlencode('Ben'))->getContent()), 'Search matches the contact too.');
        $this->assertSame(['Kitchen renovation'], $this->cardTitles($this->get($board . '?q=0101')->getContent()), 'And the contact phone.');
        $this->assertSame(['Bathroom refit'], $this->cardTitles($this->get($board . '?contact_status=in_contact')->getContent()));
        $this->assertSame(['Kitchen renovation'], $this->cardTitles($this->get($board . '?contact_status=no_contact')->getContent()));
    }

    public function test_adding_an_opportunity_with_a_contact_of_this_business(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        [, $other] = $this->crmTenant('Other Studio', 'Other');
        $pipeline = $this->standardPipeline($business);
        $contact = $this->crmContact($business);
        $stranger = $this->crmContact($other);
        $this->authenticateAs($customer);

        $this->get($this->crmRoute('opportunities.create', $workspace, $business, ['pipeline' => $pipeline->uid, 'contact' => $contact->uid]))
            ->assertOk()
            ->assertSee('value="' . $contact->uid . '" selected', false);

        $this->post($this->crmRoute('opportunities.store', $workspace, $business), ['title' => 'Stranger deal', 'contact' => $stranger->uid, 'pipeline' => $pipeline->uid])
            ->assertSessionHasErrors('contact');
        $this->assertSame(0, CrmOpportunity::query()->count());

        $this->post($this->crmRoute('opportunities.store', $workspace, $business), ['title' => 'Garden design', 'contact' => $contact->uid, 'pipeline' => $pipeline->uid, 'value' => '1250.50'])
            ->assertRedirect($this->crmRoute('board', $workspace, $business, ['pipeline' => $pipeline->uid]));

        $deal = CrmOpportunity::query()->sole();
        $this->assertSame(['Garden design', $contact->id, 125050, 'new_inquiry'], [$deal->title, $deal->contact_id, $deal->value_minor, $deal->stage->semantic_key]);
        $this->assertSame(auth()->id(), $deal->created_by_user_id);
    }

    public function test_drag_and_drop_moves_through_the_json_endpoint_and_the_detail_page_shows_history(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $deal = $this->deal($business);
        $qualified = $this->stageKeyed($deal->pipeline, 'qualified');
        $this->authenticateAs($customer);

        $this->postJson($this->crmRoute('opportunities.move', $workspace, $business, [$deal->uid]), ['stage' => $qualified->uid])
            ->assertOk()
            ->assertJson(['moved' => true, 'stage' => ['uid' => $qualified->uid, 'name' => 'Qualified']]);
        $this->assertSame($qualified->id, $deal->fresh()->stage_id);

        $this->post($this->crmRoute('opportunities.won', $workspace, $business, [$deal->uid]))->assertRedirect();
        $this->postJson($this->crmRoute('opportunities.move', $workspace, $business, [$deal->uid]), ['stage' => $this->stageKeyed($deal->pipeline, 'negotiating')->uid])
            ->assertStatus(422)
            ->assertJson(['message' => 'Reopen this opportunity before moving it.']);

        $html = $this->get($this->crmRoute('opportunities.show', $workspace, $business, [$deal->uid]))->assertOk()->getContent();
        preg_match_all('/data-event="([a-z_]+)"/', $html, $events);
        $this->assertSame(['won', 'stage_changed', 'created'], $events[1]);
        $this->assertStringContainsString('Moved from <strong>New inquiry</strong> to <strong>Qualified</strong>', $html);
    }

    public function test_status_contact_status_and_details_forms(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $deal = $this->deal($business);
        $this->authenticateAs($customer);
        $args = [$deal->uid];

        $this->post($this->crmRoute('opportunities.contact-status', $workspace, $business, $args), ['contact_status' => 'in_contact'])->assertRedirect();
        $this->post($this->crmRoute('opportunities.contact-status', $workspace, $business, $args), ['contact_status' => 'qualified'])->assertSessionHasErrors('contact_status');
        $this->post($this->crmRoute('opportunities.update', $workspace, $business, $args), ['title' => 'Renamed deal', 'value' => '99'])->assertRedirect();
        $this->post($this->crmRoute('opportunities.lost', $workspace, $business, $args), ['lost_reason' => 'Budget'])->assertRedirect();

        $deal->refresh();
        $this->assertSame([CrmContactStatus::InContact, 'Renamed deal', 9900, CrmOpportunityStatus::Lost, 'Budget'], [$deal->contact_status, $deal->title, $deal->value_minor, $deal->status, $deal->lost_reason]);

        $this->post($this->crmRoute('opportunities.reopen', $workspace, $business, $args))->assertRedirect();
        $this->assertSame(CrmOpportunityStatus::Open, $deal->fresh()->status);
    }

    public function test_stage_settings_over_http(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $qualified = $this->stageKeyed($pipeline, 'qualified');
        $proposal = $this->stageKeyed($pipeline, 'proposal_sent');
        $newInquiry = $this->stageKeyed($pipeline, 'new_inquiry');
        $deal = $this->deal($business, $pipeline, stage: $qualified);
        $this->authenticateAs($customer);
        $settings = fn (string $name, array $extra = []) => $this->crmRoute($name, $workspace, $business, [$pipeline->uid, ...$extra]);

        $html = $this->get($settings('pipelines.settings'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-role="crm-start-stage"'));
        $this->assertSame(3, substr_count($html, 'data-role="crm-stage-archive"'), 'Every stage but New inquiry can be archived.');

        $this->post($settings('stages.store'), ['name' => 'Site visit'])->assertRedirect();
        $this->post($settings('stages.update', [$newInquiry->uid]), ['name' => 'Enquiries'])->assertRedirect();
        $this->post($settings('stages.move', [$proposal->uid]), ['direction' => 'up'])->assertRedirect();
        $this->post($settings('pipelines.update'), ['name' => 'Renovations'])->assertRedirect();

        $this->post($settings('stages.archive', [$newInquiry->uid]))->assertSessionHas('status', 'error');
        $this->post($settings('stages.archive', [$qualified->uid]))->assertSessionHas('status', 'error');
        $this->post($settings('stages.archive', [$qualified->uid]), ['destination' => $proposal->uid])->assertSessionHas('status', 'success');

        $this->assertSame('Renovations', $pipeline->fresh()->name);
        $this->assertSame(['Enquiries', 'Proposal sent', 'Negotiating', 'Site visit'], $pipeline->activeStages()->pluck('name')->all());
        $this->assertSame('new_inquiry', $newInquiry->fresh()->semantic_key);
        $this->assertSame($proposal->id, $deal->fresh()->stage_id);
    }

    public function test_everything_of_another_business_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant('Harbor Lane Studios', 'Harbor');
        [$otherCustomer, $other, $otherWorkspace] = $this->crmTenant('Other Studio', 'Other');
        $pipeline = $this->standardPipeline($business);
        $otherPipeline = $this->standardPipeline($other);
        $mine = $this->deal($business, $pipeline, title: 'My kitchen');
        $theirs = $this->deal($other, $otherPipeline, title: 'Their loft conversion');
        $this->authenticateAs($customer);

        // Their Business, in my session.
        $this->get($this->crmRoute('board', $otherWorkspace, $other))->assertNotFound();
        $this->post($this->crmRoute('setup', $otherWorkspace, $other))->assertNotFound();

        // Their records, addressed inside my Business.
        $this->get($this->crmRoute('opportunities.show', $workspace, $business, [$theirs->uid]))->assertNotFound();
        $this->postJson($this->crmRoute('opportunities.move', $workspace, $business, [$theirs->uid]), ['stage' => $this->stageKeyed($otherPipeline, 'qualified')->uid])->assertNotFound();
        $this->post($this->crmRoute('opportunities.won', $workspace, $business, [$theirs->uid]))->assertNotFound();
        $this->postJson($this->crmRoute('opportunities.move', $workspace, $business, [$mine->uid]), ['stage' => $this->stageKeyed($otherPipeline, 'qualified')->uid])->assertNotFound();
        $this->get($this->crmRoute('pipelines.settings', $workspace, $business, [$otherPipeline->uid]))->assertNotFound();
        $this->post($this->crmRoute('stages.archive', $workspace, $business, [$pipeline->uid, $this->stageKeyed($otherPipeline, 'qualified')->uid]))->assertNotFound();
        $this->post($this->crmRoute('opportunities.store', $workspace, $business), ['title' => 'x', 'contact' => $this->crmContact($business)->uid, 'pipeline' => $otherPipeline->uid])->assertNotFound();

        // Their deal is untouched, and their pipeline is not on my board.
        $this->assertSame(CrmOpportunityStatus::Open, $theirs->fresh()->status);
        $this->assertSame($theirs->stage_id, $theirs->fresh()->stage_id);
        $this->get($this->crmRoute('board', $workspace, $business, ['pipeline' => $otherPipeline->uid]))->assertOk()->assertDontSee($theirs->title);

        // The contact picker lists only this Business's contacts.
        $this->crmContact($other, ['FIRST_NAME' => 'Zed']);
        $this->crmContact($business, ['FIRST_NAME' => 'Zara']);
        $results = $this->getJson($this->crmRoute('contacts.search', $workspace, $business, ['q' => 'Z']))->assertOk()->json('results');
        $this->assertSame(['Zara'], array_map(fn ($row) => explode(' · ', $row['text'])[0], $results));

        $this->assertNotNull($otherCustomer);
    }

    public function test_permissions_and_the_crm_entitlement(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $deal = $this->deal($business);
        $board = $this->crmRoute('board', $workspace, $business);

        // A missing customer permission answers 401, the application's own
        // convention for an AuthorizationException (App\Exceptions\Handler).
        $this->authenticateAs($customer, ['view_contact']);
        $this->get($board)->assertOk()->assertDontSee('data-role="crm-add-opportunity"', false);
        $this->post($this->crmRoute('opportunities.won', $workspace, $business, [$deal->uid]))->assertUnauthorized();
        $this->post($this->crmRoute('pipelines.store', $workspace, $business), ['name' => 'Nope'])->assertUnauthorized();
        $this->assertSame(CrmOpportunityStatus::Open, $deal->fresh()->status);
        $this->assertSame(1, CrmPipeline::query()->forBusiness($business)->count());

        $this->authenticateAs($customer, ['update_contact']);
        $this->get($board)->assertUnauthorized();

        $this->authenticateAs($customer);
        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $workspace->owner_user_id);
        $this->get($board)->assertNotFound();
        $this->post($this->crmRoute('opportunities.won', $workspace, $business, [$deal->uid]))->assertNotFound();
    }

    public function test_routes_are_business_scoped_for_view_as_and_leave_the_ai_coo_advisor_alone(): void
    {
        $classification = app(ViewAsRouteClassification::class);
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn ($name) => is_string($name) && str_starts_with($name, 'customer.workspaces.businesses.crm.'))
            ->values();

        $this->assertCount(19, $names);
        foreach ($names as $name) {
            $this->assertSame(ViewAsRouteClass::BusinessScoped, $classification->classifyByName($name), $name);
        }

        $this->assertSame('App\Http\Controllers\Customer\OpportunityController@index', ltrim((string) Route::getRoutes()->getByName('customer.opportunities.index')?->getActionName(), '\\'));
    }

    /** @return list<string> card titles in board order */
    private function cardTitles(string $html): array
    {
        preg_match_all('/data-role="crm-card-title">([^<]+)</', $html, $matches);

        return $matches[1];
    }
}
