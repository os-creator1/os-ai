<?php

namespace Tests\Feature\Documents\PlatformTemplates;

use App\Enums\Documents\DocumentTemplateStatus;
use App\Library\Documents\Templates\RecommendedPlatformTemplates;
use App\Models\BusinessDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Contract 17B §6b - the niche -> "Recommended for your business" linkage and
 * "Use template": recommendations come ONLY from the PUBLISHED blueprint version
 * of the Business's niche, for ACTIVE platform templates; using one creates a
 * Business-owned draft and never mutates the platform template.
 */
class PlatformTemplateRecommendationTest extends TestCase
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

    public function test_a_business_in_the_photo_booth_niche_is_recommended_exactly_the_assigned_active_templates_in_blueprint_order(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $a = $this->livePlatformTemplate('Template A');
        $b = $this->livePlatformTemplate('Template B');
        $unassigned = $this->livePlatformTemplate('Never assigned');
        $this->assignments()->assign($this->owner(), $b, $blueprint);
        $this->assignments()->assign($this->owner(), $a, $blueprint);
        $this->assignments()->publishDraft($this->owner(), $blueprint);
        $tenant = $this->photoBoothTenant();

        $this->assertSame([$b->uid, $a->uid], $this->recommendedUids($tenant['business']));
        $this->assertNotContains($unassigned->uid, $this->recommendedUids($tenant['business']), 'no global dump');
    }

    public function test_the_recommended_templates_appear_in_the_business_library_and_the_new_proposal_step(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate('Wedding proposal layout');
        $this->assignAndPublish($template, $blueprint);
        $tenant = $this->photoBoothTenant();

        $html = $this->get($this->tpl('index', $tenant))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="recommended-card"', $html);
        $this->assertStringContainsString('Wedding proposal layout', $html);
        $this->assertStringNotContainsString('data-role="recommended-empty"', $html);
        $this->assertStringNotContainsString('data-role="template-edit"', $html, 'a recommended template is use / preview only');

    }

    public function test_an_unrelated_niche_business_sees_nothing_even_when_a_template_is_assigned_elsewhere(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate();
        $this->assignAndPublish($template, $blueprint);
        $other = $this->homeServicesTenant();

        $this->assertSame([], $this->recommendedUids($other['business']));
        $html = $this->get($this->tpl('index', $other))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="recommended-empty"', $html);
        $this->assertStringNotContainsString($template->uid, $html);

        // A different niche with its own published blueprint but no assignment: still nothing.
        $homeBlueprint = $this->publisher()->createBlueprint($this->owner()->id, 'home_services', 'Home Services', null, 'home_services');
        $this->publisher()->createDraftVersion($this->owner()->id, $homeBlueprint);
        $this->publisher()->publishVersion($this->owner()->id, \App\Models\NicheBlueprintVersion::query()->where('blueprint_id', $homeBlueprint->id)->firstOrFail());
        $this->assertSame([], $this->recommendedUids($other['business']));
    }

    public function test_a_business_with_no_blueprint_at_all_or_an_inactive_one_sees_nothing(): void
    {
        $template = $this->livePlatformTemplate();
        $tenant = $this->photoBoothTenant();
        $this->assertSame([], $this->recommendedUids($tenant['business']), 'no blueprint exists');

        $blueprint = $this->seedPhotoBoothBlueprint();
        $this->assignAndPublish($template, $blueprint);
        $this->assertSame([$template->uid], $this->recommendedUids($tenant['business']));

        $this->publisher()->deactivateBlueprint($this->owner()->id, $blueprint);
        $this->assertSame([], $this->recommendedUids($tenant['business']), 'an inactive blueprint resolves for nobody');
    }

    public function test_a_draft_version_assignment_is_not_recommended_until_the_version_is_published(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate();
        $tenant = $this->photoBoothTenant();

        $this->assignments()->assign($this->owner(), $template, $blueprint);
        $this->assertSame([], $this->recommendedUids($tenant['business']));

        $this->assignments()->publishDraft($this->owner(), $blueprint);
        $this->assertSame([$template->uid], $this->recommendedUids($tenant['business']));
    }

    public function test_a_superseded_version_stops_recommending(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate();
        $tenant = $this->photoBoothTenant();
        $this->assignAndPublish($template, $blueprint);
        $this->assertSame([$template->uid], $this->recommendedUids($tenant['business']));

        $this->assignments()->unassign($this->owner(), $template, $blueprint);
        $this->assignments()->publishDraft($this->owner(), $blueprint);

        $this->assertSame([], $this->recommendedUids($tenant['business']));
    }

    public function test_disabling_a_template_removes_it_immediately_without_any_blueprint_change(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate();
        $this->assignAndPublish($template, $blueprint);
        $tenant = $this->photoBoothTenant();
        $versions = DB::table('niche_blueprint_versions')->count();
        $this->assertSame([$template->uid], $this->recommendedUids($tenant['business']));

        $this->templates()->disablePlatform($template, $this->owner());

        $this->assertSame([], $this->recommendedUids($tenant['business']));
        $this->assertSame($versions, DB::table('niche_blueprint_versions')->count());
        $this->docsStore($tenant, ['template_uid' => $template->uid])->assertNotFound();
        $this->get($this->tpl('preview', $tenant, $template))->assertNotFound();

        $this->templates()->activatePlatform($template->fresh(), $this->owner());
        $this->assertSame([$template->uid], $this->recommendedUids($tenant['business']));
    }

    public function test_a_draft_platform_template_is_never_recommended_even_if_a_blueprint_references_it(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $draft = $this->templates()->createPlatform('Unfinished', 'proposal', null, $this->owner());
        $this->assertSame(DocumentTemplateStatus::Draft, $draft->status);
        $this->assignAndPublish($draft, $blueprint);
        $tenant = $this->photoBoothTenant();

        $this->assertSame([], $this->recommendedUids($tenant['business']));
        $this->docsStore($tenant, ['template_uid' => $draft->uid])->assertNotFound();
    }

    public function test_using_a_recommended_template_creates_a_business_owned_draft_and_never_mutates_the_platform_template(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate('Recommended proposal');
        $this->assignAndPublish($template, $blueprint);
        $tenant = $this->photoBoothTenant();
        $hash = $this->templateHash($template);
        $existing = BusinessDocument::count();

        $response = $this->docsStore($tenant, ['template_uid' => $template->uid, 'title' => 'Jones photo booth']);

        $document = BusinessDocument::where('title', 'Jones photo booth')->firstOrFail();
        $response->assertRedirect($this->ed('edit', $tenant, $document));
        $this->assertSame($existing + 1, BusinessDocument::count());
        $this->assertSame($tenant['business']->id, $document->business_id);
        $this->assertSame($tenant['contact']->id, $document->contact_id);
        $this->assertSame('draft', $document->status->value);
        $version = $document->versions()->where('state', 'draft')->firstOrFail();
        $this->assertSame($template->fresh()->blocks, $version->content['blocks']);
        $this->assertSame(0, $version->lineItems()->count(), 'the product area is a placeholder; no line, no price');
        $this->assertSame(0, $version->paymentScheduleItems()->count());
        $this->assertArrayNotHasKey('payment_plan', $version->content);
        $this->assertStringNotContainsString($template->uid, json_encode($version->content), 'the document keeps no reference to the platform template');
        $this->assertSame($hash, $this->templateHash($template), 'the platform template is byte-identical after use');

        // The editor shows the layout and the product placeholder (nothing priced yet).
        $boot = $this->pageBootstrap($this->get($this->ed('edit', $tenant, $document))->assertOk()->getContent());
        $this->assertContains('product_list', array_column($boot['blocks'], 'type'));
        $this->assertSame([], $boot['lines']);
        $this->assertNull($boot['plan']);
    }

    public function test_later_platform_edits_reach_new_documents_only_and_never_alter_an_existing_one(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate('Evolving layout');
        $this->assignAndPublish($template, $blueprint);
        $tenant = $this->photoBoothTenant();

        $this->docsStore($tenant, ['template_uid' => $template->uid, 'title' => 'First doc'])->assertRedirect();
        $first = BusinessDocument::where('title', 'First doc')->firstOrFail();
        $firstBlocks = $first->versions()->where('state', 'draft')->firstOrFail()->content['blocks'];

        // The Platform Owner edits the template afterwards.
        $this->asOwner();
        $edited = [['id' => 'new-1', 'type' => 'heading', 'data' => ['level' => 1, 'align' => 'left', 'runs' => [['t' => 'Brand new heading']]]], ['id' => 'new-2', 'type' => 'signature', 'data' => ['label' => 'Sign']]];
        $this->putJson($this->adm('blocks', $template), ['blocks' => $edited, 'expected_lock_version' => (int) $template->fresh()->lock_version])->assertOk();
        $this->authenticateAs($tenant['customer']);

        $this->assertSame([$template->uid], $this->recommendedUids($tenant['business']), 'still recommended, now with the new layout');
        $this->assertSame($firstBlocks, $first->fresh()->versions()->where('state', 'draft')->firstOrFail()->content['blocks'], 'an existing document is unchanged');

        $this->docsStore($tenant, ['template_uid' => $template->uid, 'title' => 'Second doc'])->assertRedirect();
        $second = BusinessDocument::where('title', 'Second doc')->firstOrFail();
        $this->assertSame(['new-1', 'new-2'], array_column($second->versions()->where('state', 'draft')->firstOrFail()->content['blocks'], 'id'));
    }

    public function test_a_business_cannot_use_or_preview_a_platform_template_that_is_not_recommended_to_it(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $assigned = $this->livePlatformTemplate('Assigned');
        $other = $this->livePlatformTemplate('Not assigned');
        $this->assignAndPublish($assigned, $blueprint);
        $tenant = $this->photoBoothTenant();
        $existing = BusinessDocument::count();

        $this->docsStore($tenant, ['template_uid' => $other->uid])->assertNotFound();
        $this->get($this->tpl('preview', $tenant, $other))->assertNotFound();
        $this->get($this->tpl('preview', $tenant, $assigned))->assertOk();

        // Another niche's Business cannot use the assigned one either.
        $home = $this->homeServicesTenant();
        $this->docsStore($home, ['template_uid' => $assigned->uid])->assertNotFound();
        $this->get($this->tpl('preview', $home, $assigned))->assertNotFound();

        $this->assertSame($existing, BusinessDocument::count());
    }

    public function test_a_platform_template_image_never_reaches_a_document(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $template = $this->livePlatformTemplate();
        $this->assignAndPublish($template, $blueprint);
        $tenant = $this->photoBoothTenant();
        $image = $this->ownImage($tenant['business']);

        // Bypass the editor/service and plant an image block directly into the platform row.
        DB::table('document_templates')->where('id', $template->id)->update(['blocks' => json_encode([
            ...$this->platformBlocks(),
            ['id' => 'planted', 'type' => 'image', 'data' => ['catalog_image_uid' => $image->uid, 'alt' => '', 'width_pct' => 100]],
        ])]);

        $this->docsStore($tenant, ['template_uid' => $template->uid, 'title' => 'No images'])->assertRedirect();
        $document = BusinessDocument::where('title', 'No images')->firstOrFail();
        $types = array_column($document->versions()->where('state', 'draft')->firstOrFail()->content['blocks'], 'type');

        $this->assertNotContains('image', $types);
        $this->assertContains('signature', $types);
    }

    public function test_the_recommendation_lookup_has_a_constant_small_query_budget(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $first = $this->livePlatformTemplate('One');
        $this->assignAndPublish($first, $blueprint);
        $tenant = $this->photoBoothTenant();
        $business = $tenant['business']->fresh();
        $service = app(RecommendedPlatformTemplates::class);

        $count = function () use ($service, $business): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $service->forBusiness($business);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $one = $count();

        foreach (['Two', 'Three', 'Four', 'Five'] as $name) {
            $t = $this->livePlatformTemplate($name);
            $this->assignments()->assign($this->owner(), $t, $blueprint);
        }
        $this->assignments()->publishDraft($this->owner(), $blueprint);
        $this->assertCount(5, $service->forBusiness($business));

        $five = $count();

        $this->assertSame($one, $five, 'the number of queries does not grow with the number of templates');
        $this->assertLessThanOrEqual(14, $five, 'resolution + components + templates + the cached entitlement snapshot');
    }

    public function test_an_unrelated_business_pays_for_resolution_only(): void
    {
        $this->seedPhotoBoothBlueprint();
        $home = $this->homeServicesTenant();
        $business = $home['business']->fresh();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(RecommendedPlatformTemplates::class)->forBusiness($business);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(0, $result);
        $this->assertLessThanOrEqual(4, $queries);
    }
}
