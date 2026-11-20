<?php

namespace Tests\Feature\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Library\AgencyOutreach\Import\ProspectFieldMapper;
use App\Library\AgencyOutreach\Import\ProspectRowNormalizer;
use App\Library\AgencyOutreach\OutreachExistingClients;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectImportBatch;
use App\Models\AgencyProspectMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

class OutreachSmartImportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    private const GOOGLE = <<<'CSV'
name,phone,primary category,rating,review amount,address,link,lcr4fd href,website or no website,"hours, opening hours"
Snap Happy Booths,+1 (720) 664-0969,Photo booth,4.9,87,"12 Main St, Denver, CO 80202",https://www.google.com/maps/place/snap,https://snaphappy.example.com,Yes,Open 9am-5pm
Glow Booth Denver,+1 (720) 555-0101,Photo booth,4.8,41,,https://www.google.com/maps/place/glow,https://www.instagram.com/glowbooth,No,
Glow Booth Denver Dup,+1 (720) 555-0101,Photo booth,4.8,41,,,,,
No Phone Events,,Party equipment rental,4.4,21,,,,No,
Mile High Mirror,+1 (303) 555-0177,Photo booth,9.1,abc,,,,Yes,"55 Oak Avenue, Boulder, CO 80301"
,,,,,,,,,
CSV;

    private function upload(array $a, string $csv, string $kind = 'prospects')
    {
        return $this->post($this->outreachRoute('prospects.import.upload', $a['workspace']), [
            'kind' => $kind,
            'file' => UploadedFile::fake()->createWithContent('google-2026-11-20.csv', $csv),
        ]);
    }

    private function batch(array $a): AgencyProspectImportBatch
    {
        return AgencyProspectImportBatch::where('workspace_id', $a['workspace']->id)->latest('id')->firstOrFail();
    }

    public function test_google_maps_headers_are_matched_automatically(): void
    {
        $headers = ['name', 'phone', 'primary category', 'rating', 'review amount', 'address', 'link', 'lcr4fd href', 'website or no website', 'hours, opening hours'];
        $m = ProspectFieldMapper::detect($headers, [])['mapping'];

        $this->assertSame('business_name', $m['name']);
        $this->assertSame('phone', $m['phone']);
        $this->assertSame('business_type', $m['primary category']);
        $this->assertSame('rating', $m['rating']);
        $this->assertSame('review_count', $m['review amount']);
        $this->assertSame('address', $m['address']);
        $this->assertSame('maps_url', $m['link']);
        $this->assertSame('website', $m['lcr4fd href']);
        $this->assertSame('has_website', $m['website or no website']);
        $this->assertSame('opening_hours', $m['hours, opening hours']);

        $crm = ProspectFieldMapper::detect(['business_name', 'company', 'phone_number', 'mobile', 'category', 'industry', 'city', 'state', 'reviews', 'website_url', 'source'], [])['mapping'];
        $this->assertSame('business_name', $crm['business_name']);
        $this->assertSame('phone', $crm['phone_number']);
        $this->assertSame('business_type', $crm['category']);
        $this->assertSame('review_count', $crm['reviews']);
        $this->assertSame('website', $crm['website_url']);
        $this->assertNull($crm['company'] === 'business_name' ? 'x' : null, 'a second name-like column is left for the user, not silently double-mapped');
    }

    public function test_row_normaliser_flags_social_pages_misplaced_addresses_and_bad_values(): void
    {
        $map = ['n' => 'business_name', 'p' => 'phone', 'r' => 'rating', 'rv' => 'review_count', 'w' => 'website', 'h' => 'opening_hours', 'a' => 'address'];
        $n = ProspectRowNormalizer::normalize(['n' => 'X', 'p' => '(303) 555-0177', 'r' => '9', 'rv' => 'abc', 'w' => 'https://instagram.com/x', 'h' => '55 Oak Avenue, Boulder, CO 80301', 'a' => ''], $map, null);

        $this->assertNull($n['phone'], 'No country code and no confirmed region: never guessed.');
        $this->assertContains('needs_region', $n['issues']);
        $this->assertContains('invalid_rating', $n['issues']);
        $this->assertContains('invalid_review_count', $n['issues']);
        $this->assertContains('social_as_website', $n['issues']);
        $this->assertContains('address_in_hours', $n['issues']);
        $this->assertNull($n['values']['website']);
        $this->assertSame('https://instagram.com/x', $n['values']['social_url']);
        $this->assertFalse($n['values']['has_website']);
        $this->assertSame('55 Oak Avenue, Boulder, CO 80301', $n['values']['address']);
        $this->assertSame('Boulder', $n['values']['city']);
        $this->assertSame('CO', $n['values']['state']);

        $us = ProspectRowNormalizer::normalize(['n' => 'X', 'p' => '(303) 555-0177'], ['n' => 'business_name', 'p' => 'phone'], 'US');
        $this->assertSame('13035550177', $us['phone']);

        // A meaningful national prefix is kept (Italian geographic numbers keep their 0).
        $it = ProspectRowNormalizer::normalize(['n' => 'X', 'p' => '02 1234 5678'], ['n' => 'business_name', 'p' => 'phone'], 'IT');
        $this->assertSame('390212345678', $it['phone']);
    }

    public function test_upload_analyses_the_file_and_importing_never_sends_anything(): void
    {
        $a = $this->outreachAgency('Imp');
        $this->authenticateAs($a['customer']);
        Queue::fake();
        Bus::fake();
        $before = AgencyProspectMessage::count();

        $this->upload($a, self::GOOGLE)->assertRedirect();
        $batch = $this->batch($a);
        $s = $batch->summary;

        $this->assertSame(6, $s['rows']);
        $this->assertSame(1, $s['outcomes']['empty']);
        $this->assertSame(1, $s['outcomes']['duplicate']);
        $this->assertSame(1, $s['outcomes']['missing_phone']);
        $this->assertSame(2, $s['outcomes']['new'] + $s['outcomes']['existing'] - 1, 'three phoned unique rows, minus none existing');
        $this->assertSame('business_name', $batch->mapping['name']);
        $this->assertArrayHasKey('social_as_website', $s['issues']);
        $this->assertArrayHasKey('invalid_rating', $s['issues']);

        // The review screen renders with editable selectors and the diagnostics.
        $this->get($this->outreachRoute('prospects.import.review', $a['workspace'], [$batch->uid]))
            ->assertOk()->assertSee('data-role="import-issues"', false)->assertSee('name="mapping[0]"', false)->assertSee('Check fields');

        $this->post($this->outreachRoute('prospects.import.commit', $a['workspace'], [$batch->uid]), ['group_name' => 'Grp ' . uniqid()])->assertRedirect();

        $this->assertSame($before, AgencyProspectMessage::count(), 'Importing a file never creates or sends a message.');
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();

        $snap = AgencyProspect::where('workspace_id', $a['workspace']->id)->where('phone', '17206640969')->firstOrFail();
        $this->assertSame('Snap Happy Booths', $snap->company_name);
        $this->assertSame('Photo booth', $snap->business_type);
        $this->assertSame('4.90', (string) $snap->google_rating);
        $this->assertSame(87, (int) $snap->review_count);
        $this->assertTrue($snap->has_website);
        $this->assertSame('Denver', $snap->city);
        $this->assertSame('CO', $snap->state);
        $this->assertSame($batch->id, (int) $snap->import_batch_id);

        $glow = AgencyProspect::where('workspace_id', $a['workspace']->id)->where('phone', '17205550101')->firstOrFail();
        $this->assertFalse($glow->has_website);
        $this->assertSame('https://www.instagram.com/glowbooth', $glow->social_url);
        $this->assertSame(1, AgencyProspect::where('workspace_id', $a['workspace']->id)->where('phone', '17205550101')->count(), 'duplicate phone => one prospect');

        // Original values stay accessible for diagnostics.
        $this->assertDatabaseHas('agency_prospect_import_rows', ['batch_id' => $batch->id, 'row_number' => 2, 'outcome' => 'new']);
        $row = $batch->rows()->where('row_number', 2)->first();
        $this->assertSame('Snap Happy Booths', $row->original['name']);
        $this->assertSame('12 Main St, Denver, CO 80202', $row->original['address']);
    }

    public function test_numbers_without_a_country_code_need_a_confirmed_region_and_mapping_can_be_corrected(): void
    {
        $a = $this->outreachAgency('Reg');
        $this->authenticateAs($a['customer']);
        $csv = "Name,Phone,Category\nBoulder Booths,(303) 555-0177,Photo booth\nDenver Mirrors,(720) 555-0188,Photo booth\nA Co,(720) 555-0199,Photo booth\n";

        $this->upload($a, $csv);
        $batch = $this->batch($a);
        $this->assertSame(3, $batch->summary['outcomes']['invalid_phone'] + $batch->summary['outcomes']['missing_phone']);
        $this->assertSame(3, $batch->summary['issues']['needs_region']['count']);

        // The user confirms the region AND swaps a wrong mapping (Category was shown as ignored).
        $this->post($this->outreachRoute('prospects.import.map', $a['workspace'], [$batch->uid]), [
            'region' => 'US', 'mapping' => [0 => 'business_name', 1 => 'phone', 2 => 'source'],
        ])->assertRedirect();

        $batch->refresh();
        $this->assertSame('US', $batch->region);
        $this->assertSame('source', $batch->mapping['Category']);
        $this->assertSame(3, $batch->summary['outcomes']['new']);
    }

    public function test_reimport_keeps_existing_status_notes_and_client_state_and_is_idempotent(): void
    {
        $a = $this->outreachAgency('Re');
        $this->authenticateAs($a['customer']);
        $ws = $a['workspace'];

        $existing = AgencyProspect::create(['workspace_id' => $ws->id, 'company_name' => 'My Own Name', 'phone' => '17206640969', 'status' => 'booked', 'booked_at' => now(), 'source' => 'Hand added']);

        $csv = "name,phone,primary category,rating\nGoogle Name,+1 720 664 0969,Photo booth,4.9\n";
        $this->upload($a, $csv);
        $batch = $this->batch($a);
        $this->post($this->outreachRoute('prospects.import.commit', $ws, [$batch->uid]), ['group_name' => 'Grp ' . uniqid()])->assertRedirect();
        // Committing the same batch again is a no-op.
        $this->post($this->outreachRoute('prospects.import.commit', $ws, [$batch->uid]), ['group_name' => 'Grp ' . uniqid()])->assertRedirect();

        $existing->refresh();
        $this->assertSame('My Own Name', $existing->company_name, 'Existing prospect details are never overwritten.');
        $this->assertSame(AgencyProspectStatus::Booked, $existing->status);
        $this->assertSame('Hand added', $existing->source);
        $this->assertSame('Photo booth', $existing->business_type, 'Blank enrichment is filled in.');
        $this->assertSame(1, AgencyProspect::where('workspace_id', $ws->id)->where('phone', '17206640969')->count());
    }

    public function test_existing_clients_and_stop_in_a_file_are_never_imported_as_prospects(): void
    {
        $a = $this->outreachAgency('Prot');
        $this->authenticateAs($a['customer']);
        $ws = $a['workspace'];
        app(OutreachExistingClients::class)->add($ws, '+17205550001', 'Known Client Photo', 'manual', 't', null);
        AgencyProspect::create(['workspace_id' => $ws->id, 'company_name' => 'Opted out', 'phone' => '17205550002', 'status' => 'stopped', 'stop_reason' => 'opt_out', 'stopped_at' => now()]);

        $csv = "name,phone\nKnown Client Photo Booth,+1 720 555 0001\nOpted Out Co,+1 720 555 0002\nFresh Co,+1 720 555 0003\nKnown Client Photo Studio,+1 720 555 0004\n";
        $this->upload($a, $csv);
        $batch = $this->batch($a);

        $this->assertSame(1, $batch->summary['outcomes']['excluded']);
        $this->assertSame(1, $batch->summary['outcomes']['stopped']);
        $this->assertArrayHasKey('similar_client', $batch->summary['issues'], 'Similar name + different number is a review hint.');

        $this->post($this->outreachRoute('prospects.import.commit', $ws, [$batch->uid]), ['group_name' => 'Grp ' . uniqid()])->assertRedirect();

        $this->assertDatabaseMissing('agency_prospects', ['workspace_id' => $ws->id, 'phone' => '17205550001']);
        $this->assertSame('stopped', AgencyProspect::where('phone', '17205550002')->first()->status->value);
        $this->assertDatabaseHas('agency_prospects', ['workspace_id' => $ws->id, 'phone' => '17205550003']);
        // A similar name never blocks the business automatically.
        $this->assertDatabaseHas('agency_prospects', ['workspace_id' => $ws->id, 'phone' => '17205550004']);
    }

    public function test_an_existing_client_csv_adds_exclusions(): void
    {
        $a = $this->outreachAgency('CCsv');
        $this->authenticateAs($a['customer']);
        $this->upload($a, "business,mobile\nClient One,+1 303 555 0111\nClient Two,+1 303 555 0112\n", 'existing_clients');
        $batch = $this->batch($a);
        $this->post($this->outreachRoute('prospects.import.commit', $a['workspace'], [$batch->uid]), ['group_name' => 'Grp ' . uniqid()])->assertRedirect();

        $this->assertSame(2, \App\Models\AgencyProspectExclusion::where('workspace_id', $a['workspace']->id)->whereNull('removed_at')->count());
    }

    public function test_batches_from_another_workspace_are_rejected(): void
    {
        $a = $this->outreachAgency('XA');
        $b = $this->outreachAgency('XB');
        $this->authenticateAs($a['customer']);
        $this->upload($a, "name,phone\nCo,+1 303 555 0100\n");
        $batch = $this->batch($a);

        $this->authenticateAs($b['customer']);
        $this->get($this->outreachRoute('prospects.import.review', $b['workspace'], [$batch->uid]))->assertNotFound();
        $this->post($this->outreachRoute('prospects.import.commit', $b['workspace'], [$batch->uid]))->assertNotFound();
        $this->post($this->outreachRoute('prospects.import.map', $b['workspace'], [$batch->uid]), ['region' => 'US'])->assertNotFound();
        $this->get($this->outreachRoute('prospects.import.review', $a['workspace'], [$batch->uid]))->assertNotFound();
    }

    public function test_malformed_files_are_rejected_cleanly(): void
    {
        $a = $this->outreachAgency('Mal');
        $this->authenticateAs($a['customer']);

        $this->upload($a, '')->assertSessionHasErrors('file');
        $this->upload($a, "name,phone\n")->assertSessionHasErrors('file');
        $this->assertSame(0, AgencyProspectImportBatch::where('workspace_id', $a['workspace']->id)->count());
    }

    public function test_abbreviated_review_counts_keep_their_multiplier_or_are_rejected(): void
    {
        foreach (['86' => 86, '(86)' => 86, '1,234' => 1234, '1.2k' => 1200, '2K' => 2000, '1.25k' => 1250, '3.5M' => 3500000, ' 12 ' => 12] as $raw => $expected) {
            $this->assertSame($expected, ProspectRowNormalizer::parseReviewCount((string) $raw), "parse {$raw}");
        }

        foreach (['abc', '1.234', '12 reviews', '-5', '1k2', '1.2.3k', '1kk', '1e3', '9999999999k', ''] as $bad) {
            $this->assertNull(ProspectRowNormalizer::parseReviewCount($bad), "reject {$bad}");
        }

        $map = ['n' => 'business_name', 'p' => 'phone', 'rv' => 'review_count'];
        $ok = ProspectRowNormalizer::normalize(['n' => 'X', 'p' => '+1 303 555 0177', 'rv' => '1.2k'], $map, null);
        $this->assertSame(1200, $ok['values']['review_count']);
        $this->assertNotContains('invalid_review_count', $ok['issues']);

        $bad = ProspectRowNormalizer::normalize(['n' => 'X', 'p' => '+1 303 555 0177', 'rv' => '1.234'], $map, null);
        $this->assertNull($bad['values']['review_count']);
        $this->assertContains('invalid_review_count', $bad['issues'], 'an unsupported format is reported, never silently reduced to digits');
    }

    public function test_import_cannot_enroll_into_a_campaign_that_became_active_concurrently(): void
    {
        $a = $this->outreachAgency('Race', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $this->upload($a, "name,phone\nRace Co,+1 303 555 0188\nRace Two,+1 303 555 0189\n");
        $batch = $this->batch($a);
        $campaign = $a['campaign'];
        $this->assertSame('draft', $campaign->refresh()->status->value);

        // The campaign is a draft when the request starts (so the cheap pre-check passes) and is started by
        // another request before the import's transaction takes its locks.
        // retrieval #1 is the controller loading the batch, #2 is the transaction locking it: flip between them.
        $retrievals = 0;
        AgencyProspectImportBatch::retrieved(function () use (&$retrievals, $campaign) {
            if (++$retrievals === 2) {
                \Illuminate\Support\Facades\DB::table('agency_prospect_campaigns')->where('id', $campaign->id)->update(['status' => 'active']);
            }
        });

        $this->post($this->outreachRoute('prospects.import.commit', $a['workspace'], [$batch->uid]), ['campaign_uid' => $campaign->uid, 'group_name' => 'Grp ' . uniqid()])
            ->assertRedirect($this->outreachRoute('prospects.import.review', $a['workspace'], [$batch->uid]));
        $this->assertStringContainsString('already started', (string) session('flash_error'));

        $this->assertSame(0, \App\Models\AgencyProspectCampaignMember::where('campaign_id', $campaign->id)->count(), 'nobody enrolled into the running campaign');
        $this->assertSame(0, AgencyProspect::where('workspace_id', $a['workspace']->id)->where('phone', '13035550188')->count(), 'nothing imported at all');
        $this->assertSame('analyzed', $batch->refresh()->status, 'the batch stays re-runnable');

        // Importing to the prospect list only still works afterwards.
        $this->post($this->outreachRoute('prospects.import.commit', $a['workspace'], [$batch->uid]), ['group_name' => 'Grp ' . uniqid()])->assertRedirect();
        $this->assertSame(1, AgencyProspect::where('workspace_id', $a['workspace']->id)->where('phone', '13035550188')->count());
    }

    public function test_a_phone_created_by_a_concurrent_import_is_reused_not_a_500(): void
    {
        $a = $this->outreachAgency('Dupe', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $this->upload($a, "name,phone\nRace Co,+1 303 555 0188\n");
        $batch = $this->batch($a);
        $ws = $a['workspace'];

        // Another import creates the same canonical phone just before this one inserts it.
        AgencyProspect::creating(function ($p) use ($ws) {
            if ($p->phone === '13035550188' && ! \Illuminate\Support\Facades\DB::table('agency_prospects')->where('workspace_id', $ws->id)->where('phone', '13035550188')->exists()) {
                \Illuminate\Support\Facades\DB::table('agency_prospects')->insert(['uid' => 'rival', 'workspace_id' => $ws->id, 'company_name' => 'Rival', 'phone' => '13035550188', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->post($this->outreachRoute('prospects.import.commit', $ws, [$batch->uid]), ['group_name' => 'Grp ' . uniqid()])->assertRedirect();

        $this->assertSame(1, AgencyProspect::where('workspace_id', $ws->id)->where('phone', '13035550188')->count());
        $this->assertSame('imported', $batch->refresh()->status);
        $this->assertSame('Rival', AgencyProspect::where('phone', '13035550188')->first()->company_name, 'the concurrently created prospect is reused and not overwritten');
    }
}
