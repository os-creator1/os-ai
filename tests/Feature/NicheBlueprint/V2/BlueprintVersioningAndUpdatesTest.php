<?php

namespace Tests\Feature\NicheBlueprint\V2;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Crm\TagManager;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Library\NicheBlueprint\Workspace\BlueprintUpdateDetector;
use App\Library\NicheBlueprint\Workspace\BlueprintWorkspaceService;
use App\Models\Business;
use App\Models\NicheBlueprint;
use App\Models\SeoCitationDirectory;
use App\Models\SeoNicheCitationRecommendation;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\NicheBlueprint\V2\Support\SeedsPhotoBoothBlueprintV2;
use Tests\TestCase;

class BlueprintVersioningAndUpdatesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPhotoBoothBlueprintV2;

    private NicheBlueprint $blueprint;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blueprint = $this->seedPhotoBoothBlueprintV2();
        $this->adminId = $this->platformAdminId();
    }

    /** Edits one draft component through the Workspace service and publishes the result. */
    private function publishEdit(string $componentKey, array $input): int
    {
        $workspace = app(BlueprintWorkspaceService::class);
        $draft = $workspace->draftFor($this->blueprint, $this->adminId);
        $component = $draft->components()->where('component_key', $componentKey)->firstOrFail();

        $workspace->saveComponent($this->adminId, $draft, $component->component_type, $component, $input);

        return (int) app(NicheBlueprintPublisher::class)->publishVersion($this->adminId, $draft)->version_number;
    }

    public function test_draft_publish_supersede_and_new_businesses_get_the_latest_published_version(): void
    {
        [, $existing] = $this->tenant(WorkspacePlanTier::Growth, 'Existing Booths', 'Existing WS');
        $existingVersion = (int) $this->installationRecords($existing)->min('installed_from_version');

        $newVersion = $this->publishEdit('photo_booth_tags', ['tags' => "Wedding\nBirthday\nCorporate\nVIP Client"]);

        $this->assertGreaterThan($existingVersion, $newVersion);

        [, $fresh] = $this->tenant(WorkspacePlanTier::Growth, 'Fresh Booths', 'Fresh WS');

        $this->assertSame($newVersion, (int) $this->installationRecords($fresh)->min('installed_from_version'));
        $this->assertTrue(Tag::query()->where('business_id', $fresh->id)->where('name', 'VIP Client')->exists());
        $this->assertFalse(Tag::query()->where('business_id', $fresh->id)->where('name', 'Past Client')->exists(), 'The v-latest tag set no longer lists Past Client.');
    }

    public function test_an_existing_business_is_not_overwritten_but_is_told_an_update_is_available(): void
    {
        [, $existing] = $this->tenant(WorkspacePlanTier::Growth, 'Existing Booths', 'Existing WS');
        $tagsBefore = Tag::query()->where('business_id', $existing->id)->pluck('name')->sort()->values()->all();

        $this->publishEdit('photo_booth_tags', ['tags' => "Wedding\nBirthday\nVIP Client"]);

        $this->assertSame($tagsBefore, Tag::query()->where('business_id', $existing->id)->pluck('name')->sort()->values()->all(), 'No destructive overwrite.');

        $report = app(BlueprintUpdateDetector::class)->forBusiness($existing);
        $tagRow = collect($report['components'])->firstWhere('component_key', 'photo_booth_tags');

        $this->assertSame(BlueprintUpdateDetector::UPDATE_AVAILABLE, $tagRow['status']);
        $this->assertFalse($tagRow['owner_modified']);
        $this->assertGreaterThanOrEqual(1, $report['updates_available']);

        $untouched = collect($report['components'])->firstWhere('component_key', 'photo_booth_cf_event_date');
        $this->assertSame(BlueprintUpdateDetector::CURRENT, $untouched['status']);
    }

    public function test_an_owner_customised_component_is_flagged_and_preserved(): void
    {
        [, $existing] = $this->tenant(WorkspacePlanTier::Growth, 'Existing Booths', 'Existing WS');

        $tag = Tag::query()->where('business_id', $existing->id)->where('name', 'Wedding')->firstOrFail();
        app(TagManager::class)->renameTag($existing, $tag, 'Weddings & Elopements');

        $this->publishEdit('photo_booth_tags', ['tags' => "Wedding\nBirthday\nVIP Client"]);

        $report = app(BlueprintUpdateDetector::class)->forBusiness($existing);
        $tagRow = collect($report['components'])->firstWhere('component_key', 'photo_booth_tags');

        $this->assertSame(BlueprintUpdateDetector::UPDATE_AVAILABLE, $tagRow['status']);
        $this->assertTrue($tagRow['owner_modified'], 'Renaming a niche tag is an owner customisation.');
        $this->assertTrue(Tag::query()->where('business_id', $existing->id)->where('name', 'Weddings & Elopements')->exists(), 'The owner\'s rename survives.');
    }

    public function test_provisioning_uses_stable_component_identity_not_names(): void
    {
        [, $existing] = $this->tenant(WorkspacePlanTier::Growth, 'Existing Booths', 'Existing WS');
        $before = $this->installationRecords($existing)->get('photo_booth_cf_event_type');

        // Rename the field's label in the blueprint: same component_key, different descriptor.
        $this->publishEdit('photo_booth_cf_event_type', [
            'label' => 'Type of event', 'type' => 'select', 'options' => "Wedding\nBirthday\nCorporate\nSchool\nOther",
        ]);

        $report = app(BlueprintUpdateDetector::class)->forBusiness($existing);
        $row = collect($report['components'])->firstWhere('component_key', 'photo_booth_cf_event_type');

        $this->assertSame(BlueprintUpdateDetector::UPDATE_AVAILABLE, $row['status'], 'Matched by component_key even though the label changed.');
        $this->assertSame((int) $before->blueprint_id, (int) $row['blueprint_id']);
        $this->assertSame([], $report['new_components'], 'A renamed component is not mistaken for a new one.');
    }

    public function test_a_component_added_in_a_later_version_is_offered_not_installed(): void
    {
        [, $existing] = $this->tenant(WorkspacePlanTier::Growth, 'Existing Booths', 'Existing WS');

        $workspace = app(BlueprintWorkspaceService::class);
        $draft = $workspace->draftFor($this->blueprint, $this->adminId);
        $workspace->saveComponent($this->adminId, $draft, 'crm_custom_field', null, ['label' => 'Rain plan', 'type' => 'text']);
        app(NicheBlueprintPublisher::class)->publishVersion($this->adminId, $draft);

        $report = app(BlueprintUpdateDetector::class)->forBusiness($existing);

        $this->assertCount(1, $report['new_components']);
        $this->assertSame('crm_custom_field', $report['new_components'][0]['component_type']);
        $this->assertFalse(\App\Models\CustomFieldDefinition::query()->where('business_id', $existing->id)->where('label', 'Rain plan')->exists());
    }

    public function test_live_citation_recommendations_propagate_on_publish_and_removed_ones_are_withdrawn(): void
    {
        $niche = 'photo_booth_service';
        $bark = SeoCitationDirectory::query()->where('key', 'bark')->whereNull('business_id')->firstOrFail();
        $this->assertTrue(SeoNicheCitationRecommendation::query()->where('niche_key', $niche)->where('seo_citation_directory_id', $bark->id)->exists());

        $this->publishEdit('photo_booth_citations', ['recommendations' => "weddingwire_the_knot | essential | Start here.\ngigsalad | recommended"]);

        $rows = SeoNicheCitationRecommendation::query()->where('niche_key', $niche)->get();
        $weddingWire = SeoCitationDirectory::query()->where('key', 'weddingwire_the_knot')->whereNull('business_id')->value('id');

        $this->assertSame('essential', $rows->firstWhere('seo_citation_directory_id', $weddingWire)->importance->value);
        $this->assertFalse($rows->contains('seo_citation_directory_id', $bark->id), 'A recommendation the new version dropped is withdrawn.');
        $this->assertGreaterThanOrEqual(2, $rows->count());
    }

    public function test_the_config_seams_read_pinned_copies_and_live_strategy(): void
    {
        [, $existing] = $this->tenant(WorkspacePlanTier::Growth, 'Existing Booths', 'Existing WS');
        $reader = app(BlueprintConfigReader::class);

        $this->assertSame('photo_booth_editorial', $reader->websiteConfig($existing)['template_key']);
        $this->assertNotEmpty($reader->seoStrategy($existing)['keyword_patterns']);

        $workspace = app(BlueprintWorkspaceService::class);
        $draft = $workspace->draftFor($this->blueprint, $this->adminId);
        $site = $draft->components()->where('component_key', 'photo_booth_website')->firstOrFail();
        $workspace->saveComponent($this->adminId, $draft, 'website_config', $site, [
            'template_key' => 'photo_booth_luxury', 'pages' => 'home | Home | hero, cta', 'navigation' => 'Home', 'content_prompts' => '',
        ]);
        $seo = $draft->components()->where('component_key', 'photo_booth_seo')->firstOrFail();
        $workspace->saveComponent($this->adminId, $draft, 'seo_strategy', $seo, [
            'keyword_patterns' => 'photo booth hire {city} | transactional', 'faq_topics' => '', 'schema_types' => 'LocalBusiness', 'schema_notes' => '', 'internal_links' => '',
        ]);
        app(NicheBlueprintPublisher::class)->publishVersion($this->adminId, $draft);

        $this->assertSame('photo_booth_editorial', $reader->websiteConfig($existing)['template_key'], 'COPY: the pinned version never changes under a Business.');
        $this->assertSame('photo booth hire {city}', $reader->seoStrategy($existing)['keyword_patterns'][0]['pattern'], 'LIVE: the SEO strategy follows the latest published version.');

        [, $fresh] = $this->tenant(WorkspacePlanTier::Growth, 'Fresh Booths', 'Fresh WS');
        $this->assertSame('photo_booth_luxury', $reader->websiteConfig($fresh)['template_key']);
    }
}
