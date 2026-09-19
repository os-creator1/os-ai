<?php

namespace Tests\Feature\NicheBlueprint;

use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Adapters\InstalledComponentReference;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\Business;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 20 §12.B / Acceptance Matrix — "A new Blueprint
 * version is published without touching any live Business until it opts in."
 *
 * THIS IS THE LOAD-BEARING SOURCE BOUNDARY OF SUB-SLICE B. Publishing defines
 * PLATFORM content. If it could reach a Business-owned table, Addendum §16's
 * "MUST NEVER be silently installed or activated into an existing Business"
 * would be violated at the one moment an operator is least likely to notice.
 *
 * Rather than trusting a reading of the code, this captures the actual query
 * log of a real publish and asserts on the tables written.
 */
class NicheBlueprintPublishBoundaryTest extends TestCase
{
    use CreatesBusinessTestData;
    use RefreshDatabase;

    /**
     * Every Business-owned or target-module table a Blueprint could ever
     * install into, plus the installation record itself. Publishing must
     * write to none of them.
     */
    private const BUSINESS_OWNED_TABLES = [
        'business_blueprint_component_installations',
        'businesses',
        'business_locations',
        'crm_pipelines',
        'crm_pipeline_stages',
        'crm_opportunities',
        'catalog_items',
        'catalog_item_location_overrides',
        'package_snapshots',
        'automations',
        'automation_workflows',
        'automation_workflow_versions',
        'websites',
        'website_pages',
        'website_revisions',
        'contacts',
        'chat_boxes',
        'business_knowledge_profiles',
        'workspace_plan_assignments',
        'workspace_entitlement_transitions',
        'business_usage_wallets',
    ];

    private NicheBlueprintPublisher $publisher;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        app(BlueprintComponentAdapterRegistry::class)->register(
            new class implements BlueprintComponentAdapter
            {
                public function componentType(): string
                {
                    return 'crm_pipeline';
                }

                public function validateDescriptor(array $payload): void
                {
                }

                public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
                {
                    return new InstalledComponentReference('crm_pipeline', 1);
                }
            }
        );

        $this->publisher = app(NicheBlueprintPublisher::class);

        $this->adminId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Admin', 'last_name' => 'User',
            'email' => 'admin' . uniqid() . '@example.test', 'status' => true, 'is_admin' => true,
            'is_customer' => false, 'active_portal' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function draftWithComponent(NicheBlueprint $blueprint, string $key = 'photo_booth_default_pipeline'): NicheBlueprintVersion
    {
        $draft = $this->publisher->createDraftVersion($this->adminId, $blueprint);
        $this->publisher->addDraftComponent($this->adminId, $draft, $key, 'crm_pipeline', 'crm', ['pipeline_key' => 'sales']);

        return $draft;
    }

    /**
     * @return list<string> the tables written to by $operation
     */
    private function tablesWrittenBy(callable $operation): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $operation();
        } finally {
            DB::disableQueryLog();
        }

        $written = [];

        foreach (DB::getQueryLog() as $entry) {
            $sql = strtolower($entry['query']);

            if (! preg_match('/^\s*(insert|update|delete|replace|truncate)\b/', $sql)) {
                continue;
            }

            foreach (self::BUSINESS_OWNED_TABLES as $table) {
                if (str_contains($sql, '`' . $table . '`') || str_contains($sql, ' ' . $table . ' ')) {
                    $written[] = $table . ' :: ' . $entry['query'];
                }
            }
        }

        return $written;
    }

    public function test_publishing_writes_to_no_business_owned_table(): void
    {
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
        $blueprint = $this->publisher->createBlueprint($this->adminId, 'photo_booth', 'Photo Booth');
        $draft = $this->draftWithComponent($blueprint);

        $written = $this->tablesWrittenBy(fn () => $this->publisher->publishVersion($this->adminId, $draft));

        $this->assertSame(
            [],
            $written,
            "Publishing must write to no Business-owned table. Offending writes:\n" . implode("\n", $written)
        );

        // And the Business itself is genuinely untouched.
        $this->assertSame(0, DB::table('business_blueprint_component_installations')
            ->where('business_id', $business->id)->count());
        $this->assertSame(0, DB::table('crm_pipelines')->where('business_id', $business->id)->count());
    }

    /**
     * §6.2 states no minimum component count, so an empty draft publishes
     * normally — and must still touch nothing a Business owns.
     */
    public function test_publishing_an_empty_draft_writes_to_no_business_owned_table(): void
    {
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
        $blueprint = $this->publisher->createBlueprint($this->adminId, 'photo_booth', 'Photo Booth');
        $emptyDraft = $this->publisher->createDraftVersion($this->adminId, $blueprint);

        $written = $this->tablesWrittenBy(fn () => $this->publisher->publishVersion($this->adminId, $emptyDraft));

        $this->assertSame(
            [],
            $written,
            "Publishing an empty draft must write to no Business-owned table:\n" . implode("\n", $written)
        );

        $this->assertSame('published', DB::table('niche_blueprint_versions')->where('id', $emptyDraft->id)->value('state'));
        $this->assertSame(0, DB::table('business_blueprint_component_installations')
            ->where('business_id', $business->id)->count());
    }

    public function test_publishing_a_second_version_writes_to_no_business_owned_table(): void
    {
        $business = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
        $blueprint = $this->publisher->createBlueprint($this->adminId, 'photo_booth', 'Photo Booth');

        $v1 = $this->draftWithComponent($blueprint);
        $this->publisher->publishVersion($this->adminId, $v1);

        // The supersede-plus-publish transition is the case an operator would
        // most expect to "update" existing Businesses. It must not.
        $v2 = $this->draftWithComponent($blueprint, 'a_newly_added_component');

        $written = $this->tablesWrittenBy(fn () => $this->publisher->publishVersion($this->adminId, $v2));

        $this->assertSame([], $written, "Republishing must not reach a live Business:\n" . implode("\n", $written));
        $this->assertSame(0, DB::table('business_blueprint_component_installations')
            ->where('business_id', $business->id)->count());
    }

    public function test_every_authoring_operation_writes_to_no_business_owned_table(): void
    {
        $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        $written = $this->tablesWrittenBy(function (): void {
            $blueprint = $this->publisher->createBlueprint($this->adminId, 'photo_booth', 'Photo Booth');
            $this->publisher->updateBlueprintIdentity($this->adminId, $blueprint, ['display_name' => 'Renamed']);
            $this->publisher->deactivateBlueprint($this->adminId, $blueprint);
            $this->publisher->activateBlueprint($this->adminId, $blueprint);

            $draft = $this->publisher->createDraftVersion($this->adminId, $blueprint);
            $this->publisher->updateDraftNotes($this->adminId, $draft, 'release note');

            $component = $this->publisher->addDraftComponent(
                $this->adminId, $draft, 'photo_booth_default_pipeline', 'crm_pipeline', 'crm', ['pipeline_key' => 'sales']
            );
            $this->publisher->updateDraftComponent($this->adminId, $component, ['position' => 3]);

            $spare = $this->publisher->addDraftComponent(
                $this->adminId, $draft, 'spare_component', 'crm_pipeline', 'crm', []
            );
            $this->publisher->removeDraftComponent($this->adminId, $spare);

            $published = $this->publisher->publishVersion($this->adminId, $draft);
            $this->publisher->supersede($this->adminId, $published);
        });

        $this->assertSame([], $written, "No authoring operation may touch a Business:\n" . implode("\n", $written));
    }

    /**
     * §12.B — publishing dispatches no installation job. Sub-slice C's
     * installer does not exist yet; this pins the boundary so adding it later
     * cannot quietly wire a dispatch into the publish path.
     */
    public function test_publishing_dispatches_no_job(): void
    {
        Queue::fake();

        $blueprint = $this->publisher->createBlueprint($this->adminId, 'photo_booth', 'Photo Booth');
        $draft = $this->draftWithComponent($blueprint);

        $this->publisher->publishVersion($this->adminId, $draft);

        Queue::assertNothingPushed();
    }

    /**
     * THE STRUCTURAL TRIPWIRE. The publisher is the sole production write seam
     * for the three Blueprint authoring tables; if anything else can write
     * them, the draft-only rule and the platform-administrator gate both
     * quietly become advisory.
     *
     * A previous revision only scanned for `DB::table('niche_blueprint_*')`,
     * which did NOT prove the claimed boundary: a second service could write
     * `NicheBlueprintVersion::create(...)` or `$component->save()` through
     * Eloquent and never contain the string `DB::table` at all. This scans for
     * BOTH shapes.
     *
     * This is a structural tripwire, not a static analyzer, and it is scoped
     * to match the enforcement strength the contract actually claims: it fires
     * when a new production class reaches for a Blueprint authoring write, so
     * that adding one is a deliberate, visible decision rather than an
     * accident. Reads, relations, casts, the model definitions themselves and
     * the authorized publisher are all exempt.
     */
    public function test_the_publisher_is_the_only_production_writer_of_blueprint_authoring_state(): void
    {
        $tables = ['niche_blueprints', 'niche_blueprint_versions', 'niche_blueprint_components'];
        $models = ['NicheBlueprint', 'NicheBlueprintVersion', 'NicheBlueprintComponent'];

        // Write verbs that actually persist. `query()`, `where()`, `find()`,
        // `first()`, `get()` and relation accessors are reads and are absent
        // here on purpose.
        $writeVerbs = 'create|forceCreate|insert|insertGetId|updateOrCreate|firstOrCreate|update|save|forceFill|delete|forceDelete|truncate|upsert|increment|decrement|push';

        $exemptPathFragments = [
            // The authorized writer.
            '/Library/NicheBlueprint/NicheBlueprintPublisher.php',
            // The model definitions themselves: `$fillable`, `$casts`,
            // relations and scopes are declarations, not writes.
            '/Models/NicheBlueprint.php',
            '/Models/NicheBlueprintVersion.php',
            '/Models/NicheBlueprintComponent.php',
        ];

        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            foreach ($exemptPathFragments as $exempt) {
                if (str_contains($path, $exempt)) {
                    continue 2;
                }
            }

            $contents = (string) file_get_contents($file->getPathname());
            $relative = 'app' . explode('/app', $path, 2)[1];

            // (a) Raw query-builder writes against the tables.
            foreach ($tables as $table) {
                if (preg_match('/DB::table\(\s*[\'"]' . $table . '[\'"]\s*\)((?!;).)*->\s*(' . $writeVerbs . ')\s*\(/s', $contents)) {
                    $offenders[] = $relative . ' — query-builder write to ' . $table;
                }
            }

            // (b) Eloquent writes against the three authoring models, in both
            //     the static (`Model::create(...)`) and instance
            //     (`$version->save()`) shapes.
            foreach ($models as $model) {
                if (preg_match('/\b' . $model . '::\s*(' . $writeVerbs . ')\s*\(/', $contents)) {
                    $offenders[] = $relative . ' — static Eloquent write via ' . $model . '::';
                }

                // `new NicheBlueprintVersion()` followed anywhere in the file
                // by a persisting call is the instance shape.
                if (
                    preg_match('/new\s+' . $model . '\s*\(/', $contents)
                    && preg_match('/->\s*(save|forceFill|update|delete|forceDelete|push)\s*\(/', $contents)
                ) {
                    $offenders[] = $relative . ' — instantiates ' . $model . ' and persists it';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Only NicheBlueprintPublisher may write Blueprint authoring state:\n" . implode("\n", $offenders)
        );
    }

    /**
     * Proves the tripwire above actually trips. A boundary test that cannot
     * fail is worse than no boundary test, because it reads as protection
     * while providing none — so this writes two realistic bypass files into a
     * temporary tree, scans it with the same logic, and asserts both are
     * caught.
     */
    public function test_the_sole_writer_tripwire_catches_both_bypass_shapes(): void
    {
        $dir = sys_get_temp_dir() . '/blueprint_tripwire_' . uniqid();
        mkdir($dir, 0o777, true);

        file_put_contents($dir . '/RawQueryBypass.php', <<<'PHP'
        <?php
        class RawQueryBypass {
            public function go(): void {
                DB::table('niche_blueprint_versions')->where('id', 1)->update(['state' => 'published']);
            }
        }
        PHP);

        file_put_contents($dir . '/EloquentBypass.php', <<<'PHP'
        <?php
        class EloquentBypass {
            public function go(): void {
                NicheBlueprintComponent::create(['component_key' => 'sneaked']);
            }
        }
        PHP);

        file_put_contents($dir . '/InstanceBypass.php', <<<'PHP'
        <?php
        class InstanceBypass {
            public function go(): void {
                $v = new NicheBlueprintVersion();
                $v->forceFill(['state' => 'published'])->save();
            }
        }
        PHP);

        // A read-only file must NOT be flagged.
        file_put_contents($dir . '/InnocentReader.php', <<<'PHP'
        <?php
        class InnocentReader {
            public function go(): array {
                return NicheBlueprintVersion::query()->where('state', 'published')->get()->all();
            }
        }
        PHP);

        $caught = $this->scanDirectoryForBlueprintWrites($dir);

        array_map('unlink', glob($dir . '/*.php'));
        rmdir($dir);

        $this->assertContains('RawQueryBypass.php — query-builder write to niche_blueprint_versions', $caught);
        $this->assertContains('EloquentBypass.php — static Eloquent write via NicheBlueprintComponent::', $caught);
        $this->assertContains('InstanceBypass.php — instantiates NicheBlueprintVersion and persists it', $caught);

        foreach ($caught as $entry) {
            $this->assertStringNotContainsString('InnocentReader', $entry, 'A read-only file must never be flagged.');
        }
    }

    /**
     * The same detection logic as the production scan above, factored so the
     * self-test can exercise it against a controlled fixture tree.
     *
     * @return list<string>
     */
    private function scanDirectoryForBlueprintWrites(string $directory): array
    {
        $tables = ['niche_blueprints', 'niche_blueprint_versions', 'niche_blueprint_components'];
        $models = ['NicheBlueprint', 'NicheBlueprintVersion', 'NicheBlueprintComponent'];
        $writeVerbs = 'create|forceCreate|insert|insertGetId|updateOrCreate|firstOrCreate|update|save|forceFill|delete|forceDelete|truncate|upsert|increment|decrement|push';

        $found = [];

        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);
            $name = basename($file);

            foreach ($tables as $table) {
                if (preg_match('/DB::table\(\s*[\'"]' . $table . '[\'"]\s*\)((?!;).)*->\s*(' . $writeVerbs . ')\s*\(/s', $contents)) {
                    $found[] = $name . ' — query-builder write to ' . $table;
                }
            }

            foreach ($models as $model) {
                if (preg_match('/\b' . $model . '::\s*(' . $writeVerbs . ')\s*\(/', $contents)) {
                    $found[] = $name . ' — static Eloquent write via ' . $model . '::';
                }

                if (
                    preg_match('/new\s+' . $model . '\s*\(/', $contents)
                    && preg_match('/->\s*(save|forceFill|update|delete|forceDelete|push)\s*\(/', $contents)
                ) {
                    $found[] = $name . ' — instantiates ' . $model . ' and persists it';
                }
            }
        }

        return $found;
    }
}
