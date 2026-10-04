<?php

namespace Tests\Feature\GoogleAds\Attribution;

use App\Enums\GoogleAds\LeadAttributionEntrySurface;
use App\Enums\GoogleAds\LeadAttributionSubjectType;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Forms\FormSubmissionService;
use App\Library\GoogleAds\Attribution\LeadAttributionLevel;
use App\Library\GoogleAds\Attribution\LeadAttributionReader;
use App\Library\GoogleAds\Attribution\LeadAttributionRecorder;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\Feature\GoogleAds\Attribution\Concerns\BuildsAttributionCookies;
use Tests\TestCase;

class LeadAttributionReaderTest extends TestCase
{
    use BuildsAttributionCookies;
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private Business $business;

    private BusinessLocation $location;

    private FormDeployment $deployment;

    private int $phoneSeed = 0;

    private string $lastPhone = '';

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->business] = $this->formsTenant();
        $this->location = $this->formsLocation($this->business);
        [, $this->deployment] = $this->liveForm($this->business, $this->location);
    }

    /** One public-form conversion with a distinct contact, optionally carrying cookies. */
    private function lead(array $cookies = [], ?Business $business = null, ?FormDeployment $deployment = null): FormSubmission
    {
        $deployment ??= $this->deployment;
        $phone = '+1415555'.str_pad((string) (++$this->phoneSeed + random_int(1000, 8999)), 4, '0', STR_PAD_LEFT);

        $this->lastPhone = $phone;
        $submission = app(FormSubmissionService::class)
            ->submit($deployment->uid, $this->submitInput($deployment, ['phone' => $phone]))->submission;

        app(LeadAttributionRecorder::class)->record(
            $submission->business_id, $submission->business_location_id, $submission->contact_id,
            LeadAttributionSubjectType::FormSubmission, $submission->id, LeadAttributionEntrySurface::PublicForm,
            Request::create('/', 'POST', [], $cookies),
        );

        return $submission;
    }

    private function read(?Business $business = null, string $from = '-1 day', string $to = '+1 day'): array
    {
        return app(LeadAttributionReader::class)
            ->page($business ?? $this->business, Carbon::parse($from), Carbon::parse($to))->items();
    }

    private function byContact(array $rows): array
    {
        return collect($rows)->keyBy(fn ($row) => $row['contact']['id'])->all();
    }

    public function test_levels_are_google_click_campaign_tags_or_not_captured_never_a_campaign_match(): void
    {
        $click = $this->lead($this->touchCookies(['gclid' => self::CLICK]));
        $tags = $this->lead($this->touchCookies(['utm_source' => 'google', 'utm_campaign' => 'Spring']));
        $none = $this->lead();

        $rows = $this->byContact($this->read());

        $this->assertCount(3, $rows);
        $this->assertSame(LeadAttributionLevel::GoogleClick, $rows[$click->contact_id]['level']);
        $this->assertSame(LeadAttributionLevel::CampaignTags, $rows[$tags->contact_id]['level']);
        $this->assertSame(LeadAttributionLevel::NotCaptured, $rows[$none->contact_id]['level']);
        $this->assertNull($rows[$none->contact_id]['first_touch']);

        // No false precision: there is no campaign / keyword resolution anywhere in a row.
        $keys = array_keys($rows[$click->contact_id]);
        foreach (['campaign', 'campaign_id', 'keyword', 'ad_group', 'google_campaign'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys);
        }
        $this->assertSame('Google click ID captured', $rows[$click->contact_id]['level']->label());
        $this->assertSame('public_form', $rows[$click->contact_id]['entry_surface']);
        $this->assertSame('/sites/demo', $rows[$click->contact_id]['landing_page']);
        $this->assertSame(self::CLICK, $rows[$click->contact_id]['first_touch']['gclid']);
        $this->assertSame('form_submission', $rows[$none->contact_id]['subject']['type']);
        $this->assertSame('public_form', $rows[$none->contact_id]['entry_surface']);
    }

    public function test_a_utm_first_touch_followed_by_a_click_id_last_touch_is_a_google_click_with_both_touches(): void
    {
        $lead = $this->lead($this->touchCookies(['utm_source' => 'newsletter'], ['gclid' => self::CLICK]));

        $row = $this->byContact($this->read())[$lead->contact_id];

        $this->assertSame(LeadAttributionLevel::GoogleClick, $row['level']);
        $this->assertSame('newsletter', $row['first_touch']['utm_source']);
        $this->assertSame(self::CLICK, $row['last_touch']['gclid']);
    }

    public function test_first_touch_is_the_oldest_first_row_and_last_touch_the_newest_last_row_across_conversions(): void
    {
        $first = $this->lead($this->touchCookies(['gclid' => 'OldestClickIdAAAAAA1'], ['utm_source' => 'a']));
        $contactId = (int) $first->contact_id;

        // The same person converts again later with other touches.
        $second = app(FormSubmissionService::class)->submit(
            $this->deployment->uid, $this->submitInput($this->deployment, ['phone' => $this->lastPhone, 'message' => 'again'])
        )->submission;
        $this->assertSame($contactId, (int) $second->contact_id);
        app(LeadAttributionRecorder::class)->record(
            $this->business, $this->location->id, $second->contact_id, LeadAttributionSubjectType::FormSubmission,
            $second->id, LeadAttributionEntrySurface::PublicForm,
            Request::create('/', 'POST', [], $this->touchCookies(['gclid' => 'OldestClickIdAAAAAA1'], ['utm_source' => 'newest'])),
        );
        DB::table('lead_attribution_touches')->where('subject_id', $second->id)->update(['captured_at' => now()->addMinute()]);

        $rows = $this->read();
        $this->assertCount(1, $rows, 'distinct contact, not one row per conversion');
        $this->assertSame('OldestClickIdAAAAAA1', $rows[0]['first_touch']['gclid']);
        $this->assertSame('newest', $rows[0]['last_touch']['utm_source']);
        $this->assertSame(['gclid' => 'OldestClickIdAAAAAA1', 'type' => 'gclid'], [
            'gclid' => app(LeadAttributionReader::class)->firstClickIdFor($this->business, $contactId)['value'],
            'type' => app(LeadAttributionReader::class)->firstClickIdFor($this->business, $contactId)['type'],
        ]);
    }

    public function test_summary_counts_levels_and_distinct_leads_and_respects_the_period(): void
    {
        $this->lead($this->touchCookies(['gclid' => self::CLICK]));
        $this->lead($this->touchCookies(['gbraid' => 'GbraidValue000001']));
        $this->lead($this->touchCookies(['utm_medium' => 'cpc']));
        $this->lead();
        $old = $this->lead($this->touchCookies(['gclid' => self::CLICK]));
        DB::table('form_submissions')->where('id', $old->id)->update(['created_at' => now()->subDays(40)]);

        $summary = app(LeadAttributionReader::class)->summary($this->business, now()->subDays(30), now()->addDay());

        $this->assertSame(['leads' => 4, 'google_click' => 2, 'campaign_tags' => 1, 'not_captured' => 1], $summary);
        $this->assertCount(4, $this->read(null, '-30 days'));
    }

    public function test_an_empty_period_is_all_zero(): void
    {
        $this->assertSame(['leads' => 0, 'google_click' => 0, 'campaign_tags' => 0, 'not_captured' => 0],
            app(LeadAttributionReader::class)->summary($this->business, now()->subDays(30), now()->addDay()));
        $this->assertSame([], $this->read());
    }

    public function test_a_submission_without_a_contact_is_not_a_lead(): void
    {
        $lead = $this->lead($this->touchCookies(['gclid' => self::CLICK]));
        DB::table('form_submissions')->where('id', $lead->id)->update(['contact_id' => null]);

        $this->assertSame([], $this->read());
        $this->assertSame(0, app(LeadAttributionReader::class)->summary($this->business, now()->subDay(), now()->addDay())['leads']);
    }

    public function test_the_reader_is_business_scoped(): void
    {
        [, $other] = $this->formsTenant(name: 'Other Studio');
        $otherLocation = $this->formsLocation($other, 'Elsewhere');
        [, $otherDeployment] = $this->liveForm($other, $otherLocation);

        $mine = $this->lead($this->touchCookies(['gclid' => self::CLICK]));
        $theirs = $this->lead($this->touchCookies(['gclid' => 'TheirClickIdAAAAAAA1']), null, $otherDeployment);

        $mineRows = $this->read();
        $theirRows = $this->read($other);

        $this->assertSame([(int) $mine->contact_id], array_map(fn ($r) => $r['contact']['id'], $mineRows));
        $this->assertSame([(int) $theirs->contact_id], array_map(fn ($r) => $r['contact']['id'], $theirRows));
        $this->assertNull(app(LeadAttributionReader::class)->firstClickIdFor($this->business, (int) $theirs->contact_id));
        $this->assertSame(1, app(LeadAttributionReader::class)->summary($this->business, now()->subDay(), now()->addDay())['leads']);
    }

    public function test_the_current_crm_opportunity_is_read_with_the_business_currency(): void
    {
        $lead = $this->lead($this->touchCookies(['gclid' => self::CLICK]));
        $pipeline = $this->formsPipeline($this->business);
        $service = app(CrmOpportunityService::class);

        $service->create($this->business, $pipeline, $lead->contact, 'Old deal', 1000);
        $latest = $service->create($this->business, $pipeline, $lead->contact, 'Current deal', 250000);

        $row = $this->read()[0];

        $this->assertSame($latest->stage->name, $row['opportunity']['stage']);
        $this->assertSame('open', $row['opportunity']['status']);
        $this->assertSame(250000, $row['opportunity']['value_minor']);
        $this->assertSame($this->business->currency_code, $row['opportunity']['currency']);
        $this->assertFalse($row['booked']);
    }

    public function test_query_count_does_not_depend_on_the_number_of_rows(): void
    {
        $pipeline = $this->formsPipeline($this->business);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->read();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $lead = $this->lead($this->touchCookies(['gclid' => self::CLICK]));
        app(CrmOpportunityService::class)->create($this->business, $pipeline, $lead->contact, 'Deal', 100);
        $small = $count();

        foreach (range(1, 6) as $i) {
            $lead = $this->lead($this->touchCookies(['utm_source' => 's'.$i], ['gclid' => 'Click'.str_pad((string) $i, 12, '0')]));
            app(CrmOpportunityService::class)->create($this->business, $pipeline, $lead->contact, 'Deal '.$i, 100);
        }
        $large = $count();

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(10, $large);
    }

    public function test_paging_is_bounded_and_reports_the_true_total(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->lead($this->touchCookies(['gclid' => self::CLICK]));
        }

        $page = app(LeadAttributionReader::class)->page($this->business, now()->subDay(), now()->addDay(), 2, 2);

        $this->assertSame(5, $page->total());
        $this->assertCount(2, $page->items());
        $this->assertSame(3, $page->lastPage());
    }
}
