<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Models\AgencySite;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * A private Agency draft preview (page and stylesheet) answers every visitor who may not see it with the ONE
 * branded 404 — HTTP 404, no login redirect, no 401/403, nothing that reveals a draft exists. Other 401/403 pages
 * share the same shell with their own status; JSON stays JSON.
 */
class AgencySitePreviewDenialTest extends TestCase
{
    use CreatesAgencySaasFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();

        Route::middleware('web')->group(function (): void {
            Route::get('/__preview-denial/forbidden', fn () => throw new AuthorizationException('SECRET-DENIAL'));
            Route::get('/__preview-denial/auth', fn () => 'ok')->middleware('auth');
        });
    }

    /** @return array{0: Workspace, 1: Customer} */
    private function agency(string $name): array
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        app(AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $workspace, [
            'display_name' => $name, 'accent_color' => '#0a7d3b', 'support_email' => 'help@example.test', 'is_enabled' => '1',
        ]);

        return [$workspace, $owner];
    }

    private function url(string $name, Workspace $agency, array $extra = []): string
    {
        return route('customer.workspaces.agency.site.' . $name, ['workspaceUid' => $agency->uid] + $extra);
    }

    private function openEditor(Workspace $agency, Customer $owner): AgencySite
    {
        $this->authenticateAs($owner);
        $this->get(route('customer.workspaces.agency.white-label.show', ['workspaceUid' => $agency->uid, 'tab' => 'landing']))->assertOk();

        return AgencySite::query()->where('agency_workspace_id', $agency->id)->sole();
    }

    private function assertBrandedNotFound($response): void
    {
        $response->assertNotFound()
            ->assertSee('data-error-code="404"', false)
            ->assertSee('We can\'t find that page')
            ->assertSee('Back to Home')
            ->assertDontSee('Unauthorized')
            ->assertDontSee('Unauthenticated')
            ->assertDontSee('Draft preview');
    }

    public function test_the_owner_still_gets_the_draft_page_and_its_stylesheet(): void
    {
        [$agency, $owner] = $this->agency('Agency A');
        $this->openEditor($agency, $owner);

        $page = $this->get($this->url('preview', $agency, ['page' => 'home']))->assertOk();
        $this->assertStringContainsString($this->url('preview-css', $agency), $page->getContent());

        $css = $this->get($this->url('preview-css', $agency))->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')->getContent();
        $this->assertStringContainsString('#0a7d3b', $css);
    }

    public function test_a_signed_out_visitor_gets_the_branded_404_for_the_draft_and_its_stylesheet(): void
    {
        [$agency, $owner] = $this->agency('Agency A');
        $this->openEditor($agency, $owner);
        auth()->logout();

        $this->assertBrandedNotFound($this->get($this->url('preview', $agency, ['page' => 'home'])));
        $this->assertBrandedNotFound($this->get($this->url('preview', $agency)));
        $this->assertBrandedNotFound($this->get($this->url('preview-css', $agency)));
    }

    public function test_another_agency_cannot_see_the_draft_or_its_stylesheet(): void
    {
        [$agency, $owner] = $this->agency('Agency A');
        [, $otherOwner] = $this->agency('Agency B');
        $this->openEditor($agency, $owner);

        $this->authenticateAs($otherOwner);
        $this->assertBrandedNotFound($this->get($this->url('preview', $agency, ['page' => 'home'])));
        $this->assertBrandedNotFound($this->get($this->url('preview-css', $agency)));
    }

    public function test_the_public_404_page_is_unchanged(): void
    {
        $this->get('/no/such/page/anywhere')->assertNotFound()
            ->assertSee('data-error-code="404"', false)
            ->assertSee('We can\'t find that page')
            ->assertSee('The link may be mistyped, or the page may have moved or been removed.')
            ->assertSee('Error 404');
    }

    public function test_other_html_401_and_403_use_the_shared_shell_with_their_own_status(): void
    {
        $unauthenticated = $this->get('/__preview-denial/auth')->assertUnauthorized();
        $unauthenticated->assertSee('data-error-code="401"', false)
            ->assertSee('Unauthorized')
            ->assertDontSee('Unauthenticated.')
            ->assertDontSee('misc-wrapper', false);

        $this->get('/__preview-denial/forbidden')->assertUnauthorized()
            ->assertSee('data-error-code="401"', false)
            ->assertDontSee('SECRET-DENIAL');
    }

    public function test_an_explicit_403_abort_keeps_its_existing_handling(): void
    {
        Route::middleware('web')->get('/__preview-denial/abort-403', fn () => abort(403));

        // HttpException is answered as the branded 404 by the existing handler; unchanged by this fix.
        $this->get('/__preview-denial/abort-403')->assertNotFound();
    }

    public function test_json_and_login_flows_are_unaffected(): void
    {
        [$agency, $owner] = $this->agency('Agency A');
        $this->openEditor($agency, $owner);
        auth()->logout();

        $this->getJson('/__preview-denial/auth')->assertUnauthorized()->assertJson(['status' => 'error']);
        $this->getJson($this->url('preview', $agency, ['page' => 'home']))->assertUnauthorized()->assertJson(['status' => 'error']);
        $this->get(route('login'))->assertOk();
    }
}
