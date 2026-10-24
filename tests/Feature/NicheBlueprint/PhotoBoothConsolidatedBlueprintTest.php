<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Entitlement\WorkspacePlanAssigned;
use App\Library\Business\BusinessLocationManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Migration\Ghl\GhlImportPlanner;
use App\Library\Migration\Ghl\GhlImportWriter;
use App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Library\NicheBlueprint\PhotoBoothBusinessPackV3;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use App\Models\User;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\UserRepository;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The production situation, end to end on the isolated test database: four provisioned Photo Booth accounts with imported
 * GHL data and NO blueprint records; the platform's published Photo Booth blueprint holds only the earlier (V2) components.
 * A consolidated version is published through the canonical publisher and installed into all four.
 */
class PhotoBoothConsolidatedBlueprintTest extends TestCase
{
    use RefreshDatabase;

    private int $adminId;

    /** @var array<string, Business> */
    private array $businesses = [];

    private function provisionFour(): void
    {
        $this->adminId = (int) User::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'admin@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin', 'password' => bcrypt('x')])->id;

        // Provisioning happened while nothing listened for the plan event (as in production): the installer never ran.
        Event::fake([WorkspacePlanAssigned::class]);

        foreach (['Kaleb Hill Photography' => 'photographer', 'Booths To The Max' => 'photo_booth_service', 'Transparent Photo Booths' => 'photo_booth_service', 'Greenbelt Photography' => 'photographer'] as $name => $industry) {
            $owner = app(UserRepository::class)->store(['first_name' => $name, 'last_name' => '(agency owner)', 'email' => Str::slug($name).'@example.test', 'password' => Str::password(40), 'status' => true, 'phone' => '', 'is_customer' => true], true);
            $customer = \App\Models\Customer::query()->where('user_id', $owner->id)->firstOrFail();
            $workspace = app(WorkspaceManager::class)->createWorkspace((int) $owner->id, $name);
            $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, ['name' => $name, 'industry' => $industry, 'country_code' => 'US', 'timezone' => 'America/Denver', 'currency_code' => 'USD']);
            $business->forceFill(['status' => BusinessStatus::Active->value, 'activated_at' => now()])->save();
            app(BusinessLocationManager::class)->upsertPrimaryLocation($business, ['name' => 'Primary Location', 'service_mode' => 'service_area', 'country_code' => 'US', 'public_address' => false]);
            app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Growth, $this->adminId, 'test', true, 0);
            $this->businesses[$name] = $business->fresh();
        }
    }

    /** Imported GHL data (the real importer): contacts incl. an opted-out one, the event fields, tags. Greenbelt also has its own pipeline. */
    private function importHistory(): void
    {
        foreach ($this->businesses as $name => $business) {
            $location = $business->locations()->where('is_primary', true)->firstOrFail();
            $plan = (new GhlImportPlanner())->plan([
                'location' => ['id' => 'L-'.$name],
                'customFields' => [['id' => 'cf1', 'name' => 'Event Type'], ['id' => 'cf2', 'name' => 'Event Date']],
                'contacts' => [
                    ['id' => 'C1-'.$name, 'firstNameRaw' => 'Ann', 'phone' => '(720) 555-0101', 'email' => 'ann@example.test', 'tags' => ['inquiry'], 'dnd' => false, 'dateAdded' => '2026-01-02T00:00:00.000Z',
                        'customFields' => [['id' => 'cf1', 'value' => ['Wedding']], ['id' => 'cf2', 'value' => 1794009600000]]],
                    ['id' => 'C2-'.$name, 'firstNameRaw' => 'Stop', 'phone' => '(720) 555-0102', 'dnd' => true, 'tags' => ['past client'], 'dateAdded' => '2026-01-03T00:00:00.000Z'],
                ],
                'conversations' => [['id' => 'V1-'.$name, 'contactId' => 'C1-'.$name]],
                'messages' => ['V1-'.$name => [['id' => 'M1-'.$name, 'messageType' => 'TYPE_SMS', 'direction' => 'outbound', 'dateAdded' => '2026-05-01T10:00:00.000Z', 'body' => 'hi', 'contactId' => 'C1-'.$name, 'status' => 'delivered']]],
            ]);
            app(GhlImportWriter::class)->import($plan, $business, $location, '+17205550100', false);
        }

        // Greenbelt's own imported pipeline (GHL "Marketing Pipeline")
        app(CrmPipelineComponentAdapter::class)->install($this->businesses['Greenbelt Photography'], [
            'template_key' => 'ghl_import', 'template_version' => 1, 'pipeline_key' => 'marketing_pipeline', 'name' => 'Marketing Pipeline',
            'stages' => [['name' => 'New Lead', 'semantic_key' => 'new_inquiry'], ['name' => 'Contacted'], ['name' => 'Closed']],
        ], null);
    }

    private function importedState(Business $b): array
    {
        return [
            'contacts' => DB::table('contacts')->where('business_id', $b->id)->orderBy('id')->get(['id', 'phone', 'status'])->map(fn ($r) => (array) $r)->all(),
            'values' => DB::table('custom_field_values')->where('business_id', $b->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'definitions' => DB::table('custom_field_definitions')->where('business_id', $b->id)->orderBy('id')->get(['id', 'label', 'type'])->map(fn ($r) => (array) $r)->all(),
            'pipelines' => DB::table('crm_pipelines as p')->where('p.business_id', $b->id)->where('p.name', 'Marketing Pipeline')->get(['id', 'name'])->map(fn ($r) => (array) $r)->all(),
            'chat' => DB::table('chat_boxes')->where('business_id', $b->id)->count(),
            'ledger' => DB::table('external_import_records')->where('business_id', $b->id)->count(),
        ];
    }

    public function test_consolidated_blueprint_installs_cleanly_into_the_four_existing_accounts(): void
    {
        Mail::fake();
        Http::fake(fn () => Http::response('blocked', 503));

        $this->provisionFour();
        Artisan::call('db:seed', ['--class' => WebsiteTemplateSeeder::class, '--force' => true]);
        Artisan::call('blueprint:seed-photo-booth');
        Artisan::call('blueprint:seed-photo-booth-v2');
        $blueprint = NicheBlueprint::query()->where('key', 'photo_booth')->firstOrFail();
        $before = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'published')->firstOrFail();
        $beforeKeys = NicheBlueprintComponent::query()->where('blueprint_version_id', $before->id)->orderBy('component_key')->pluck('component_key')->all();
        $beforeRows = NicheBlueprintComponent::query()->where('blueprint_version_id', $before->id)->orderBy('id')->get()->map(fn ($c) => $c->only(['component_key', 'payload']))->all();

        $this->importHistory();
        $imported = array_map(fn ($b) => $this->importedState($b), $this->businesses);
        foreach ($this->businesses as $b) {
            $this->assertSame(0, BusinessBlueprintComponentInstallation::query()->where('business_id', $b->id)->count(), 'Production precondition: no installation records.');
        }
        $this->assertContains('photo_booth_form_availability', $beforeKeys);
        $this->assertNotContains('pb3_form_lead', $beforeKeys);

        // ---- publish the consolidated version through the canonical publisher
        $this->assertSame(0, Artisan::call('blueprint:seed-photo-booth-v3', ['--consolidate' => true, '--actor' => $this->adminId]));
        $published = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'published')->firstOrFail();
        $this->assertSame($before->version_number + 1, $published->version_number);

        // the previously published version is untouched (state aside) and still holds exactly what it did
        $old = NicheBlueprintVersion::query()->whereKey($before->id)->firstOrFail();
        $this->assertNotSame('published', $old->state->value ?? (string) $old->state);
        $this->assertSame($beforeRows, NicheBlueprintComponent::query()->where('blueprint_version_id', $before->id)->orderBy('id')->get()->map(fn ($c) => $c->only(['component_key', 'payload']))->all(), 'Published rows are immutable.');

        $keys = NicheBlueprintComponent::query()->where('blueprint_version_id', $published->id)->pluck('component_key')->all();
        foreach (PhotoBoothBusinessPackV3::SUPERSEDED_EARLIER_KEYS as $gone) {
            $this->assertNotContains($gone, $keys, "{$gone} is superseded and must not be in the consolidated version.");
        }
        foreach (array_column(PhotoBoothBusinessPackV3::components(), 'key') as $v3) {
            $this->assertContains($v3, $keys, "{$v3} missing from the consolidated version.");
        }
        foreach (['photo_booth_booking_consult', 'photo_booth_package_classic', 'photo_booth_package_premium', 'photo_booth_addon_backdrop', 'photo_booth_addon_extra_hour', 'photo_booth_website', 'photo_booth_seo', 'photo_booth_citations', 'photo_booth_cf_venue', 'photo_booth_cf_guests', 'photo_booth_tags'] as $kept) {
            $this->assertContains($kept, $keys, "{$kept} is useful and non-conflicting: it must be kept.");
        }

        // ---- production procedure: align industry, then install
        foreach (['Kaleb Hill Photography', 'Greenbelt Photography'] as $name) {
            app(BusinessRepository::class)->update($this->businesses[$name], ['industry' => 'photo_booth_service']);
        }
        $installer = app(NicheBlueprintInstaller::class);
        foreach ($this->businesses as $name => $b) {
            $result = $installer->installForBusiness($b->fresh());
            $this->assertFalse($result->wasAborted(), "{$name}: ".json_encode($result));
            $this->assertSame(0, $result->failed, "{$name}: a component failed");
            $this->assertSame(count($keys), $result->installed + $result->alreadyDecided + $result->skippedUnavailable + $result->skippedUnentitled, "{$name}: every component decided ".json_encode($result));
        }

        foreach ($this->businesses as $name => $b) {
            $records = BusinessBlueprintComponentInstallation::query()->where('business_id', $b->id)->get();
            // Package templates are decided by the installer without a per-business record (they are platform templates, not Business rows).
            $unrecorded = array_values(array_diff($keys, $records->pluck('component_key')->all()));
            sort($unrecorded);
            $this->assertSame(['photo_booth_addon_backdrop', 'photo_booth_addon_extra_hour', 'photo_booth_package_classic', 'photo_booth_package_premium'], $unrecorded, "{$name}: only the package templates carry no record");
            $this->assertCount(count($keys) - 4, $records, "{$name}: one record per recorded component");
            $this->assertSame([], $records->filter(fn ($r) => ($r->state->value ?? (string) $r->state) !== 'installed')->pluck('component_key')->all(), "{$name}: every component installed (zero failed/skipped)");
            $this->assertSame([$published->version_number], $records->pluck('installed_from_version')->unique()->values()->all());

            // no duplicates anywhere
            $this->assertSame(['Check Availability', 'Event questionnaire'], DB::table('forms')->where('business_id', $b->id)->orderBy('name')->pluck('name')->all(), "{$name}: forms");
            $pipelines = DB::table('crm_pipelines')->where('business_id', $b->id)->pluck('name')->sort()->values()->all();
            $this->assertSame($name === 'Greenbelt Photography' ? ['Bookings', 'Inquiries', 'Marketing Pipeline'] : ['Bookings', 'Inquiries'], $pipelines, "{$name}: pipelines (no Sales pipeline)");
            $labels = DB::table('custom_field_definitions')->where('business_id', $b->id)->pluck('label');
            $this->assertSame($labels->count(), $labels->map(fn ($l) => mb_strtolower($l))->unique()->count(), "{$name}: duplicate custom field labels");
            $this->assertSame('multi_select', DB::table('custom_field_definitions')->where('business_id', $b->id)->where('label', 'Event type')->value('type'), "{$name}: Event type keeps the imported multi-select");
            $tags = DB::table('tags')->where('business_id', $b->id)->pluck('normalized_name');
            $this->assertSame($tags->count(), $tags->unique()->count(), "{$name}: duplicate tags");
            $wf = DB::table('automation_workflows')->where('business_id', $b->id)->pluck('name');
            $this->assertCount(count(PhotoBoothBusinessPackV3::workflows()), $wf, "{$name}: only the 11 V3 automations");
            $this->assertSame(0, DB::table('automation_workflows')->where('business_id', $b->id)->whereNotNull('published_version_id')->count(), "{$name}: all drafts");

            // bindings
            $lead = DB::table('forms')->where('business_id', $b->id)->where('name', 'Check Availability')->value('id');
            $questionnaire = DB::table('forms')->where('business_id', $b->id)->where('name', 'Event questionnaire')->value('id');
            $trigger = fn (string $wfName) => json_decode((string) DB::table('automation_workflow_versions as v')->join('automation_workflows as w', 'w.id', '=', 'v.workflow_id')->where('w.business_id', $b->id)->where('w.name', $wfName)->where('v.state', 'draft')->value('v.definition'), true)['root']['config'];
            $this->assertSame((int) $lead, (int) $trigger('New Inquiry Received')['form_id'], "{$name}: New Inquiry binds to the V3 lead form");
            $this->assertSame((int) $questionnaire, (int) $trigger('Questionnaire Submitted')['form_id'], "{$name}: Questionnaire Submitted binds to the questionnaire");
            $proposal = DB::table('crm_pipeline_stages')->where('business_id', $b->id)->where('name', 'Proposal Sent')->first();
            $this->assertSame((int) $proposal->id, (int) $trigger('Proposal Follow-Up')['to_stage_id'], "{$name}: stage workflow binds to the V3 stage");
            $this->assertSame(1, DB::table('crm_pipeline_stages as s')->join('crm_pipelines as p', 'p.id', '=', 's.pipeline_id')->where('s.business_id', $b->id)->where('p.name', 'Inquiries')->where('s.name', 'Proposal Sent')->count());

            // inert
            $this->assertSame(0, DB::table('booking_types')->join('business_locations', 'business_locations.id', '=', 'booking_types.business_location_id')->where('business_locations.business_id', $b->id)->where('booking_types.is_active', true)->count(), "{$name}: booking types inactive");
            $this->assertGreaterThan(0, DB::table('booking_types')->join('business_locations', 'business_locations.id', '=', 'booking_types.business_location_id')->where('business_locations.business_id', $b->id)->count(), "{$name}: booking type installed");
            $this->assertSame(0, DB::table('business_messaging_operations')->where('business_id', $b->id)->count());
            $this->assertSame(0, DB::table('business_messaging_identities')->where('business_id', $b->id)->count());
            $this->assertSame(0, DB::table('automation_enrollments')->where('business_id', $b->id)->count());
            $this->assertSame(0, DB::table('websites')->where('business_id', $b->id)->count(), "{$name}: the blueprint's website configuration builds no website");
        }
        Mail::assertNothingSent();

        // imported data: byte-for-byte what it was (values, consents/opt-outs, Greenbelt's pipeline)
        foreach ($this->businesses as $name => $b) {
            $after = $this->importedState($b);
            $this->assertSame($imported[$name]['contacts'], $after['contacts'], "{$name}: contacts + opt-out status unchanged");
            $this->assertSame($imported[$name]['values'], $after['values'], "{$name}: custom field values unchanged");
            $this->assertSame($imported[$name]['definitions'], array_slice($after['definitions'], 0, count($imported[$name]['definitions'])), "{$name}: imported field definitions unchanged");
            $this->assertSame($imported[$name]['pipelines'], $after['pipelines'], "{$name}: Marketing Pipeline untouched");
            $this->assertSame($imported[$name]['chat'], $after['chat']);
            $this->assertSame($imported[$name]['ledger'], $after['ledger']);
            $this->assertSame(1, DB::table('contacts')->where('business_id', $b->id)->where('status', 'unsubscribe')->count(), "{$name}: the opt-out is still an opt-out");
        }

        // idempotent second installation
        $snapshot = fn () => [DB::table('business_blueprint_component_installations')->count(), DB::table('crm_pipelines')->count(), DB::table('forms')->count(), DB::table('automation_workflows')->count(), DB::table('custom_field_definitions')->count(), DB::table('tags')->count(), DB::table('booking_types')->count()];
        $first = $snapshot();
        foreach ($this->businesses as $b) {
            $again = $installer->installForBusiness($b->fresh());
            $this->assertSame(0, $again->installed);
            $this->assertSame(0, $again->failed);
        }
        $this->assertSame($first, $snapshot(), 'A second installation creates nothing.');

        // future accounts: the consolidated version is what a NEW Photo Booth business resolves to
        $resolved = $installer->resolveBlueprint($this->businesses['Booths To The Max']->fresh());
        $this->assertSame((int) $blueprint->id, (int) $resolved->id);
    }

    public function test_consolidation_is_a_no_op_when_run_again(): void
    {
        $this->provisionFour();
        Artisan::call('db:seed', ['--class' => WebsiteTemplateSeeder::class, '--force' => true]);
        Artisan::call('blueprint:seed-photo-booth');
        Artisan::call('blueprint:seed-photo-booth-v2');
        Artisan::call('blueprint:seed-photo-booth-v3', ['--consolidate' => true, '--actor' => $this->adminId]);
        $versions = NicheBlueprintVersion::query()->count();

        Artisan::call('blueprint:seed-photo-booth-v3', ['--consolidate' => true, '--actor' => $this->adminId]);

        $this->assertSame($versions, NicheBlueprintVersion::query()->count(), 'Nothing new is published the second time.');
    }
}
