<?php

namespace Tests\Feature\Documents\PlatformTemplates;

use App\Library\Documents\Blocks\BlockSchema;
use App\Library\Documents\Blocks\DocumentBlockRenderer;
use App\Library\Documents\Blocks\DocumentMergeFields;
use App\Library\Documents\Templates\PhotoBoothPlatformTemplates;
use App\Models\DocumentTemplate;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Contract 17B §6b - `documents:seed-photo-booth-templates`: four polished
 * platform templates, created once by seed_key, assigned to the photo_booth
 * blueprint through NicheBlueprintPublisher, safe to run any number of times.
 */
class SeedPhotoBoothDocumentTemplatesCommandTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;
    use CreatesDocumentsTestData;
    use CreatesCatalogHttpFixtures;
    use PlatformTemplateTestHelpers;

    private const COMMAND = 'documents:seed-photo-booth-templates';

    private const KEYS = ['photo_booth_proposal', 'photo_booth_wedding_proposal', 'photo_booth_corporate_proposal', 'photo_booth_event_agreement'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRequiredAppConfigRowsExist();
        $this->owner();
    }

    private function run_(): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan(self::COMMAND, ['--actor' => $this->owner()->id]);
    }

    /** @return array<int, string> version number => state */
    private function versionStates(NicheBlueprint $blueprint): array
    {
        return NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->orderBy('version_number')->get()
            ->mapWithKeys(fn ($v) => [(int) $v->version_number => $v->state->value])->all();
    }

    public function test_it_fails_closed_when_the_photo_booth_blueprint_is_not_published(): void
    {
        $this->run_()->assertExitCode(1);

        $this->assertSame(0, DocumentTemplate::query()->count());
    }

    public function test_it_creates_the_four_templates_and_publishes_one_new_blueprint_version(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();

        $this->run_()->assertExitCode(0);

        $templates = DocumentTemplate::query()->whereNotNull('seed_key')->orderBy('id')->get();
        $this->assertSame(self::KEYS, $templates->pluck('seed_key')->all());
        foreach ($templates as $template) {
            $this->assertNull($template->business_id);
            $this->assertSame('active', $template->status->value);
            $this->assertSame(2, (int) $template->schema_version);
        }
        $this->assertSame(['proposal', 'proposal', 'proposal', 'contract'], $templates->map(fn ($t) => $t->template_type->value)->all());
        $this->assertSame(['Photo Booth Proposal', 'Wedding Photo Booth Proposal', 'Corporate Event Proposal', 'Event Agreement'], $templates->pluck('name')->all());

        $this->assertSame([1 => 'superseded', 2 => 'published'], $this->versionStates($blueprint));
        $published = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'published')->firstOrFail();
        $components = NicheBlueprintComponent::query()->where('blueprint_version_id', $published->id)->orderBy('position')->get();
        $this->assertSame(['crm_pipeline', 'document_template', 'document_template', 'document_template', 'document_template'], $components->pluck('component_type')->all());
        $this->assertSame('photo_booth_default_pipeline', $components[0]->component_key, 'the existing pipeline component is kept');
        $this->assertEqualsCanonicalizing($templates->pluck('uid')->all(), $components->slice(1)->map(fn ($c) => $c->payload['template_uid'])->all());
        $this->assertSame(['payments_contracts'], $components->slice(1)->pluck('required_feature_key')->unique()->values()->all());
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $this->run_()->assertExitCode(0);
        $hashes = DocumentTemplate::query()->orderBy('id')->get()->map(fn ($t) => $this->templateHash($t))->all();

        $this->run_()->assertExitCode(0);
        $this->run_()->assertExitCode(0);

        $this->assertSame(4, DocumentTemplate::query()->count());
        $this->assertSame($hashes, DocumentTemplate::query()->orderBy('id')->get()->map(fn ($t) => $this->templateHash($t))->all());
        $this->assertSame([1 => 'superseded', 2 => 'published'], $this->versionStates($blueprint), 'one version bump at most');
        $this->assertSame(5, NicheBlueprintComponent::query()->whereIn('blueprint_version_id', NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'published')->pluck('id'))->count());
    }

    public function test_it_does_not_undo_an_owner_rename_edit_or_disable(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $this->run_()->assertExitCode(0);
        $wedding = DocumentTemplate::query()->where('seed_key', 'photo_booth_wedding_proposal')->firstOrFail();
        $agreement = DocumentTemplate::query()->where('seed_key', 'photo_booth_event_agreement')->firstOrFail();
        $this->templates()->update($wedding, ['name' => 'My renamed wedding layout', 'blocks' => $this->platformBlocks()], (int) $wedding->lock_version, null);
        $this->templates()->disablePlatform($agreement, $this->owner());

        $this->run_()->assertExitCode(0);

        $this->assertSame('My renamed wedding layout', $wedding->fresh()->name);
        $this->assertCount(6, $wedding->fresh()->blocks);
        $this->assertSame('archived', $agreement->fresh()->status->value);
        $this->assertSame(4, DocumentTemplate::query()->count());
        $this->assertSame([1 => 'superseded', 2 => 'published'], $this->versionStates($blueprint));
    }

    public function test_the_shipped_blocks_pass_the_platform_block_schema_and_hold_no_business_data(): void
    {
        $this->seedPhotoBoothBlueprint();
        $this->run_()->assertExitCode(0);

        foreach (DocumentTemplate::query()->whereNotNull('seed_key')->get() as $template) {
            $blocks = $template->blocks;
            $this->assertEquals($blocks, BlockSchema::normalize($blocks, ['allow_images' => false]), $template->name . ' is already normalised');
            $types = array_column($blocks, 'type');

            $this->assertNotContains('image', $types, $template->name);
            $this->assertSame(1, BlockSchema::countOfType($blocks, 'product_list'), $template->name . ': one generic product placeholder');
            $this->assertSame(1, BlockSchema::countOfType($blocks, 'payment_terms'), $template->name);
            $this->assertSame(1, BlockSchema::countOfType($blocks, 'signature'), $template->name);
            $this->assertContains('business_details', $types);
            $this->assertContains('heading', $types);
            $this->assertContains('section', $types);

            foreach ($blocks as $block) {
                if ($block['type'] === 'product_list') {
                    $this->assertEqualsCanonicalizing(['show_description', 'show_quantity'], array_keys($block['data']), 'presentation flags only: no product, price or line');
                }
                foreach ($block['data']['runs'] ?? [] as $run) {
                    if (isset($run['merge'])) {
                        $this->assertTrue(DocumentMergeFields::isAllowed($run['merge']));
                    }
                    $this->assertDoesNotMatchRegularExpression('/[$\x{20AC}\x{00A3}]\s?\d|\b\d{1,2}\/\d{1,2}\/\d{2,4}\b|\b(19|20)\d{2}\b/u', (string) ($run['t'] ?? ''), $template->name . ' states no amount or date');
                }
            }

            $text = collect($blocks)->flatMap(fn ($b) => collect($b['data']['runs'] ?? [])->pluck('t'))->implode(' ');
            $this->assertStringContainsString('not legal advice or a legal opinion', $text, $template->name . ' carries the e-sign record note');
            $this->assertStringNotContainsStringIgnoringCase('legal advice from us', $text);
            $this->assertContains('merge', collect($blocks)->flatMap(fn ($b) => collect($b['data']['runs'] ?? [])->flatMap(fn ($r) => array_keys($r)))->all(), $template->name . ' uses merge tokens');
        }

        $agreement = DocumentTemplate::query()->where('seed_key', 'photo_booth_event_agreement')->firstOrFail();
        $this->assertSame('contract', $agreement->template_type->value);
        $this->assertContains('page_break', array_column($agreement->blocks, 'type'), 'the agreement breaks the page before the signature');
    }

    public function test_the_definitions_are_valid_without_touching_the_database(): void
    {
        $definitions = PhotoBoothPlatformTemplates::normalized();

        $this->assertSame(self::KEYS, array_column($definitions, 'seed_key'));
        $this->assertCount(4, array_unique(array_column($definitions, 'name')));
    }

    public function test_the_seeded_templates_render_through_the_one_renderer_with_sample_data(): void
    {
        $this->seedPhotoBoothBlueprint();
        $this->run_()->assertExitCode(0);
        $this->asOwner();

        foreach (DocumentTemplate::query()->whereNotNull('seed_key')->get() as $template) {
            $html = $this->get($this->adm('preview', $template))->assertOk()->getContent();
            $this->assertStringContainsString('Alex', $html, $template->name);
            $this->assertStringContainsString('Your Business', $html, $template->name);
            $this->assertStringNotContainsString('<img', $html);
        }
        $this->assertNotNull(app(DocumentBlockRenderer::class));
    }

    public function test_after_seeding_a_photo_booth_business_sees_all_four_and_an_unrelated_one_sees_none(): void
    {
        $this->seedPhotoBoothBlueprint();
        $this->run_()->assertExitCode(0);
        $tenant = $this->photoBoothTenant();

        $this->assertCount(4, $this->recommendedUids($tenant['business']));
        $html = $this->get($this->tpl('index', $tenant))->assertOk()->getContent();
        $this->assertSame(4, substr_count($html, 'data-role="recommended-card"'));
        $this->assertStringContainsString('Event Agreement', $html);

        $other = $this->homeServicesTenant();
        $this->assertSame([], $this->recommendedUids($other['business']));
    }

    public function test_it_resumes_its_own_interrupted_draft_without_duplicates_and_keeps_the_pipeline(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        // A previous run that created the templates and ONE assignment, then died before publishing.
        $definitions = PhotoBoothPlatformTemplates::normalized();
        $first = null;
        foreach ($definitions as $definition) {
            $template = new DocumentTemplate(['business_id' => null, 'template_type' => $definition['type'], 'name' => $definition['name'], 'description' => $definition['description'], 'blocks' => $definition['blocks'], 'schema_version' => 2, 'created_by_user_id' => $this->owner()->id]);
            $template->forceFill(['seed_key' => $definition['seed_key'], 'status' => 'active'])->save();
            $first ??= $template;
        }
        $this->assignments()->assign($this->owner(), $first, $blueprint);
        $this->assertSame(['draft', 'published'], array_values(array_map(fn ($s) => $s, collect($this->versionStates($blueprint))->sort()->values()->all())));

        $this->run_()->assertExitCode(0);

        $this->assertSame(4, DocumentTemplate::query()->count());
        $this->assertSame([1 => 'superseded', 2 => 'published'], $this->versionStates($blueprint));
        $published = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'published')->firstOrFail();
        $components = NicheBlueprintComponent::query()->where('blueprint_version_id', $published->id)->get();
        $this->assertCount(5, $components);
        $this->assertCount(4, $components->where('component_type', 'document_template')->pluck('payload.template_uid')->unique());
    }

    public function test_it_completes_a_partially_copied_draft_so_the_pipeline_is_never_dropped(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        // A draft that exists but holds NONE of the live components (the copy was interrupted at the start).
        $this->publisher()->createDraftVersion($this->owner()->id, $blueprint);

        $this->run_()->assertExitCode(0);

        $published = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'published')->firstOrFail();
        $types = NicheBlueprintComponent::query()->where('blueprint_version_id', $published->id)->pluck('component_type')->all();
        $this->assertContains('crm_pipeline', $types);
        $this->assertSame(4, count(array_keys($types, 'document_template')));
    }

    public function test_it_refuses_to_touch_an_operator_authored_draft(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $draft = $this->publisher()->createDraftVersion($this->owner()->id, $blueprint);
        $this->publisher()->addDraftComponent($this->owner()->id, $draft, 'operators_own_pipeline', 'crm_pipeline', 'crm', [
            'template_key' => 'x', 'template_version' => 1, 'pipeline_key' => 'ops', 'name' => 'Ops',
            'stages' => [['name' => 'New Lead', 'semantic_key' => 'new_inquiry']],
        ]);

        $this->run_()->assertExitCode(1);

        $this->assertSame(['draft', 'published'], collect($this->versionStates($blueprint))->sort()->values()->all());
        $this->assertSame(1, NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->count(), 'the operator draft is untouched');
        $this->assertSame(0, NicheBlueprintComponent::query()->where('component_type', 'document_template')->count());
    }

    public function test_the_existing_blueprint_seed_command_keeps_its_semantics(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $this->run_()->assertExitCode(0);

        // blueprint:seed-photo-booth is a no-op once any version is published: it never edits or re-publishes v1.
        $this->artisan('blueprint:seed-photo-booth', ['--actor' => $this->owner()->id])->assertExitCode(0);

        $this->assertSame([1 => 'superseded', 2 => 'published'], $this->versionStates($blueprint));
        $this->assertSame(1, DB::table('niche_blueprints')->where('key', 'photo_booth')->count());
    }

    public function test_a_business_owned_row_squatting_a_seed_key_blocks_the_command(): void
    {
        $this->seedPhotoBoothBlueprint();
        $tenant = $this->photoBoothTenant();
        $squat = $this->plantTemplate($tenant['business'], [], ['name' => 'Squatter']);
        DB::table('document_templates')->where('id', $squat->id)->update(['seed_key' => 'photo_booth_proposal']);

        $this->run_()->assertExitCode(1);

        $this->assertSame(0, DocumentTemplate::query()->whereNull('business_id')->count());
    }
}
