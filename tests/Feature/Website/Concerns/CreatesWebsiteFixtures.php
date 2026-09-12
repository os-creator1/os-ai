<?php

namespace Tests\Feature\Website\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

/**
 * Website Generation + Hosting Slice A — shared test fixtures. Builds an
 * ENTITLED, Business-scoped tenant (a Workspace on the Core plan tier —
 * the tier `website_generation` is already packaged into, contract
 * §26.1 — plus an active Business), mirroring B4's own
 * CreatesAutomationFixtures::entitledTenant() pattern exactly so the two
 * feature suites stay directly comparable.
 */
trait CreatesWebsiteFixtures
{
    use CreatesBusinessTestData;

    /**
     * A minimal, real 1x1 PNG — reused from the Branding upload test
     * suite's own fixture so a genuine magic-byte-valid image is
     * available without shipping a binary file.
     */
    private const VALID_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function entitledTenant(): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        $workspace = Workspace::query()->findOrFail($business->workspace_id);

        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->platformAdminId(), 'Website fixture assignment.', true, 0);

        return [$customer, $business->fresh(), $workspace->fresh()];
    }

    protected function platformAdminId(): int
    {
        return User::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'email' => 'platform-admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    protected function addMember(Workspace $workspace, User $user, WorkspaceMembershipRole $role, bool $isActive = true): WorkspaceMembership
    {
        return WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'business_access_scope' => WorkspaceBusinessAccessScope::All->value,
            'is_active' => $isActive,
        ]);
    }

    protected function authenticateAsCustomer(Customer $customer, array $permissions = ['website']): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    protected function authenticateAsUser(User $user, array $permissions = ['website']): void
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($user);
    }

    protected function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }

    protected function createWebsite(Business $business, array $overrides = []): Website
    {
        return Website::create(array_merge([
            'business_id' => $business->id,
            'name' => 'Test Website',
            'status' => \App\Enums\Website\WebsiteStatus::Draft,
        ], $overrides));
    }

    /**
     * A minimal, valid `data` payload for a given WebsiteSectionType
     * value, matching WebsiteSectionValidator's per-type rules exactly.
     */
    protected function sectionData(string $type): array
    {
        return match ($type) {
            'hero' => ['heading' => 'Welcome', 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null],
            'text' => ['heading' => 'About us', 'body' => 'We are a local business.'],
            'image_text' => ['heading' => 'Our story', 'body' => 'Founded in 2020.', 'image' => null, 'image_position' => 'left'],
            'services' => ['heading' => 'Services', 'items' => [['name' => 'Service one', 'description' => null, 'price_label' => null, 'image' => null]]],
            'testimonials' => ['heading' => 'Testimonials', 'items' => [['quote' => 'Great service!', 'author_name' => 'Jane Doe', 'author_title' => null]]],
            'faq' => ['heading' => 'FAQ', 'items' => [['question' => 'Are you open weekends?', 'answer' => 'Yes.']]],
            'cta' => ['heading' => 'Get in touch', 'body' => null, 'buttons' => [['label' => 'Contact us', 'url' => 'https://example.test/contact']]],
            'contact_details' => ['show_phone' => true, 'show_email' => true, 'show_address' => true],
            default => throw new \InvalidArgumentException("Unknown section type: {$type}"),
        };
    }

    protected function section(string $type, array $dataOverrides = []): array
    {
        return ['type' => $type, 'data' => array_merge($this->sectionData($type), $dataOverrides)];
    }

    /**
     * Directly persists a homepage WebsitePage bypassing
     * WebsiteDraftPageService — for tests that need a page fixture
     * already in place before exercising a different code path (e.g.
     * public rendering, asset retention). Tests proving the seam itself
     * (contract §37.3) go through the controller/service, never this
     * helper.
     */
    protected function homePage(Website $website, array $overrides = []): WebsitePage
    {
        return WebsitePage::create(array_merge([
            'website_id' => $website->id,
            'title' => 'Home',
            'slug' => null,
            'is_home' => true,
            'sections' => [$this->section('hero')],
            'seo_title' => null,
            'meta_description' => null,
            'noindex' => false,
            'sort_order' => 0,
        ], $overrides));
    }

    protected function subPage(Website $website, string $slug = 'about', array $overrides = []): WebsitePage
    {
        return WebsitePage::create(array_merge([
            'website_id' => $website->id,
            'title' => ucfirst($slug),
            'slug' => $slug,
            'is_home' => false,
            'sections' => [$this->section('text')],
            'seo_title' => null,
            'meta_description' => null,
            'noindex' => false,
            'sort_order' => 1,
        ], $overrides));
    }

    protected function validPngBytes(): string
    {
        return base64_decode(self::VALID_PNG_BASE64);
    }

    /**
     * A real, on-disk UploadedFile carrying genuine PNG magic bytes —
     * UploadedFile::fake() alone only fabricates a name/size, not real
     * image content, and every Website asset path re-validates real
     * magic bytes (contract §13).
     */
    protected function fakeImageUpload(string $name = 'photo.png', ?string $contents = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'website-asset-');
        file_put_contents($path, $contents ?? $this->validPngBytes());

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /**
     * Binds a mocked WebsiteAiGenerationClient so no live OpenAI call is
     * ever made (contract §37.8). Pass null to simulate a fail-closed
     * provider (missing/inactive key, or a provider exception).
     */
    protected function mockAiClient(?string $responseJson): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        // AI-1 Correction 6 — the generator now asks the client whether the
        // last call was refused for budget, so it can say "paused" instead
        // of "unavailable" and skip a retry that cannot help. These doubles
        // stand in for an ordinary, funded call.
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturn($responseJson);
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        return $mock;
    }
}
