<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteStatus;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A contract §37.8 (AI). Every test
 * binds a Mockery double for WebsiteAiGenerationClient (directly, or via
 * the fixture's mockAiClient()) so no live OpenAI call is ever
 * attempted — the mock replaces the client entirely at the container
 * level, so there is no live network seam left to exercise.
 */
class WebsiteAiGenerationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_valid_ai_response_creates_exactly_the_generated_pages_and_reports_success(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $responseJson = json_encode([
            'pages' => [
                [
                    'title' => 'Home',
                    'is_home' => true,
                    'sections' => [$this->section('hero')],
                ],
                [
                    'title' => 'About Us',
                    'is_home' => false,
                    'slug' => 'about',
                    'sections' => [$this->section('text')],
                ],
            ],
        ]);

        $this->mockAiClient($responseJson);

        $response = $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]));
        $this->assertSame('success', session('status'));

        $this->assertSame(2, WebsitePage::where('website_id', $website->id)->count());
        $this->assertDatabaseHas('website_pages', [
            'website_id' => $website->id,
            'title' => 'Home',
            'is_home' => true,
            'slug' => null,
        ]);
        $this->assertDatabaseHas('website_pages', [
            'website_id' => $website->id,
            'title' => 'About Us',
            'is_home' => false,
            'slug' => 'about',
        ]);
    }

    public function test_response_missing_pages_key_fails_closed_with_zero_pages_created(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        // Missing the required top-level "pages" key entirely — invalid
        // on both the initial attempt and the one bounded retry.
        $this->mockAiClient(json_encode(['foo' => 'bar']));

        $response = $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]));
        $this->assertSame('error', session('status'));
        $this->assertSame(0, WebsitePage::where('website_id', $website->id)->count());
    }

    public function test_response_with_a_page_missing_sections_fails_closed_with_zero_pages_created(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->mockAiClient(json_encode([
            'pages' => [
                ['title' => 'Home', 'is_home' => true],
            ],
        ]));

        $response = $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]));
        $this->assertSame('error', session('status'));
        $this->assertSame(0, WebsitePage::where('website_id', $website->id)->count());
    }

    public function test_response_with_two_home_pages_fails_closed_with_zero_pages_created(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->mockAiClient(json_encode([
            'pages' => [
                ['title' => 'Home', 'is_home' => true, 'sections' => [$this->section('hero')]],
                ['title' => 'Also Home', 'is_home' => true, 'sections' => [$this->section('text')]],
            ],
        ]));

        $response = $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]));
        $this->assertSame('error', session('status'));
        $this->assertSame(0, WebsitePage::where('website_id', $website->id)->count());
    }

    public function test_response_with_an_unknown_section_type_fails_closed_with_zero_pages_created(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->mockAiClient(json_encode([
            'pages' => [
                [
                    'title' => 'Home',
                    'is_home' => true,
                    'sections' => [
                        ['type' => 'not_a_real_type', 'data' => ['heading' => 'Welcome']],
                    ],
                ],
            ],
        ]));

        $response = $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]));
        $this->assertSame('error', session('status'));
        $this->assertSame(0, WebsitePage::where('website_id', $website->id)->count());
    }

    public function test_a_successful_ai_generation_never_auto_publishes_the_website(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->assertSame(WebsiteStatus::Draft, $website->status);

        $responseJson = json_encode([
            'pages' => [
                ['title' => 'Home', 'is_home' => true, 'sections' => [$this->section('hero')]],
            ],
        ]);

        $this->mockAiClient($responseJson);

        $response = $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]));

        $response->assertRedirect();
        $this->assertSame('success', session('status'));

        $this->assertSame(WebsiteStatus::Draft, $website->fresh()->status);
        $this->assertNull($website->fresh()->published_revision_id);
        $this->assertSame(0, WebsiteRevision::where('website_id', $website->id)->count());
    }

    public function test_a_response_referencing_an_asset_is_rejected_because_ai_content_forbids_asset_references(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        // Otherwise perfectly valid, except the hero section carries a
        // non-empty background_image — WebsiteAiDraftGenerator always
        // calls WebsiteSectionValidator::validate() with
        // allowAssetReferences=false, so this must be rejected outright
        // rather than silently stripped.
        $responseJson = json_encode([
            'pages' => [
                [
                    'title' => 'Home',
                    'is_home' => true,
                    'sections' => [
                        $this->section('hero', ['background_image' => 'some-asset-uid']),
                    ],
                ],
            ],
        ]);

        $this->mockAiClient($responseJson);

        $response = $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]));
        $this->assertSame('error', session('status'));
        $this->assertSame(0, WebsitePage::where('website_id', $website->id)->count());
    }

    public function test_generation_context_includes_only_available_fields_for_the_target_business_and_excludes_other_businesses(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $business->update([
            'name' => 'Acme Plumbing Co',
            'phone' => '555-0100',
            'email' => 'contact@acme-plumbing.test',
        ]);
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        // A second, wholly unrelated tenant — its Business must never
        // leak into the first Website's generation payload.
        [, $otherBusiness] = $this->entitledTenant();
        $otherBusiness->update([
            'name' => 'Best Bakery LLC',
            'phone' => '555-0200',
        ]);
        $this->createWebsite($otherBusiness);

        $responseJson = json_encode([
            'pages' => [
                ['title' => 'Home', 'is_home' => true, 'sections' => [$this->section('hero')]],
            ],
        ]);

        $allowedFields = ['name', 'description', 'industry', 'phone', 'email', 'city', 'region', 'services'];

        // Built directly (rather than through the trait's generic
        // mockAiClient() helper) because this expectation must be the
        // ONLY one registered for complete(): Mockery matches
        // expectations in declaration order, so a prior unconstrained
        // expectation (as mockAiClient() installs) would always win over
        // a later withArgs()-constrained one and this payload assertion
        // would never actually run.
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('complete')
            ->withArgs(function (array $messages) use ($business, $otherBusiness, $allowedFields) {
                $userMessage = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

                $this->assertStringContainsString($business->name, $userMessage);
                $this->assertStringNotContainsString($otherBusiness->name, $userMessage);
                $this->assertStringNotContainsString($otherBusiness->phone, $userMessage);

                $jsonStart = strpos($userMessage, '{');
                $this->assertIsInt($jsonStart);

                $payload = json_decode(substr($userMessage, $jsonStart), true);
                $this->assertIsArray($payload);
                $this->assertSame([], array_diff(array_keys($payload), $allowedFields));

                return true;
            })
            ->andReturn($responseJson);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $response = $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.website.pages.index', [$workspace->uid, $business->uid]));
        $this->assertSame('success', session('status'));
    }

    public function test_no_secret_configuration_value_ever_lands_in_stored_website_data(): void
    {
        config(['services.openai.api_key' => 'sk-test-should-never-leak']);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $responseJson = json_encode([
            'pages' => [
                ['title' => 'Home', 'is_home' => true, 'sections' => [$this->section('hero')]],
                ['title' => 'Services', 'is_home' => false, 'slug' => 'services', 'sections' => [$this->section('services')]],
            ],
        ]);

        $this->mockAiClient($responseJson);

        $this->post(route('customer.workspaces.businesses.website.generate', [$workspace->uid, $business->uid]))
            ->assertRedirect();
        $this->assertSame('success', session('status'));

        $this->post(route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $secret = 'sk-test-should-never-leak';

        $pageSections = WebsitePage::where('website_id', $website->id)->pluck('sections');
        foreach ($pageSections as $sections) {
            $this->assertStringNotContainsString($secret, json_encode($sections));
        }

        $revision = WebsiteRevision::where('website_id', $website->id)->firstOrFail();
        $this->assertStringNotContainsString($secret, json_encode($revision->snapshot));
    }
}
