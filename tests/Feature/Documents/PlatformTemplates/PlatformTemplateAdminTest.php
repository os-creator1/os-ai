<?php

namespace Tests\Feature\Documents\PlatformTemplates;

use App\Library\ViewAs\ViewAsManager;
use App\Models\DocumentTemplate;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Contract 17B §6b - the Platform Owner's `/admin/document-templates` surface:
 * create, edit through the REAL editor endpoints, preview, publish / disable,
 * niche assignment (two explicit steps), and who is refused.
 */
class PlatformTemplateAdminTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;
    use CreatesDocumentsTestData;
    use CreatesCatalogHttpFixtures;
    use PlatformTemplateTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->owner();
    }

    private function draftTemplate(string $name = 'Draft layout'): DocumentTemplate
    {
        return $this->templates()->createPlatform($name, 'proposal', null, $this->owner());
    }

    // ---- create / index ---------------------------------------------------------------------

    public function test_the_owner_creates_a_blank_draft_platform_template_and_lands_in_the_editor(): void
    {
        $this->asOwner();

        $response = $this->post($this->adm('store'), ['name' => 'Wedding layout', 'template_type' => 'contract', 'description' => 'For weddings']);

        $template = DocumentTemplate::query()->whereNull('business_id')->where('name', 'Wedding layout')->firstOrFail();
        $response->assertRedirect($this->adm('edit', $template));
        $this->assertSame('draft', $template->status->value);
        $this->assertSame('contract', $template->template_type->value);
        $this->assertNull($template->business_id);
        $this->assertSame([], $template->blocks);
        $this->assertSame($this->owner()->id, (int) $template->created_by_user_id);
        $this->assertNull($template->seed_key);
    }

    public function test_create_validates_name_and_type(): void
    {
        $this->asOwner();

        $this->post($this->adm('store'), ['name' => '', 'template_type' => 'invoice'])->assertSessionHasErrors(['name', 'template_type']);
        $this->assertSame(0, DocumentTemplate::query()->count());
    }

    public function test_the_index_lists_platform_templates_only_with_status_and_live_niches(): void
    {
        $tenant = $this->photoBoothTenant();
        $own = $this->plantTemplate($tenant['business'], [], ['name' => 'Tenant private layout']);
        $blueprint = $this->seedPhotoBoothBlueprint();
        $live = $this->livePlatformTemplate('Photo booth live');
        $draft = $this->draftTemplate('Still a draft');
        $this->assignAndPublish($live, $blueprint);
        $this->asOwner();

        $html = $this->get($this->adm('index'))->assertOk()->getContent();

        $this->assertStringContainsString('Photo booth live', $html);
        $this->assertStringContainsString('Still a draft', $html);
        $this->assertStringNotContainsString('Tenant private layout', $html, 'a Business template never appears on the platform list');
        $this->assertSame(2, substr_count($html, 'data-role="platform-template-row"'));
        $this->assertMatchesRegularExpression('#data-template-uid="' . $live->uid . '".*?Published.*?Photo Booth#s', $html);
        $this->assertStringContainsString('Draft', $html);
        $this->assertNotNull($own);
    }

    // ---- editor ----------------------------------------------------------------------------

    public function test_the_editor_opens_in_platform_template_mode_without_contact_commerce_or_images(): void
    {
        $template = $this->livePlatformTemplate();
        $this->asOwner();

        $html = $this->get($this->adm('edit', $template))->assertOk()->getContent();
        $boot = $this->pageBootstrap($html);

        $this->assertSame('platform_template', $boot['mode']);
        $this->assertTrue($boot['editable']);
        $this->assertSame($template->uid, $boot['template']['uid']);
        $this->assertTrue($boot['template']['is_platform']);
        $this->assertSame('active', $boot['template']['status']);
        $this->assertSame([], $boot['images'], 'no platform media seam in V1');
        $this->assertSame([], $boot['lines']);
        $this->assertSame([], $boot['schedule']);
        $this->assertNull($boot['plan']);
        $this->assertSame('', $boot['contact']['name']);
        $this->assertSame('', $boot['business']['name']);
        $this->assertSame($this->adm('blocks', $template), $boot['urls']['blocks']);
        $this->assertSame($this->adm('preview', $template), $boot['urls']['preview']);
        $this->assertSame($this->adm('niches', $template), $boot['urls']['niches']);
        $this->assertArrayNotHasKey('send', $boot['urls']);

        $this->assertStringNotContainsString('data-tool="image"', $html, 'the Image block is not offered to platform templates');
        $this->assertStringContainsString('data-tool="product"', $html);
        $this->assertStringContainsString('data-tool="signature"', $html);
        $this->assertStringNotContainsString('data-role="action-send"', $html);
        $this->assertStringNotContainsString('data-role="action-save-template"', $html);
        $this->assertStringNotContainsString('data-role="editor-contact"', $html);
        foreach (['action-preview', 'action-save', 'action-assign-niches', 'action-unpublish', 'template-type', 'editor-status'] as $role) {
            $this->assertStringContainsString('data-role="' . $role . '"', $html, $role);
        }
        $this->assertStringNotContainsString('data-role="action-publish"', $html, 'a published template offers Unpublish, not Publish');
        $this->assertStringContainsString('>Published<', $html);
        $categoryTypes = collect($boot['toolbox']['categories'])->flatMap(fn ($c) => collect($c['items'])->pluck('type'))->all();
        $this->assertNotContains('image', $categoryTypes);
    }

    public function test_a_draft_template_offers_publish_and_a_disabled_one_offers_enable(): void
    {
        $draft = $this->draftTemplate();
        $this->asOwner();

        $html = $this->get($this->adm('edit', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="action-publish"', $html);
        $this->assertStringNotContainsString('data-role="action-unpublish"', $html);

        $live = $this->livePlatformTemplate('To disable');
        $this->templates()->disablePlatform($live, $this->owner());
        $html = $this->get($this->adm('edit', $live))->assertOk()->getContent();
        $this->assertStringContainsString('>Enable<', $html);
        $this->assertStringContainsString('>Disabled<', $html);
        $this->assertTrue($this->pageBootstrap($html)['editable'], 'a disabled platform template stays editable so it can be fixed and re-enabled');
    }

    public function test_the_blocks_endpoint_saves_and_bumps_the_lock_version_and_a_stale_lock_conflicts(): void
    {
        $template = $this->draftTemplate();
        $this->asOwner();
        $lock = (int) $template->lock_version;

        $ok = $this->putJson($this->adm('blocks', $template), [
            'blocks' => $this->platformBlocks(), 'name' => 'Renamed', 'template_type' => 'contract', 'description' => 'Hello', 'expected_lock_version' => $lock,
        ]);
        $ok->assertOk()->assertJson(['status' => 'ok', 'lock_version' => $lock + 1, 'name' => 'Renamed', 'template_type' => 'contract']);
        $fresh = $template->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertCount(6, $fresh->blocks);
        $this->assertSame('contract', $fresh->template_type->value);
        $this->assertNull($fresh->business_id);

        $before = $this->templateHash($template);
        $this->putJson($this->adm('blocks', $template), ['blocks' => [], 'expected_lock_version' => $lock])
            ->assertStatus(409)->assertJson(['status' => 'conflict', 'lock_version' => $lock + 1]);
        $this->assertSame($before, $this->templateHash($template), 'a conflicting save changes nothing');
    }

    public function test_the_blocks_endpoint_requires_a_lock_version_and_rejects_invalid_blocks_in_the_editor_shape(): void
    {
        $template = $this->draftTemplate();
        $this->asOwner();
        $lock = (int) $template->lock_version;

        $this->putJson($this->adm('blocks', $template), ['blocks' => []])
            ->assertStatus(422)->assertJsonPath('status', 'invalid')->assertJsonStructure(['message', 'errors' => ['expected_lock_version']]);

        $bad = [['id' => 'x1', 'type' => 'script', 'data' => []]];
        $this->putJson($this->adm('blocks', $template), ['blocks' => $bad, 'expected_lock_version' => $lock])
            ->assertStatus(422)->assertJsonPath('status', 'invalid');

        $unknownMerge = [['id' => 'x2', 'type' => 'text', 'data' => ['runs' => [['merge' => 'business.secret']]]]];
        $this->putJson($this->adm('blocks', $template), ['blocks' => $unknownMerge, 'expected_lock_version' => $lock])
            ->assertStatus(422);

        $this->assertSame($lock, (int) $template->fresh()->lock_version);
    }

    public function test_image_blocks_are_rejected_in_a_platform_template(): void
    {
        $tenant = $this->photoBoothTenant();
        $image = $this->ownImage($tenant['business']);
        $template = $this->draftTemplate();
        $this->asOwner();

        $blocks = [...$this->platformBlocks(), ['id' => 'img', 'type' => 'image', 'data' => ['catalog_image_uid' => $image->uid, 'alt' => 'x', 'width_pct' => 50]]];
        $this->putJson($this->adm('blocks', $template), ['blocks' => $blocks, 'expected_lock_version' => (int) $template->lock_version])
            ->assertStatus(422)->assertJsonPath('status', 'invalid');

        $this->assertSame([], $template->fresh()->blocks);
    }

    public function test_the_preview_renders_the_ONE_renderer_with_sample_data_and_no_business_data(): void
    {
        $template = $this->livePlatformTemplate();
        $this->asOwner();

        $html = $this->get($this->adm('preview', $template))->assertOk()->getContent();

        $this->assertStringContainsString('Proposal for', $html);
        $this->assertStringContainsString('Alex', $html, 'merge tokens resolve to sample data');
        $this->assertStringContainsString('Your Business', $html);
        $this->assertStringContainsString('data-role="preview-page"', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    // ---- publish / disable ------------------------------------------------------------------

    public function test_publish_requires_at_least_one_block_and_valid_blocks(): void
    {
        $empty = $this->draftTemplate();
        $this->asOwner();

        $this->post($this->adm('publish', $empty))->assertRedirect($this->adm('edit', $empty))->assertSessionHas('flash_error');
        $this->assertSame('draft', $empty->fresh()->status->value);

        // A planted image block (bypassing the editor) cannot be published either.
        DB::table('document_templates')->where('id', $empty->id)->update(['blocks' => json_encode([
            ['id' => 'i1', 'type' => 'image', 'data' => ['catalog_image_uid' => (string) \Illuminate\Support\Str::uuid(), 'alt' => '', 'width_pct' => 100]],
        ])]);
        $this->post($this->adm('publish', $empty))->assertRedirect($this->adm('edit', $empty))->assertSessionHas('flash_error');
        $this->assertSame('draft', $empty->fresh()->status->value);
    }

    public function test_publish_disable_and_enable_move_the_status_and_nothing_else(): void
    {
        $template = $this->draftTemplate();
        $this->asOwner();
        $this->putJson($this->adm('blocks', $template), ['blocks' => $this->platformBlocks(), 'expected_lock_version' => (int) $template->lock_version])->assertOk();
        $lock = (int) $template->fresh()->lock_version;
        $blocks = $template->fresh()->blocks;

        $this->post($this->adm('publish', $template))->assertRedirect($this->adm('index'))->assertSessionHas('flash_success');
        $this->assertSame('active', $template->fresh()->status->value);
        $this->post($this->adm('publish', $template))->assertRedirect($this->adm('index'));
        $this->assertSame('active', $template->fresh()->status->value, 'publishing twice is idempotent');

        $this->post($this->adm('disable', $template))->assertRedirect($this->adm('index'))->assertSessionHas('flash_success');
        $this->assertSame('archived', $template->fresh()->status->value);

        $this->post($this->adm('publish', $template))->assertRedirect($this->adm('index'));
        $this->assertSame('active', $template->fresh()->status->value, 'a disabled template is enabled through the same action');
        $this->assertSame($lock, (int) $template->fresh()->lock_version, 'status changes never touch the editor lock');
        $this->assertSame($blocks, $template->fresh()->blocks);
    }

    public function test_only_a_published_template_can_be_disabled(): void
    {
        $draft = $this->draftTemplate();
        $this->asOwner();

        $this->post($this->adm('disable', $draft))->assertRedirect($this->adm('edit', $draft))->assertSessionHas('flash_error');
        $this->assertSame('draft', $draft->fresh()->status->value);
    }

    // ---- authority --------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> method + route name for every admin template route */
    private function everyAdminRoute(DocumentTemplate $template): array
    {
        return [
            'index' => ['get', $this->adm('index')],
            'create' => ['get', $this->adm('create')],
            'store' => ['post', $this->adm('store')],
            'edit' => ['get', $this->adm('edit', $template)],
            'blocks' => ['put', $this->adm('blocks', $template)],
            'preview' => ['get', $this->adm('preview', $template)],
            'publish' => ['post', $this->adm('publish', $template)],
            'disable' => ['post', $this->adm('disable', $template)],
            'niches' => ['get', $this->adm('niches', $template)],
            'niches.update' => ['put', $this->adm('niches', $template)],
            'niches.publish' => ['post', $this->adm('niches.publish', $template)],
            'forged' => ['get', route('admin.document-templates.edit', [(string) \Illuminate\Support\Str::uuid()])],
        ];
    }

    public function test_a_non_owner_admin_account_a_customer_a_guest_and_a_view_as_frame_are_all_refused_everywhere(): void
    {
        $template = $this->livePlatformTemplate();
        $hash = $this->templateHash($template);
        $owner = $this->owner();

        $actors = [
            'non-owner admin account' => fn () => $this->asNonOwnerAdminAccount(),
            'customer owner' => fn () => $this->asTenant($this->photoBoothTenant()),
            'guest' => function () {
                $this->app['auth']->guard()->logout();
            },
            'owner inside a View As frame' => function () use ($owner) {
                $this->withSession(['permissions' => collect(['access backend']), ViewAsManager::SESSION_KEY => 'some-view-as-session-uid']);
                $this->actingAs($owner);
            },
        ];

        foreach ($actors as $who => $becomes) {
            $becomes();

            foreach ($this->everyAdminRoute($template) as $name => [$method, $url]) {
                $response = $method === 'put'
                    ? $this->putJson($url, ['blocks' => [], 'expected_lock_version' => 1, 'blueprints' => []])
                    : $this->{$method}($url, $method === 'post' ? ['name' => 'x', 'template_type' => 'proposal', 'blueprint' => (string) \Illuminate\Support\Str::uuid(), 'confirm' => 1] : []);

                $this->assertContains($response->getStatusCode(), [302, 401, 403, 404], "{$who}: {$name} -> {$response->getStatusCode()}");
                if ($response->getStatusCode() === 302) {
                    $this->assertStringNotContainsString('document-templates', (string) $response->headers->get('Location'), "{$who}: {$name} redirected into the surface");
                }
                $this->assertNotSame(200, $response->getStatusCode(), "{$who}: {$name}");
            }

            $this->assertSame($hash, $this->templateHash($template), "{$who} changed the platform template");
            $this->assertSame(1, DocumentTemplate::query()->whereNull('business_id')->count(), "{$who} created a template");
        }
    }

    public function test_a_forged_or_business_owned_uid_is_a_plain_404_for_the_owner(): void
    {
        $tenant = $this->photoBoothTenant();
        $own = $this->plantTemplate($tenant['business'], $this->platformBlocks(), ['name' => 'Tenant private']);
        $hash = $this->templateHash($own);
        $this->asOwner();

        $this->get(route('admin.document-templates.edit', [(string) \Illuminate\Support\Str::uuid()]))->assertNotFound();
        $this->get(route('admin.document-templates.preview', [(string) \Illuminate\Support\Str::uuid()]))->assertNotFound();

        foreach ([['get', 'edit'], ['get', 'preview'], ['get', 'niches'], ['post', 'publish'], ['post', 'disable']] as [$method, $name]) {
            $this->{$method}($this->adm($name, $own))->assertNotFound();
        }
        $this->putJson($this->adm('blocks', $own), ['blocks' => [], 'name' => 'Hijacked', 'expected_lock_version' => (int) $own->lock_version])->assertNotFound();
        $this->putJson($this->adm('niches', $own), ['blueprints' => []])->assertNotFound();

        $this->assertSame($hash, $this->templateHash($own), 'the platform surface never touches a Business template');
    }

    public function test_every_platform_template_route_sits_behind_the_administrator_middleware_and_no_business_route_reaches_the_controller(): void
    {
        $adminRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.document-templates.'));

        $this->assertGreaterThanOrEqual(11, $adminRoutes->count());
        foreach ($adminRoutes as $route) {
            $this->assertContains(\App\Http\Middleware\EnsureUserIsAdministrator::class, $route->gatherMiddleware(), $route->getName());
        }

        $leaks = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->getActionName(), 'Admin\\DocumentTemplateController') && ! str_starts_with((string) $route->getName(), 'admin.document-templates.'));
        $this->assertCount(0, $leaks);

        // The Business-side controller can only ever resolve a template through the Business-scoped access rules.
        $businessRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains((string) $route->getName(), 'businesses.document-templates.'));
        $this->assertGreaterThanOrEqual(8, $businessRoutes->count());
        foreach ($businessRoutes as $route) {
            $this->assertStringNotContainsString('Admin', $route->getActionName());
        }
    }

    public function test_a_business_owner_cannot_touch_a_platform_template_through_any_business_route(): void
    {
        $platform = $this->livePlatformTemplate('Recommended layout');
        $blueprint = $this->seedPhotoBoothBlueprint();
        $this->assignAndPublish($platform, $blueprint);
        $tenant = $this->photoBoothTenant();
        $this->assertContains($platform->uid, $this->recommendedUids($tenant['business']));
        $hash = $this->templateHash($platform);

        // Even a RECOMMENDED platform template is read-only to the Business: edit / write / lifecycle routes are 404.
        $this->get($this->tpl('edit', $tenant, $platform))->assertNotFound();
        $this->putJson($this->tpl('blocks', $tenant, $platform), ['blocks' => [], 'name' => 'Mine now', 'expected_lock_version' => (int) $platform->lock_version])->assertNotFound();
        $this->post($this->tpl('duplicate', $tenant, $platform))->assertNotFound();
        $this->post($this->tpl('archive', $tenant, $platform))->assertNotFound();
        $this->post($this->tpl('restore', $tenant, $platform))->assertNotFound();

        $this->assertSame($hash, $this->templateHash($platform));
    }

    // ---- niche assignment (two explicit steps) ----------------------------------------------

    public function test_assignment_step_one_writes_a_draft_copy_of_the_published_version_plus_the_component_and_recommends_nothing(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate();
        $tenant = $this->photoBoothTenant();
        $this->asOwner();

        $this->get($this->adm('niches', $template))->assertOk()
            ->assertSee('data-role="niche-row"', false)
            ->assertSee('Photo Booth', false)
            ->assertSee('Not in v1', false);

        $this->put($this->adm('niches', $template), ['blueprints' => [$blueprint->uid]])
            ->assertRedirect($this->adm('niches', $template))->assertSessionHas('flash_success');

        $draft = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'draft')->firstOrFail();
        $this->assertSame(2, (int) $draft->version_number);
        $components = NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->orderBy('position')->get();
        $this->assertSame(['crm_pipeline', 'document_template'], $components->pluck('component_type')->all(), 'the live pipeline component is carried into the draft');
        $this->assertSame('payments_contracts', $components[1]->required_feature_key);
        $this->assertSame($template->uid, $components[1]->payload['template_uid']);
        $this->assertSame('published', NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('version_number', 1)->value('state')->value);

        $this->assertSame([], $this->recommendedUids($tenant['business']), 'a draft-only assignment is NOT recommended');

        $html = $this->get($this->adm('niches', $template))->assertOk()->getContent();
        $this->assertStringContainsString('will add', $html);
        $this->assertStringContainsString('data-role="niche-publish"', $html);
        $this->assertStringContainsString('2. Publish blueprint versions', $html);
    }

    public function test_assignment_step_two_publishes_the_blueprint_version_and_only_then_the_business_sees_it(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate();
        $tenant = $this->photoBoothTenant();
        $this->asOwner();
        $this->put($this->adm('niches', $template), ['blueprints' => [$blueprint->uid]]);

        $this->post($this->adm('niches.publish', $template), ['blueprint' => $blueprint->uid, 'confirm' => 1])
            ->assertRedirect($this->adm('niches', $template))->assertSessionHas('flash_success');

        $versions = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->orderBy('version_number')->pluck('state', 'version_number')->map(fn ($s) => $s->value)->all();
        $this->assertSame([1 => 'superseded', 2 => 'published'], $versions, 'one published, no draft left');
        $this->assertSame([$template->uid], $this->recommendedUids($tenant['business']));
        $this->assertStringContainsString('Recommended in v2', $this->get($this->adm('niches', $template))->getContent());
    }

    public function test_publishing_the_blueprint_requires_the_explicit_confirmation(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate();
        $this->asOwner();
        $this->put($this->adm('niches', $template), ['blueprints' => [$blueprint->uid]]);

        $this->post($this->adm('niches.publish', $template), ['blueprint' => $blueprint->uid])->assertSessionHasErrors('confirm');
        $this->assertSame('draft', NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('version_number', 2)->value('state')->value);
    }

    public function test_unassigning_goes_through_the_same_two_steps_and_is_idempotent(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate();
        $tenant = $this->photoBoothTenant();
        $this->assignAndPublish($template, $blueprint);
        $this->asOwner();
        $this->assertSame([$template->uid], $this->recommendedUids($tenant['business']));

        $this->put($this->adm('niches', $template), [])->assertRedirect()->assertSessionHas('flash_success');
        $this->assertSame([$template->uid], $this->recommendedUids($tenant['business']), 'still recommended until the blueprint version is published');
        $this->assertStringContainsString('will remove', $this->get($this->adm('niches', $template))->getContent());

        // Saving the same choice again is a no-op (one draft, one change).
        $this->put($this->adm('niches', $template), [])->assertSessionHas('flash_success', 'No changes.');
        $this->assertSame(1, NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'draft')->count());

        $this->post($this->adm('niches.publish', $template), ['blueprint' => $blueprint->uid, 'confirm' => 1])->assertSessionHas('flash_success');
        $this->assertSame([], $this->recommendedUids($tenant['business']));
        $this->assertSame(1, NicheBlueprintComponent::query()
            ->whereIn('blueprint_version_id', NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'published')->pluck('id'))
            ->count(), 'the CRM pipeline component survives the round trip');
    }

    public function test_a_forged_niche_uid_fails_closed_and_changes_nothing(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate();
        $this->asOwner();
        $versionsBefore = NicheBlueprintVersion::query()->count();

        $this->put($this->adm('niches', $template), ['blueprints' => [$blueprint->uid, (string) \Illuminate\Support\Str::uuid()]])->assertNotFound();
        $this->put($this->adm('niches', $template), ['blueprints' => ['not-a-uuid']])->assertSessionHasErrors('blueprints.0');
        $this->post($this->adm('niches.publish', $template), ['blueprint' => (string) \Illuminate\Support\Str::uuid(), 'confirm' => 1])->assertNotFound();

        $this->assertSame($versionsBefore, NicheBlueprintVersion::query()->count());
        $this->assertSame(0, NicheBlueprintComponent::query()->where('component_type', 'document_template')->count());
    }

    public function test_publishing_a_blueprint_with_no_draft_is_refused_with_a_message(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate();
        $this->asOwner();

        $this->post($this->adm('niches.publish', $template), ['blueprint' => $blueprint->uid, 'confirm' => 1])
            ->assertRedirect($this->adm('niches', $template))->assertSessionHas('flash_error');
    }

    public function test_a_non_live_template_shows_a_warning_on_the_assignment_screen(): void
    {
        $this->seedPhotoBoothBlueprint();
        $draft = $this->draftTemplate();
        $this->asOwner();

        $this->get($this->adm('niches', $draft))->assertOk()->assertSee('data-role="template-not-live"', false);
    }

    public function test_the_admin_navigation_lists_proposal_templates_for_owners_only(): void
    {
        $this->asOwner();
        $html = $this->get($this->adm('index'))->assertOk()->getContent();

        $this->assertStringContainsString('Proposal Templates', $html);
        $this->assertStringContainsString('/document-templates', $html);
    }
}
