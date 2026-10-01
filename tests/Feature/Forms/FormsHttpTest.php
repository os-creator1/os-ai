<?php

namespace Tests\Feature\Forms;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Forms\FormLifecycleState;
use App\Library\ViewAs\ViewAsRouteClass;
use App\Library\ViewAs\ViewAsRouteClassification;
use App\Library\Forms\FormSubmissionService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Forms V1 — the customer HTTP boundary: the full authorization chain on every
 * route, the standalone entitlement/capability, Location-scoped submission
 * visibility, bounded queries, View As, and the standalone navigation entry.
 */
class FormsHttpTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private $owner;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private Form $form;

    private FormDeployment $atDowntown;

    private FormDeployment $atUptown;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->business, $this->workspace] = $this->formsTenant();
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
        [$this->form, $this->atDowntown] = $this->liveForm($this->business, $this->downtown);
        $this->atUptown = $this->deploy($this->business, $this->form, $this->uptown);
    }

    private function postedForm(array $overrides = []): array
    {
        $input = $this->leadFormInput($overrides);
        $input['fields'] = array_map(fn (array $field) => array_merge($field, [
            'required' => ! empty($field['required']) ? '1' : '0',
            'contact_name' => ! empty($field['contact_name']) ? '1' : '0',
        ]), $input['fields']);
        $input['create_opportunity'] = ! empty($input['create_opportunity']) ? '1' : '0';

        return $input;
    }

    private function submission(FormDeployment $deployment, array $answers = []): FormSubmission
    {
        return app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment, $answers))->submission;
    }

    private function submissionsUrl(array $query = []): string
    {
        return $this->formsRoute('submissions.index', $this->workspace, $this->business).($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * EVERY customer route of the module, in one place, so the matrix and the
     * inventory test cannot silently miss one.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function everyRoute(): array
    {
        $w = $this->workspace;
        $b = $this->business;
        $submission = $this->submission($this->atDowntown, [FormSubmissionService::TOKEN_FIELD => \App\Library\Forms\FormOperationToken::issue($this->atDowntown)]);

        return [
            'index' => ['GET', $this->formsRoute('index', $w, $b), []],
            'create' => ['GET', $this->formsRoute('create', $w, $b), []],
            'store' => ['POST', $this->formsRoute('store', $w, $b), $this->postedForm(['name' => 'Probe'])],
            'submissions.index' => ['GET', $this->formsRoute('submissions.index', $w, $b), []],
            'submissions.show' => ['GET', $this->formsRoute('submissions.show', $w, $b, [$submission->uid]), []],
            'edit' => ['GET', $this->formsRoute('edit', $w, $b, [$this->form->uid]), []],
            'update' => ['POST', $this->formsRoute('update', $w, $b, [$this->form->uid]), $this->postedForm()],
            'activate' => ['POST', $this->formsRoute('activate', $w, $b, [$this->form->uid]), []],
            'deactivate' => ['POST', $this->formsRoute('deactivate', $w, $b, [$this->form->uid]), []],
            'locations.set' => ['POST', $this->formsRoute('locations.set', $w, $b, [$this->form->uid, $this->downtown->uid]), ['enabled' => 1]],
        ];
    }

    private function hit(string $method, string $url, array $payload = [])
    {
        return $method === 'GET' ? $this->get($url) : $this->post($url, $payload);
    }

    // ------------------------------------------------------------ route inventory

    public function test_every_forms_route_is_in_the_inventory_and_classified_business_scoped_for_view_as(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn ($name) => is_string($name) && str_starts_with($name, 'customer.workspaces.businesses.forms.'))
            ->map(fn ($name) => substr($name, strlen('customer.workspaces.businesses.forms.')))
            ->sort()->values()->all();

        $inventory = array_keys($this->everyRoute());
        sort($inventory);

        $this->assertSame($inventory, $registered, 'a route added without an inventory entry escapes the authorization matrix');

        $classification = app(ViewAsRouteClassification::class);
        foreach ($registered as $name) {
            $this->assertSame(
                ViewAsRouteClass::BusinessScoped,
                $classification->classifyByName('customer.workspaces.businesses.forms.'.$name),
                $name
            );
        }
    }

    // -------------------------------------------------------------- authorization

    public function test_the_owner_reaches_every_screen_and_action(): void
    {
        $this->authenticateAs($this->owner);

        foreach ($this->everyRoute() as $label => [$method, $url, $payload]) {
            $status = $this->hit($method, $url, $payload)->getStatusCode();
            $this->assertContains($status, [200, 302], "{$label} answered {$status}");
        }
    }

    public function test_a_stranger_without_tenancy_is_refused_everything_with_a_404(): void
    {
        $routes = $this->everyRoute();
        $this->authenticateAs($this->outsider());

        foreach ($routes as $label => [$method, $url, $payload]) {
            $this->hit($method, $url, $payload)->assertNotFound("{$label} must 404 for a stranger");
        }

        $this->assertSame(1, Form::count(), 'the stranger created nothing');
    }

    public function test_tenancy_without_the_forms_capability_is_refused_everything_with_a_401(): void
    {
        $routes = $this->everyRoute();
        $this->authenticateWithoutFormsCapability($this->owner);

        foreach ($routes as $label => [$method, $url, $payload]) {
            $this->hit($method, $url, $payload)->assertStatus(401);
        }
    }

    public function test_the_website_capability_never_stands_in_for_the_forms_capability(): void
    {
        $routes = $this->everyRoute();
        // Everything EXCEPT the forms key, including `website`.
        $this->authenticateWithoutFormsCapability($this->owner);
        $this->assertContains('website', $this->allCustomerPermissions());

        foreach ($routes as $label => [$method, $url, $payload]) {
            $this->hit($method, $url, $payload)->assertStatus(401);
        }

        // And the forms key ALONE is enough (no website, no anything else).
        $this->authenticateWithOnlyFormsCapability($this->owner);
        $this->get($this->formsRoute('index', $this->workspace, $this->business))->assertOk();
    }

    public function test_tenancy_and_capability_without_the_forms_entitlement_is_refused_everything_with_a_404(): void
    {
        $routes = $this->everyRoute();
        $this->denyFeature($this->workspace, PlatformFeature::Forms);
        $this->authenticateAs($this->owner);

        foreach ($routes as $label => [$method, $url, $payload]) {
            $this->hit($method, $url, $payload)->assertNotFound("{$label} must 404 when Forms is not entitled");
        }
    }

    public function test_forms_does_not_depend_on_the_website_feature(): void
    {
        $this->denyFeature($this->workspace, PlatformFeature::WebsiteGeneration);
        $this->authenticateAs($this->owner);

        $this->get($this->formsRoute('index', $this->workspace, $this->business))->assertOk();
        $this->get($this->formsRoute('create', $this->workspace, $this->business))->assertOk();
        $this->post($this->formsRoute('store', $this->workspace, $this->business), $this->postedForm(['name' => 'No website needed']))->assertRedirect();
        $this->assertSame(1, Form::where('name', 'No website needed')->count());
    }

    public function test_a_business_with_only_the_website_feature_is_not_given_forms(): void
    {
        $this->denyFeature($this->workspace, PlatformFeature::Forms);
        $this->authenticateAs($this->owner);

        $this->get($this->formsRoute('index', $this->workspace, $this->business))->assertNotFound();
    }

    public function test_a_foreign_businesses_form_is_not_found_through_my_business(): void
    {
        [, $otherBusiness] = $this->formsTenant(name: 'Other Studio');
        $theirs = $this->makeForm($otherBusiness, ['name' => 'Theirs']);
        $this->authenticateAs($this->owner);

        foreach ([
            ['GET', $this->formsRoute('edit', $this->workspace, $this->business, [$theirs->uid])],
            ['POST', $this->formsRoute('update', $this->workspace, $this->business, [$theirs->uid])],
            ['POST', $this->formsRoute('activate', $this->workspace, $this->business, [$theirs->uid])],
            ['POST', $this->formsRoute('deactivate', $this->workspace, $this->business, [$theirs->uid])],
            ['POST', $this->formsRoute('locations.set', $this->workspace, $this->business, [$theirs->uid, $this->downtown->uid])],
        ] as [$method, $url]) {
            $this->hit($method, $url, $this->postedForm() + ['enabled' => 1])->assertNotFound();
        }

        $this->assertSame(FormLifecycleState::Draft, $theirs->fresh()->lifecycle_state);
        $this->get($this->formsRoute('index', $this->workspace, $this->business))->assertDontSee('Theirs');
    }

    // ---------------------------------------------------------------- management

    public function test_a_form_is_created_edited_activated_deployed_and_switched_off_through_the_ui(): void
    {
        $this->authenticateAs($this->owner);
        $w = $this->workspace;
        $b = $this->business;

        $this->get($this->formsRoute('create', $w, $b))->assertOk()->assertSee('New form');

        $response = $this->post($this->formsRoute('store', $w, $b), $this->postedForm(['name' => 'Gallery inquiry']));
        $form = Form::where('name', 'Gallery inquiry')->firstOrFail();
        $response->assertRedirect($this->formsRoute('edit', $w, $b, [$form->uid]));
        $this->assertSame(FormLifecycleState::Draft, $form->lifecycle_state);

        $this->get($this->formsRoute('edit', $w, $b, [$form->uid]))->assertOk()->assertSee('Gallery inquiry')->assertSee('Offer here');

        // An edit that changes a question writes version 2.
        $edited = $this->postedForm(['name' => 'Gallery inquiry']);
        $edited['fields'][5]['label'] = 'Tell us more';
        $edited['fields'] = array_map(fn ($f, $i) => $f + ['key' => $form->currentVersion()->fields[$i]['key']], $edited['fields'], array_keys($edited['fields']));
        $this->post($this->formsRoute('update', $w, $b, [$form->uid]), $edited)->assertRedirect();
        $this->assertSame(2, $form->fresh()->current_version);

        $this->post($this->formsRoute('activate', $w, $b, [$form->uid]))->assertRedirect();
        $this->assertSame(FormLifecycleState::Active, $form->fresh()->lifecycle_state);

        $this->post($this->formsRoute('locations.set', $w, $b, [$form->uid, $this->downtown->uid]), ['enabled' => 1])->assertRedirect();
        $deployment = FormDeployment::where('form_id', $form->id)->firstOrFail();
        $this->assertSame((int) $this->downtown->id, (int) $deployment->business_location_id);
        $this->get($this->formsRoute('edit', $w, $b, [$form->uid]))->assertSee(route('public.forms.show', [$deployment->uid]));

        $this->post($this->formsRoute('deactivate', $w, $b, [$form->uid]))->assertRedirect();
        $this->assertSame(FormLifecycleState::Inactive, $form->fresh()->lifecycle_state);
        $this->get(route('public.forms.show', [$deployment->uid]))->assertNotFound();

        $this->post($this->formsRoute('locations.set', $w, $b, [$form->uid, $this->downtown->uid]), ['enabled' => 0])->assertRedirect();
        $this->assertFalse((bool) $deployment->fresh()->is_enabled);
    }

    public function test_a_rule_refusal_is_shown_as_the_manager_worded_it_and_writes_nothing(): void
    {
        $this->authenticateAs($this->owner);
        $before = Form::count();

        $bad = $this->postedForm(['name' => 'Two phones']);
        $bad['fields'][] = ['label' => 'Second phone', 'type' => 'phone', 'required' => '0', 'contact_name' => '0'];

        $this->from($this->formsRoute('create', $this->workspace, $this->business))
            ->post($this->formsRoute('store', $this->workspace, $this->business), $bad)
            ->assertRedirect($this->formsRoute('create', $this->workspace, $this->business))
            ->assertSessionHasErrors(['forms' => 'A form can have only one phone number question.']);

        $this->assertSame($before, Form::count());
    }

    public function test_a_deployment_cannot_name_a_location_of_another_business(): void
    {
        [, $otherBusiness] = $this->formsTenant(name: 'Other Studio');
        $foreign = $this->formsLocation($otherBusiness, 'Elsewhere');
        $this->authenticateAs($this->owner);

        $this->post($this->formsRoute('locations.set', $this->workspace, $this->business, [$this->form->uid, $foreign->uid]), ['enabled' => 1])->assertNotFound();
        $this->assertSame(2, FormDeployment::count());
    }

    // --------------------------------------------------------------- location ACL

    public function test_a_location_limited_member_sees_and_deploys_only_their_own_location(): void
    {
        $staff = $this->staffGrantedOnly($this->workspace, $this->downtown);
        $this->authenticateAs($staff);

        $edit = $this->get($this->formsRoute('edit', $this->workspace, $this->business, [$this->form->uid]))->assertOk();
        $edit->assertSee('Downtown')->assertDontSee('Uptown');

        // May manage the Business-wide definition...
        $this->post($this->formsRoute('update', $this->workspace, $this->business, [$this->form->uid]), $this->postedForm(['name' => 'Renamed by staff']))->assertRedirect();
        $this->assertSame('Renamed by staff', $this->form->fresh()->name);

        // ...may switch their own Location, but a sibling Location is a 404.
        $this->post($this->formsRoute('locations.set', $this->workspace, $this->business, [$this->form->uid, $this->downtown->uid]), ['enabled' => 0])->assertRedirect();
        $this->post($this->formsRoute('locations.set', $this->workspace, $this->business, [$this->form->uid, $this->uptown->uid]), ['enabled' => 0])->assertNotFound();
        $this->assertTrue((bool) $this->atUptown->fresh()->is_enabled);
    }

    public function test_a_location_limited_member_cannot_enumerate_or_inspect_another_locations_submissions(): void
    {
        $mine = $this->submission($this->atDowntown, ['your_name' => 'Downtown Dana', 'message' => 'downtown-only-secret']);
        $theirs = $this->submission($this->atUptown, ['your_name' => 'Uptown Uma', 'message' => 'uptown-only-secret']);

        $this->authenticateAs($this->staffGrantedOnly($this->workspace, $this->downtown));

        // The list: my Location's row, never the other's — not even as a count.
        $list = $this->get($this->submissionsUrl())->assertOk();
        $list->assertSee($mine->uid)->assertDontSee($theirs->uid)->assertDontSee('Uptown');

        // A filter naming the other Location is NOT silently ignored: it is a 404.
        $this->get($this->submissionsUrl(['location' => $this->uptown->uid]))->assertNotFound();
        $this->get($this->submissionsUrl(['location' => $this->downtown->uid]))->assertOk()->assertSee($mine->uid);

        // The single submission: mine opens; the other's is the same 404 as an unknown uid.
        $this->get($this->formsRoute('submissions.show', $this->workspace, $this->business, [$mine->uid]))->assertOk()->assertSee('downtown-only-secret');
        $this->get($this->formsRoute('submissions.show', $this->workspace, $this->business, [$theirs->uid]))->assertNotFound();
        $this->get($this->formsRoute('submissions.show', $this->workspace, $this->business, ['00000000-0000-4000-8000-000000000000']))->assertNotFound();
    }

    public function test_a_member_with_no_location_grant_sees_no_submissions_at_all(): void
    {
        $this->submission($this->atDowntown);

        $customer = $this->createCustomer();
        $membership = $this->createMembership($this->workspace, $customer->user, [
            'role' => \App\Enums\Workspace\WorkspaceMembershipRole::Staff,
            'business_access_scope' => \App\Enums\Workspace\WorkspaceBusinessAccessScope::All,
            'location_access_scope' => \App\Enums\Workspace\LocationAccessScope::Selected,
        ]);
        $this->authenticateAs($customer);

        $this->get($this->submissionsUrl())->assertOk()->assertSee('No responses yet')->assertDontSee('Downtown');
        $this->assertNotNull($membership);
    }

    public function test_an_owner_and_a_full_reach_member_see_every_location_and_the_filter_narrows(): void
    {
        $a = $this->submission($this->atDowntown);
        $b = $this->submission($this->atUptown);

        foreach ([$this->owner, $this->staffWithFullReach($this->workspace)] as $actor) {
            $this->authenticateAs($actor);
            $this->get($this->submissionsUrl())->assertOk()->assertSee($a->uid)->assertSee($b->uid);
            $this->get($this->submissionsUrl(['location' => $this->uptown->uid]))->assertOk()->assertSee($b->uid)->assertDontSee($a->uid);
            $this->get($this->submissionsUrl(['form' => $this->form->uid]))->assertOk()->assertSee($a->uid);
        }
    }

    public function test_the_form_filter_cannot_name_a_foreign_businesss_form(): void
    {
        [, $otherBusiness] = $this->formsTenant(name: 'Other Studio');
        $theirs = $this->makeForm($otherBusiness, ['name' => 'Theirs']);
        $this->authenticateAs($this->owner);

        $this->get($this->submissionsUrl(['form' => $theirs->uid]))->assertNotFound();
    }

    public function test_a_submission_of_another_business_is_not_found_through_mine(): void
    {
        [, $otherBusiness] = $this->formsTenant(name: 'Other Studio');
        $otherLocation = $this->formsLocation($otherBusiness, 'Elsewhere');
        [, $theirDeployment] = $this->liveForm($otherBusiness, $otherLocation);
        $theirs = $this->submission($theirDeployment);

        $this->authenticateAs($this->owner);

        $this->get($this->formsRoute('submissions.show', $this->workspace, $this->business, [$theirs->uid]))->assertNotFound();
        $this->get($this->submissionsUrl())->assertDontSee($theirs->uid);
    }

    // -------------------------------------------------------------- query bounds

    public function test_the_response_list_is_a_bounded_page_with_a_constant_number_of_queries(): void
    {
        $this->authenticateAs($this->owner);

        foreach (range(1, 4) as $i) {
            $this->submission($this->atDowntown, ['your_name' => "Person {$i}"]);
        }
        $this->get($this->submissionsUrl())->assertOk(); // warm up the shell
        $small = $this->queryCount(fn () => $this->get($this->submissionsUrl())->assertOk());

        foreach (range(5, 30) as $i) {
            $this->submission($i % 2 ? $this->atDowntown : $this->atUptown, ['your_name' => "Person {$i}"]);
        }
        $response = null;
        $large = $this->queryCount(function () use (&$response): void {
            $response = $this->get($this->submissionsUrl())->assertOk();
        });

        $this->assertSame($small, $large, 'queries must not grow with the number of responses');
        $this->assertSame(25, substr_count($response->getContent(), 'data-submission='), 'a page is at most 25 rows');
        $this->get($this->submissionsUrl(['page' => 2]))->assertOk();
    }

    private function queryCount(callable $run): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $run();

        return $count;
    }

    // -------------------------------------------------------------------- View As

    public function test_view_as_reaches_only_the_viewed_businesss_forms_and_responses(): void
    {
        [$agency, $viewed, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $managed = $this->createAgencyManagedClient($workspace, 'Sibling Client', 'Sibling Client Workspace');
        $sibling = $managed['clientBusiness'];

        $location = $this->formsLocation($viewed, 'Viewed Downtown');
        [, $deployment] = $this->liveForm($viewed, $location);
        $submission = $this->submission($deployment);

        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        $this->get($this->formsRoute('index', $workspace, $viewed))->assertOk();
        $this->get($this->formsRoute('submissions.index', $workspace, $viewed))->assertOk()->assertSee($submission->uid);
        $this->get($this->formsRoute('submissions.show', $workspace, $viewed, [$submission->uid]))->assertOk();
        $this->post($this->formsRoute('store', $workspace, $viewed), $this->postedForm(['name' => 'Made while viewing']))->assertRedirect();
        $this->assertSame(1, Form::where('business_id', $viewed->id)->where('name', 'Made while viewing')->count());

        // The sibling client's forms are out of reach for the whole session.
        $this->get($this->formsRoute('index', $managed['clientWorkspace'], $sibling))->assertNotFound();
        $this->get($this->formsRoute('submissions.index', $managed['clientWorkspace'], $sibling))->assertNotFound();
    }

    // ------------------------------------------------------------------ navigation

    public function test_forms_is_its_own_navigation_entry_independent_of_the_website_module(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$owner] = $this->formsTenant($tier, 'Tier '.$tier->value.' Studio');
            $this->authenticateAs($owner);

            $keys = $this->menuKeys($this->home()->assertOk()->getContent());
            $this->assertContains('forms', $keys, $tier->value.' sees Forms');
            $this->assertContains('website', $keys, 'Website remains its own, separate entry');
        }
    }

    public function test_the_forms_entry_links_to_the_forms_list_and_is_active_on_every_forms_screen(): void
    {
        $submission = $this->submission($this->atDowntown);
        $this->authenticateAs($this->owner);

        $this->assertContains(
            $this->formsRoute('index', $this->workspace, $this->business),
            $this->menuLinks($this->home()->assertOk()->getContent())
        );

        foreach ([
            $this->formsRoute('index', $this->workspace, $this->business),
            $this->formsRoute('create', $this->workspace, $this->business),
            $this->formsRoute('edit', $this->workspace, $this->business, [$this->form->uid]),
            $this->formsRoute('submissions.index', $this->workspace, $this->business),
            $this->formsRoute('submissions.show', $this->workspace, $this->business, [$submission->uid]),
        ] as $url) {
            $this->assertContains('forms', $this->activeMenuKeys($this->get($url)->assertOk()->getContent()), $url);
        }
    }

    public function test_the_forms_entry_follows_its_own_capability_and_entitlement_only(): void
    {
        // Without the capability: hidden.
        $this->authenticateWithoutFormsCapability($this->owner);
        $this->assertNotContains('forms', $this->menuKeys($this->home()->assertOk()->getContent()));

        // Website denied: Forms still shown.
        $this->denyFeature($this->workspace, PlatformFeature::WebsiteGeneration);
        $this->authenticateAs($this->owner);
        $keys = $this->menuKeys($this->home()->assertOk()->getContent());
        $this->assertContains('forms', $keys);
        $this->assertNotContains('website', $keys);

        // Forms denied: hidden.
        $this->denyFeature($this->workspace, PlatformFeature::Forms);
        $this->assertNotContains('forms', $this->menuKeys($this->home()->assertOk()->getContent()));
    }

    // ----------------------------------------------------------------- public link

    public function test_the_public_link_renders_the_form_and_records_a_submission_with_its_location(): void
    {
        $page = $this->get(route('public.forms.show', [$this->atUptown->uid]))->assertOk();
        $page->assertSee('Quote request')->assertSee('Tell us about your event.')->assertSee(FormSubmissionService::TOKEN_FIELD, false);
        preg_match('/name="operation_token" value="([^"]+)"/', $page->getContent(), $match);
        $this->assertNotEmpty($match[1] ?? null);

        $this->post(route('public.forms.submit', [$this->atUptown->uid]), $this->submitInput($this->atUptown, [], $match[1]));

        $submission = FormSubmission::firstOrFail();
        $this->assertSame((int) $this->uptown->id, (int) $submission->business_location_id);
        $thanks = route('public.forms.thanks', ['deploymentUid' => $this->atUptown->uid, 's' => $submission->uid]);
        $this->get(route('public.forms.thanks', [$this->atUptown->uid]))->assertOk()->assertSee('Thanks — we will be in touch.');
        $this->get($thanks)->assertOk()->assertSee('Thanks — we will be in touch.');
    }

    public function test_each_render_gets_its_own_token_and_a_double_post_converges(): void
    {
        $tokens = [];
        foreach ([1, 2] as $_) {
            preg_match('/name="operation_token" value="([^"]+)"/', $this->get(route('public.forms.show', [$this->atDowntown->uid]))->getContent(), $m);
            $tokens[] = $m[1];
        }
        $this->assertNotSame($tokens[0], $tokens[1], 'two renders, two logical submissions');

        $url = route('public.forms.submit', [$this->atDowntown->uid]);
        $this->post($url, $this->submitInput($this->atDowntown, [], $tokens[0]))->assertRedirect();
        $this->post($url, $this->submitInput($this->atDowntown, [], $tokens[0]))->assertRedirect();
        $this->assertSame(1, FormSubmission::count(), 'a double click is one submission');

        $this->post($url, $this->submitInput($this->atDowntown, [], $tokens[1]))->assertRedirect();
        $this->assertSame(2, FormSubmission::count(), 'a second render with the same text is a second submission');
    }

    public function test_a_filled_honeypot_is_answered_like_a_success_and_stores_nothing(): void
    {
        $this->post(route('public.forms.submit', [$this->atDowntown->uid]), $this->submitInput($this->atDowntown, [FormSubmissionService::HONEYPOT_FIELD => 'gotcha']))
            ->assertRedirect(route('public.forms.thanks', [$this->atDowntown->uid]));

        $this->assertSame(0, FormSubmission::count());
        $this->assertSame(0, \App\Models\Contacts::count());
    }

    public function test_invalid_answers_go_back_with_errors_and_store_nothing(): void
    {
        $url = route('public.forms.show', [$this->atDowntown->uid]);

        $this->from($url)->post(route('public.forms.submit', [$this->atDowntown->uid]), $this->submitInput($this->atDowntown, ['email' => 'nope']))
            ->assertRedirect($url)
            ->assertSessionHasErrors('email');

        $this->assertSame(0, FormSubmission::count());
    }

    public function test_every_refusal_of_the_public_link_is_the_same_404_on_get_and_post(): void
    {
        $scenarios = [
            'form switched off' => fn () => app(\App\Library\Forms\FormManager::class)->deactivate($this->business, $this->form),
            'location switched off' => fn () => app(\App\Library\Forms\FormManager::class)->setDeployment($this->business, $this->form, $this->downtown, false),
            'forms not entitled' => fn () => $this->denyFeature($this->workspace, PlatformFeature::Forms),
        ];

        foreach ($scenarios as $label => $change) {
            $change();
            $this->get(route('public.forms.show', [$this->atDowntown->uid]))->assertNotFound();
            $this->post(route('public.forms.submit', [$this->atDowntown->uid]), $this->submitInput($this->atDowntown))->assertNotFound();
            $this->get(route('public.forms.thanks', [$this->atDowntown->uid]))->assertNotFound();
        }

        $this->assertSame(0, FormSubmission::count());
        $this->get('/forms/not-a-uuid')->assertNotFound();
        $this->get('/forms/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }
}
