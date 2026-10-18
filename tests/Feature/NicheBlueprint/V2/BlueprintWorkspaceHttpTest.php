<?php

namespace Tests\Feature\NicheBlueprint\V2;

use App\Library\NicheBlueprint\Safety\BlueprintMode;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use App\Models\User;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\NicheBlueprint\Support\CreatesBlueprintInstallationFixtures;
use Tests\Feature\NicheBlueprint\V2\Support\SeedsPhotoBoothBlueprintV2;
use Tests\TestCase;

/** The Platform Owner experience: enter the Workspace, configure, save, publish, exit. */
class BlueprintWorkspaceHttpTest extends TestCase
{
    use CreatesBlueprintInstallationFixtures;
    use RefreshDatabase;
    use SeedsPhotoBoothBlueprintV2;

    private function actingAsAdmin(array $permissions = ['access backend']): User
    {
        $admin = User::create([
            'first_name' => 'Test', 'last_name' => 'Admin', 'email' => 'admin'.uniqid('', true).'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    private function blueprint(): NicheBlueprint
    {
        $this->ensureRequiredAppConfigRowsExist();
        (new WebsiteTemplateSeeder())->run();

        $admin = $this->platformAdminId();

        return $this->blueprintPublisher()->createBlueprint($admin, 'wedding_dj', 'Wedding DJ', null, 'wedding_vendor');
    }

    public function test_the_niche_page_shows_the_overview_and_the_enter_button(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprintV2();
        $this->actingAsAdmin();

        $response = $this->get(route('admin.niche-blueprints.show', $blueprint));

        $response->assertOk()
            ->assertSee('Enter Blueprint Workspace')
            ->assertSee('Configuration status')
            ->assertSee('Version history')
            ->assertSee('Businesses using it');

        foreach (['CRM', 'Automations', 'Forms', 'Website', 'SEO', 'Citations', 'Calendar', 'Documents', 'Packages'] as $surface) {
            $response->assertSee($surface);
        }

        $response->assertSee('7 recommendations', false);
    }

    public function test_every_surface_renders_against_the_real_photo_booth_blueprint(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprintV2();
        $this->actingAsAdmin();
        $this->post(route('admin.niche-blueprints.workspace.enter', $blueprint));

        foreach (\App\Library\NicheBlueprint\Workspace\BlueprintSurfaces::keys() as $surface) {
            $this->get(route('admin.niche-blueprints.workspace.show', [$blueprint, $surface]))
                ->assertOk();
        }

        $this->get(route('admin.niche-blueprints.workspace.show', [$blueprint, 'documents']))->assertOk()->assertSee('Photo Booth Proposal')->assertSee('25');
        $this->get(route('admin.niche-blueprints.workspace.show', [$blueprint, 'nonsense']))->assertNotFound();
    }

    public function test_the_workspace_requires_entering_first_and_is_admin_only(): void
    {
        $blueprint = $this->blueprint();

        $this->get(route('admin.niche-blueprints.workspace.show', [$blueprint, 'crm']))->assertUnauthorized();

        $this->actingAsAdmin();

        $this->get(route('admin.niche-blueprints.workspace.show', [$blueprint, 'crm']))
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint));
        $this->assertNull(NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->first());
    }

    public function test_a_customer_cannot_reach_the_workspace(): void
    {
        $blueprint = $this->blueprint();
        [$customer] = $this->workspaceWithPlanButNoBusiness();
        $this->actingAs($customer->user);

        $this->post(route('admin.niche-blueprints.workspace.enter', $blueprint))->assertUnauthorized();
        $this->assertNull(NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->first());
    }

    public function test_a_platform_owner_can_configure_save_and_publish_through_the_workspace(): void
    {
        $blueprint = $this->blueprint();
        $this->actingAsAdmin();

        $this->post(route('admin.niche-blueprints.workspace.enter', $blueprint))
            ->assertRedirect(route('admin.niche-blueprints.workspace.show', [$blueprint, 'crm']));

        $page = $this->get(route('admin.niche-blueprints.workspace.show', [$blueprint, 'crm']))->assertOk();
        $page->assertSee('Blueprint Mode')->assertSee('Editing Niche Blueprint')->assertSee('Save Draft')->assertSee('Publish v1')->assertSee('Exit Blueprint');
        $this->assertFalse(app(BlueprintMode::class)->active(), 'Blueprint mode is released after the request.');

        // CRM: pipeline, tags, a custom field.
        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'crm']), [
            'component_type' => 'crm_pipeline',
            'c' => ['name' => 'DJ bookings', 'stages' => "New Lead | new_inquiry\nQuoted\nBooked"],
        ])->assertRedirect()->assertSessionHas('flash_success');

        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'crm']), [
            'component_type' => 'crm_tag_set', 'c' => ['tags' => "Wedding\nCorporate"],
        ])->assertSessionHas('flash_success');

        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'crm']), [
            'component_type' => 'crm_custom_field', 'c' => ['label' => 'Venue', 'type' => 'text'],
        ])->assertSessionHas('flash_success');

        // Forms, Automations, Website, SEO, Booking, Packages through the same UI.
        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'forms']), [
            'component_type' => 'form',
            'c' => ['name' => 'Enquire', 'fields' => "full_name | Full name | text | required\nemail | Email | email | required\nphone | Phone | phone | required", 'create_opportunity' => '0'],
        ])->assertSessionHas('flash_success');

        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'automations']), [
            'component_type' => 'automation_workflow',
            'c' => ['name' => 'Follow up', 'trigger_type' => 'form_submitted', 'steps' => "add_tag: Hot Lead\nwait: 1 day\nsend_sms: Thanks for enquiring!"],
        ])->assertSessionHas('flash_success');

        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'website']), [
            'component_type' => 'website_config',
            'c' => ['template_key' => 'photo_booth_modern', 'pages' => "home | Home | hero, cta\ncontact | Contact | form", 'navigation' => "Home\nContact", 'content_prompts' => 'Describe the DJ experience.'],
        ])->assertSessionHas('flash_success');

        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'seo']), [
            'component_type' => 'seo_strategy', 'c' => ['keyword_patterns' => 'wedding dj {city} | transactional', 'faq_topics' => 'How early to book?'],
        ])->assertSessionHas('flash_success');

        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'calendar']), [
            'component_type' => 'booking_type', 'c' => ['name' => 'Consultation', 'duration_minutes' => '30', 'minimum_notice_minutes' => '120'],
        ])->assertSessionHas('flash_success');

        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'packages']), [
            'component_type' => 'package_template', 'c' => ['kind' => 'package', 'name' => 'Ceremony + reception'],
        ])->assertSessionHas('flash_success');

        $draft = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->firstOrFail();
        $this->assertSame('draft', $draft->state->value);
        $this->assertSame(9, $draft->components()->count());

        // Save Draft, then Publish.
        $this->post(route('admin.niche-blueprints.workspace.save', $blueprint), ['surface' => 'crm', 'notes' => 'First pass'])->assertSessionHas('flash_success');
        $this->assertSame('First pass', $draft->fresh()->notes);

        $this->post(route('admin.niche-blueprints.workspace.publish', $blueprint))
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint))
            ->assertSessionHas('flash_success');

        $this->assertSame('published', $draft->fresh()->state->value);

        $this->post(route('admin.niche-blueprints.workspace.exit', $blueprint))
            ->assertRedirect(route('admin.niche-blueprints.show', $blueprint));
    }

    public function test_bad_input_is_refused_with_a_message_and_writes_nothing(): void
    {
        $blueprint = $this->blueprint();
        $this->actingAsAdmin();
        $this->post(route('admin.niche-blueprints.workspace.enter', $blueprint));

        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'crm']), [
            'component_type' => 'crm_tag_set', 'c' => ['tags' => "Wedding\nclient@example.com"],
        ])->assertSessionHas('flash_error');

        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'website']), [
            'component_type' => 'website_config', 'c' => ['template_key' => 'does_not_exist', 'pages' => 'home | Home | hero'],
        ])->assertSessionHas('flash_error');

        $this->assertSame(0, NicheBlueprintComponent::query()->where('blueprint_id', $blueprint->id)->count());
    }

    public function test_editing_a_component_keeps_its_stable_key_and_a_new_draft_starts_from_the_published_copy(): void
    {
        $blueprint = $this->blueprint();
        $this->actingAsAdmin();
        $this->post(route('admin.niche-blueprints.workspace.enter', $blueprint));
        $this->post(route('admin.niche-blueprints.workspace.components.store', [$blueprint, 'crm']), [
            'component_type' => 'crm_tag_set', 'c' => ['tags' => 'Wedding'],
        ]);

        $component = NicheBlueprintComponent::query()->where('blueprint_id', $blueprint->id)->firstOrFail();
        $key = $component->component_key;

        $this->patch(route('admin.niche-blueprints.workspace.components.update', [$blueprint, 'crm', $component->id]), ['c' => ['tags' => "Wedding\nBirthday"]])
            ->assertSessionHas('flash_success');
        $this->assertSame($key, $component->fresh()->component_key);
        $this->assertSame(['Wedding', 'Birthday'], $component->fresh()->payload['tags']);

        $this->post(route('admin.niche-blueprints.workspace.publish', $blueprint));
        $this->post(route('admin.niche-blueprints.workspace.enter', $blueprint));

        $v2 = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'draft')->firstOrFail();
        $this->assertSame(2, (int) $v2->version_number);
        $this->assertSame([$key], $v2->components->pluck('component_key')->all(), 'The copy keeps the stable component identity.');
    }

    public function test_a_published_component_cannot_be_edited_through_the_workspace(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprintV2();
        $this->actingAsAdmin();

        $published = NicheBlueprintComponent::query()->where('blueprint_id', $blueprint->id)
            ->whereHas('version', fn ($q) => $q->where('state', 'published'))->where('component_type', 'crm_tag_set')->firstOrFail();

        $this->post(route('admin.niche-blueprints.workspace.enter', $blueprint));

        $this->patch(route('admin.niche-blueprints.workspace.components.update', [$blueprint, 'crm', $published->id]), ['c' => ['tags' => 'Hacked']])
            ->assertNotFound();
        $this->assertNotContains('Hacked', $published->fresh()->payload['tags']);
    }
}
