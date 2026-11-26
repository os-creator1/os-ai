<?php

namespace Tests\Feature\WebsiteAnalytics;

use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Library\AgencyOutreach\OutreachScriptRenderer;
use App\Library\AgencyOutreach\OutreachScriptTokens;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\WebsiteAnalytics\AnalyticsCampaignLinks;
use App\Library\WebsiteAnalytics\AnalyticsReport;
use App\Models\AgencySite;
use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\Workspace;
use App\Models\WebsiteAnalyticsEvent;
use App\Models\WebsiteAnalyticsProperty;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Agency → White Label → Analytics, end to end over HTTP with the real stack and a fake SMS provider:
 *
 *   actual campaign SMS (the owner pastes the normal /photobooth link) → tagged landing page → real calendar → real booking write
 *   → the right Agency's dashboard, with one visit, one calendar open and one confirmed booking.
 *
 * Fictional fixtures only. Time is frozen so the default working hours always offer a future slot.
 */
class AgencyLandingFunnelAnalyticsTest extends TestCase
{
    use BuildsOutreachFixtures;
    use RefreshDatabase;

    private const PHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootOutreach();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * One Agency with a published original-edition landing page at /photobooth (its own calendar), and a managed SMS campaign.
     *
     * @return array{customer: mixed, business: mixed, workspace: Workspace, site: AgencySite, type: BookingType, path: string}
     */
    private function agencyWithLanding(string $name, string $path = 'photobooth', bool $embedCalendar = true): array
    {
        [$customer, $business, $workspace] = $this->agency($name);
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        $type = app(AgencySiteBookingService::class)->create((int) $customer->user_id, $workspace, ['name' => 'Intro call', 'duration_minutes' => 15, 'default_hours' => true]);
        config(['marketing.landing.official_paths.' . $path => (string) $workspace->uid]);
        $this->artisan('agency-site:init-original', ['workspace_uid' => $workspace->uid, '--booking-type' => $type->public_booking_uuid, '--apply' => true])->assertSuccessful();
        if ($embedCalendar) {
            // The owner's finished /photobooth: the real booking calendar embedded at #book on the landing page itself.
            $this->artisan('agency-site:complete-photobooth', ['workspace_uid' => $workspace->uid, '--apply' => true])->assertSuccessful();
        }
        $site = app(AgencySiteManager::class)->publish((int) $customer->user_id, $workspace);
        config(['marketing.landing.official_paths.' . $path => (string) $workspace->uid]);

        return compact('customer', 'business', 'workspace', 'site', 'type', 'path');
    }

    /** The message the prospect's phone really receives, via the real initial-send job and the fake provider. */
    private function sendCampaignSms(Workspace $workspace, string $opening, string $phone = '12025551001'): array
    {
        $campaign = $this->managedCampaign($workspace, ['name' => 'Venue blast', 'opening_message' => $opening]);
        $prospect = $this->prospect($workspace, $phone, ['company_name' => 'Dune Bakery', 'contact_name' => 'Dana Dune']);
        $member = $this->enroll($workspace, $campaign, $prospect);
        OutreachInitialSendJob::dispatch($member->id);

        return [$campaign, $prospect, $this->sentBodies()];
    }

    /** Signs the Agency owner in with every customer permission, as the Agency's own browser session. */
    private function authenticateAs($customer): void
    {
        $user = $customer->user;
        $user->email_verified_at = now();
        $user->save();
        $this->withSession(['permissions' => collect(array_merge(['access_backend'], array_keys(config('customer-permissions'))))]);
        $this->actingAs($user);
    }

    private function urlIn(string $text): string
    {
        $this->assertSame(1, preg_match('~https?://[^\s]+~', $text, $m), 'the message carries a link');

        return rtrim($m[0], '.,!?');
    }

    private function localPath(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** @return array<string, mixed> */
    private function pageConfig(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="mg-analytics-config">(.*?)</script>#s', $html, $m), 'the published page carries the analytics config');

        return json_decode($m[1], true);
    }

    private function beacon(array $payload, string $ua = self::PHONE_UA, array $headers = [])
    {
        $server = ['HTTP_USER_AGENT' => $ua, 'CONTENT_TYPE' => 'text/plain'];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->call('POST', route('public.analytics.collect', [], false), [], [], [], $server, json_encode($payload));
    }

    private function ids(): array
    {
        return ['v' => bin2hex(random_bytes(16)), 's' => bin2hex(random_bytes(16))];
    }

    private function pv(string $key, array $ids, string $path = '/photobooth', ?string $campaign = null, string $referrer = ''): array
    {
        return ['k' => $key, 't' => 'pageview', 'v' => $ids['v'], 's' => $ids['s'], 'e' => bin2hex(random_bytes(16)), 'p' => $path, 'r' => $referrer, 'c' => $campaign];
    }

    /** Picks the first real offered slot from the real calendar endpoints, as the page's script does. */
    private function firstSlot(BookingType $type): array
    {
        $uuid = $type->public_booking_uuid;
        foreach (['2027-03', '2027-04'] as $month) {
            $dates = $this->getJson(route('public.booking.dates', [$uuid]) . '?month=' . $month)->assertOk()->json('available');
            if ($dates !== []) {
                $date = $dates[0];
                $slots = $this->getJson(route('public.booking.slots', [$uuid]) . '?date=' . $date)->assertOk()->json('slots');
                $this->assertNotEmpty($slots, 'the calendar offers a time');
                $first = $slots[0];

                return [$date, is_array($first) ? ($first['time'] ?? $first['start'] ?? $first['value']) : $first];
            }
        }
        $this->fail('The fixture calendar offers no slot.');
    }

    private function bookingPayload(string $date, string $time, array $mg = []): array
    {
        return array_merge([
            'date' => $date, 'time' => $time, 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'email' => 'ada@example.test', 'phone' => '+1 (415) 555-1234', 'visitor_timezone' => 'UTC',
        ], $mg);
    }

    private function events(WebsiteAnalyticsProperty $property, ?string $kind = null)
    {
        return WebsiteAnalyticsEvent::query()->where('property_id', $property->id)->when($kind, fn ($q) => $q->where('kind', $kind))->get();
    }

    private function dashboard(array $a, string $query = '')
    {
        $this->authenticateAs($a['customer']);

        return $this->get(route('customer.workspaces.agency.white-label.analytics', [$a['workspace']->uid]) . $query);
    }

    private function kpi(string $html, string $metric): int
    {
        $this->assertSame(1, preg_match('#data-metric="' . $metric . '"><div class="v">([0-9,]+)</div>#', $html, $m), "KPI {$metric} is on the screen");

        return (int) str_replace(',', '', $m[1]);
    }

    // ------------------------------------------------------------------------------------------------------------------

    public function test_cold_sms_to_tagged_visit_to_calendar_to_confirmed_booking_is_one_attributable_funnel_and_replays_stay_one(): void
    {
        $a = $this->agencyWithLanding('Motion Grove Photo Booth');
        $this->saveScript($a['workspace']);

        // 1. The owner pastes the NORMAL link into the script; the final outbound rendering tags it.
        $normal = url('/photobooth');
        [$campaign, , $bodies] = $this->sendCampaignSms($a['workspace'], 'Hi {{company_name}}, see our photo booth offer at ' . $normal . '. Reply STOP to opt out.');
        $this->assertCount(1, $bodies);
        $sms = $bodies[0];
        $tagged = $this->urlIn($sms);
        $this->assertMatchesRegularExpression('~^' . preg_quote($normal, '~') . '\?mgc=c[a-z0-9]{9}$~', $tagged);
        $this->assertStringContainsString($tagged . '. Reply STOP', $sms, 'sentence punctuation stays outside the link');
        foreach (['Dana', 'Dune', '12025551001'] as $personal) {
            $this->assertStringNotContainsString($personal, $tagged, 'no contact detail in the link');
        }
        $this->assertSame($sms, OutreachScriptRenderer::render(OutreachScriptTokens::canonicalise($campaign->opening_message), $a['workspace'], $this->prospectOf($a['workspace'])), 'preview = send');

        // 2. Sending the SMS and merely fetching the link (a preview scanner, or any page GET) is NOT a human visit.
        $path = $this->localPath($tagged);
        $this->withHeader('User-Agent', 'facebookexternalhit/1.1')->get($path)->assertOk();
        $html = $this->withHeader('User-Agent', self::PHONE_UA)->get($path)->assertOk()->getContent();
        $config = $this->pageConfig($html);
        $property = WebsiteAnalyticsProperty::query()->where('public_key', $config['key'])->firstOrFail();
        $this->assertSame((int) $a['workspace']->id, (int) $property->workspace_id, 'the tenant was resolved server side');
        $this->assertSame(0, $this->events($property)->count(), 'rendering the page records nothing; only the visible page does');
        $this->assertSame(['mgc' => $this->mgc($tagged)], ['mgc' => $this->mgc($tagged)]);

        // 3. The page is actually viewed: one visit tagged with the campaign. A retried beacon is the same event.
        $ids = $this->ids();
        $pv = $this->pv($config['key'], $ids, '/photobooth', $this->mgc($tagged));
        $this->beacon($pv)->assertNoContent();
        $this->beacon($pv)->assertNoContent();
        $this->assertSame(1, $this->events($property, 'pageview')->count());
        $this->assertNotNull($this->events($property, 'pageview')->first()->campaign_id);

        // 4. The real calendar is displayed (embedded on the landing page): one calendar open per visit.
        $this->assertStringContainsString('data-role="public-scheduler"', $html);
        $open = ['k' => $config['key'], 't' => 'calendar_open', 'v' => $ids['v'], 's' => $ids['s'], 'e' => bin2hex(random_bytes(16)), 'p' => '/photobooth', 'bt' => $a['type']->public_booking_uuid, 'c' => $this->mgc($tagged)];
        $this->beacon($open)->assertNoContent();
        $this->beacon($open)->assertNoContent();
        $this->assertSame(1, $this->events($property, 'calendar_open')->count());

        // 5. A booking is written by the real calendar endpoint, carrying the anonymous context the script added.
        [$date, $time] = $this->firstSlot($a['type']);
        $mg = ['mg_pk' => $config['key'], 'mg_v' => $ids['v'], 'mg_s' => $ids['s'], 'mg_c' => $this->mgc($tagged)];
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->postJson(route('public.booking.store', [$a['type']->public_booking_uuid]), $this->bookingPayload($date, $time, $mg))->assertStatus(201);
        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(1, $this->events($property, 'booking_confirmed')->count());

        // 6. Replays: the same request again (slot is taken), refreshing the confirmation page, and a forged beacon claiming a booking.
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->postJson(route('public.booking.store', [$a['type']->public_booking_uuid]), $this->bookingPayload($date, $time, $mg))->assertStatus(409);
        foreach ([1, 2] as $_) {
            $this->get(route('public.booking.confirmed', [$a['type']->public_booking_uuid]))->assertOk();
        }
        $this->beacon(['k' => $config['key'], 't' => 'booking_confirmed', 'v' => $ids['v'], 's' => $ids['s'], 'p' => '/photobooth'])->assertNoContent();
        $this->assertSame(1, $this->events($property, 'booking_confirmed')->count(), 'still exactly one booking, and a browser can never claim one');

        // 7. The correct dashboard shows one attributable visit, one calendar open and one confirmed booking.
        $html = $this->dashboard($a)->assertOk()->assertSee('data-testid="website-analytics"', false)->getContent();
        $this->assertSame(1, $this->kpi($html, 'visits'));
        $this->assertSame(1, $this->kpi($html, 'visitors'));
        $this->assertSame(1, $this->kpi($html, 'calendar_opens'));
        $this->assertSame(1, $this->kpi($html, 'bookings'));
        $this->assertStringContainsString('100%', $html, 'visit-to-booking rate');
        $this->assertStringContainsString('SMS: Venue blast', $html);
        $this->assertStringContainsString('Booked a call', $html);
        $this->assertStringContainsString('Measurement started', $html);

        $byCampaign = $this->dashboard($a, '?campaign=' . $this->mgc($tagged))->assertOk()->getContent();
        $this->assertSame(1, $this->kpi($byCampaign, 'visits'));
        $this->assertSame(1, $this->kpi($byCampaign, 'bookings'));
        $direct = $this->dashboard($a, '?campaign=direct')->assertOk()->getContent();
        $this->assertSame(0, $this->kpi($direct, 'visits'), 'the campaign visit is not Direct / unknown');
        $this->assertSame(0, $this->kpi($direct, 'bookings'));
    }

    private function prospectOf(Workspace $workspace)
    {
        return \App\Models\AgencyProspect::query()->where('workspace_id', $workspace->id)->firstOrFail();
    }

    private function mgc(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return (string) $q['mgc'];
    }

    public function test_tagging_preserves_query_and_fragment_is_idempotent_and_leaves_unrelated_urls_alone(): void
    {
        $a = $this->agencyWithLanding('Alpha Booth');
        $other = $this->agencyWithLanding('Beta Booth', 'dogs', false);
        $campaign = $this->managedCampaign($a['workspace'], ['name' => 'Spring']);
        $links = app(AnalyticsCampaignLinks::class);
        $token = $links->tokenFor($campaign)->token;

        $this->assertSame('See ' . url('/photobooth') . '?mgc=' . $token . '.', $links->tag('See ' . url('/photobooth') . '.', $a['workspace'], $campaign));
        $this->assertSame(url('/photobooth') . '?utm_source=sms&x=1&mgc=' . $token . '#book', $links->tag(url('/photobooth') . '?utm_source=sms&x=1#book', $a['workspace'], $campaign), 'query and fragment preserved');
        $this->assertSame(url('/photobooth/pricing') . '?mgc=' . $token, $links->tag(url('/photobooth/pricing'), $a['workspace'], $campaign), 'inner pages of the owned landing page');

        $once = $links->tag('Go ' . url('/photobooth'), $a['workspace'], $campaign);
        $this->assertSame($once, $links->tag($once, $a['workspace'], $campaign), 'idempotent');
        $this->assertSame($once, $links->tag($links->tag($once, $a['workspace'], $campaign), $a['workspace'], $campaign));
        $existing = url('/photobooth') . '?mgc=csomeoneelse';
        $this->assertSame($existing, $links->tag($existing, $a['workspace'], $campaign), 'an existing value is never replaced');

        foreach ([
            'https://example.com/photobooth',                                  // not a MotionGrove host
            'https://cal.example.test/snap?x=1',                               // booking link of another provider
            url('/dogs'),                                                      // official path of ANOTHER agency
            url('/pricing'),                                                   // a MotionGrove page that is not this agency's landing page
            url('/photoboothfoo'),                                             // look-alike path
            'mailto:hello@example.com',
        ] as $untouched) {
            $this->assertSame('x ' . $untouched . ' y', $links->tag('x ' . $untouched . ' y', $a['workspace'], $campaign), $untouched);
        }

        $this->assertSame('no link here', $links->tag('no link here', $a['workspace'], $campaign));
        $this->assertSame(url('/photobooth'), $links->tag(url('/photobooth'), $a['workspace'], null), 'no campaign, no tag');
        // Another agency's campaign can never tag this agency's link.
        $foreign = $this->managedCampaign($other['workspace'], ['name' => 'Theirs']);
        $this->assertSame(url('/photobooth'), $links->tag(url('/photobooth'), $a['workspace'], $foreign));
    }

    public function test_an_untagged_visit_is_direct_unknown_and_a_tagged_one_never_leaks_into_it(): void
    {
        $a = $this->agencyWithLanding('Motion Grove Photo Booth');
        $key = $this->pageConfig($this->withHeader('User-Agent', self::PHONE_UA)->get('/photobooth')->assertOk()->getContent())['key'];
        $this->beacon($this->pv($key, $this->ids()))->assertNoContent();
        $this->beacon($this->pv($key, $this->ids(), '/photobooth', null, 'https://www.google.com/search?q=booth'))->assertNoContent();
        $this->beacon($this->pv($key, $this->ids(), '/photobooth', 'cbogusvalue'))->assertNoContent(); // an unknown campaign value is no campaign

        $property = WebsiteAnalyticsProperty::query()->where('public_key', $key)->firstOrFail();
        $this->assertSame(3, $this->events($property, 'pageview')->count());
        $this->assertSame(0, $this->events($property)->whereNotNull('campaign_id')->count());
        $report = app(AnalyticsReport::class)->build($property, '7', 'UTC');
        $labels = array_column($report['sources'], 'label');
        $this->assertContains('Direct / unknown', $labels);
        $this->assertContains('www.google.com', $labels);
        $this->assertSame(2, collect($report['sources'])->firstWhere('label', 'Direct / unknown')['visits']);
        $this->assertStringNotContainsString('q=booth', json_encode($report), 'no query string is ever stored or shown');

        // "Direct / unknown only" excludes the referral visit (and would exclude its calendar open and outcomes too).
        $direct = app(AnalyticsReport::class)->build($property, '7', 'UTC', null, 'direct');
        $this->assertSame(2, $direct['totals']['visits']);
        $this->assertSame(['Direct / unknown'], array_column($direct['sources'], 'label'));
    }

    public function test_previews_drafts_scanners_prefetch_and_opted_out_visitors_are_never_counted(): void
    {
        $a = $this->agencyWithLanding('Motion Grove Photo Booth');
        $key = $this->pageConfig($this->withHeader('User-Agent', self::PHONE_UA)->get('/photobooth')->assertOk()->getContent())['key'];
        $property = WebsiteAnalyticsProperty::query()->where('public_key', $key)->firstOrFail();
        $before = $this->events($property)->count();

        // Link scanners, bots, carrier/browser prefetch, Global Privacy Control, Do Not Track, and no user agent at all.
        foreach (['facebookexternalhit/1.1', 'Twitterbot/1.0', 'Slackbot-LinkExpanding 1.0', 'WhatsApp/2.23', 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'Mozilla/5.0 HeadlessChrome/120', 'TelnyxLinkPreview', 'curl/8.4'] as $ua) {
            $this->beacon($this->pv($key, $this->ids()), $ua)->assertNoContent();
        }
        $this->beacon($this->pv($key, $this->ids()), self::PHONE_UA, ['Sec-Purpose' => 'prefetch'])->assertNoContent();
        $this->beacon($this->pv($key, $this->ids()), self::PHONE_UA, ['Sec-GPC' => '1'])->assertNoContent();
        $this->beacon($this->pv($key, $this->ids()), self::PHONE_UA, ['DNT' => '1'])->assertNoContent();
        $this->beacon($this->pv($key, $this->ids()), '')->assertNoContent();
        // Garbage, an unknown key, oversized bodies, a path that is not a path.
        $this->beacon($this->pv('zzzzzzzzzzzzzzzzzzzzzzzz', $this->ids()))->assertNoContent();
        $this->beacon(['k' => $key, 't' => 'pageview', 'v' => 'nothex', 's' => 'nothex', 'e' => 'x', 'p' => '/photobooth'])->assertNoContent();
        $this->beacon($this->pv($key, $this->ids(), 'https://evil.example/x'))->assertNoContent();
        $this->assertSame($before, $this->events($property)->count(), 'nothing above counted');

        // A draft preview and an unpublished site emit no analytics block, and no property is created for a draft-only site.
        $this->authenticateAs($a['customer']);
        $preview = $this->get(route('customer.workspaces.agency.site.preview', [$a['workspace']->uid]))->assertOk()->getContent();
        $this->assertStringNotContainsString('mg-analytics-config', $preview);
        app(AgencySiteManager::class)->unpublish((int) $a['customer']->user_id, $a['workspace']);
        $this->beacon($this->pv($key, $this->ids()))->assertNoContent();
        $this->assertSame($before, $this->events($property)->count(), 'an unpublished site accepts no events');

        // A real, visible view still counts once the site is live again.
        app(AgencySiteManager::class)->publish((int) $a['customer']->user_id, $a['workspace']);
        $this->beacon($this->pv($key, $this->ids()))->assertNoContent();
        $this->assertSame($before + 1, $this->events($property)->count());
    }

    public function test_a_second_agency_cannot_read_or_contaminate_the_first_agencys_report(): void
    {
        $a = $this->agencyWithLanding('Alpha Photo Booth');
        $b = $this->agencyWithLanding('Beta Photo Booth', 'dogs', false);
        $this->saveScript($a['workspace']);

        $keyA = $this->pageConfig($this->withHeader('User-Agent', self::PHONE_UA)->get('/photobooth')->assertOk()->getContent())['key'];
        $keyB = $this->pageConfig($this->withHeader('User-Agent', self::PHONE_UA)->get('/dogs')->assertOk()->getContent())['key'];
        $this->assertNotSame($keyA, $keyB);
        $propA = WebsiteAnalyticsProperty::query()->where('public_key', $keyA)->firstOrFail();
        $propB = WebsiteAnalyticsProperty::query()->where('public_key', $keyB)->firstOrFail();

        $campaignA = $this->managedCampaign($a['workspace'], ['name' => 'Alpha blast']);
        $tokenA = app(AnalyticsCampaignLinks::class)->tokenFor($campaignA)->token;

        // A's campaign value presented to B's page is not a campaign of B: B records an unattributed visit, A records nothing.
        $this->beacon($this->pv($keyB, $this->ids(), '/dogs', $tokenA))->assertNoContent();
        $this->assertSame(1, $this->events($propB, 'pageview')->count());
        $this->assertNull($this->events($propB)->first()->campaign_id);
        $this->assertSame(0, $this->events($propA)->count());

        // A's calendar cannot be reported under B's key, and a booking carrying B's key for A's calendar is not B's.
        $ids = $this->ids();
        $this->beacon(['k' => $keyB, 't' => 'calendar_open', 'v' => $ids['v'], 's' => $ids['s'], 'e' => bin2hex(random_bytes(16)), 'p' => '/dogs', 'bt' => $a['type']->public_booking_uuid])->assertNoContent();
        $this->assertSame(0, $this->events($propB, 'calendar_open')->count());
        [$date, $time] = $this->firstSlot($a['type']);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->postJson(route('public.booking.store', [$a['type']->public_booking_uuid]), $this->bookingPayload($date, $time, ['mg_pk' => $keyB, 'mg_v' => $ids['v'], 'mg_s' => $ids['s']]))->assertStatus(201);
        $this->assertSame(0, $this->events($propB, 'booking_confirmed')->count(), 'a booking of A is never B\'s');
        $this->assertSame(0, $this->events($propA, 'booking_confirmed')->count(), 'and it carried no context of A, so it is simply not attributed');

        // B's dashboard shows only B; B cannot open A's, nor learn A's campaigns through a filter.
        $html = $this->dashboard($b)->assertOk()->getContent();
        $this->assertSame(1, $this->kpi($html, 'visits'));
        $this->assertStringNotContainsString('Alpha blast', $html);
        $filtered = $this->dashboard($b, '?campaign=' . $tokenA . '&landing=' . urlencode('/photobooth'))->assertOk()->getContent();
        $this->assertSame(1, $this->kpi($filtered, 'visits'), 'a foreign campaign value is ignored, not honoured');
        $this->authenticateAs($b['customer']);
        $this->get(route('customer.workspaces.agency.white-label.analytics', [$a['workspace']->uid]))->assertNotFound();
    }

    public function test_the_dashboard_reports_when_collection_started_and_never_presents_unmeasured_days_as_zero(): void
    {
        $a = $this->agencyWithLanding('Motion Grove Photo Booth');
        $key = $this->pageConfig($this->withHeader('User-Agent', self::PHONE_UA)->get('/photobooth')->assertOk()->getContent())['key'];
        $property = WebsiteAnalyticsProperty::query()->where('public_key', $key)->firstOrFail();

        $report = app(AnalyticsReport::class)->build($property, '30', 'UTC');
        $this->assertTrue($report['partially_measured'], 'collection began today, so the 30 day range is mostly unmeasured');
        $this->assertSame('2027-02-25', substr($report['collection_started_at'], 0, 10));

        $html = $this->dashboard($a, '?range=30')->assertOk()->getContent();
        $this->assertStringContainsString('not measured', $html);
        $this->assertStringContainsString('Feb 25, 2027', $html);
        $today = $this->dashboard($a, '?range=today')->assertOk()->getContent();
        $this->assertSame(24, substr_count($today, 'class="wa-bar'), 'hourly buckets for Today');
        $tz = (string) ($a['business']->timezone ?: config('app.timezone'));
        $expectedHours = collect(app(AnalyticsReport::class)->build($property, 'today', $tz)['series'])->where('measured', false)->count();
        $this->assertGreaterThan(0, $expectedHours, 'collection began part-way through today');
        $this->assertLessThan(24, $expectedHours);
        $this->assertSame($expectedHours, substr_count($today, 'class="wa-bar is-unmeasured"'), 'the hours before collection began are drawn as not measured, never as zero bars');
        $this->assertSame(29, substr_count($html, 'class="wa-bar is-unmeasured"'), 'the 29 days before today of the 30 day range');
        $this->assertSame(7, substr_count($this->dashboard($a)->getContent(), 'class="wa-bar'), 'daily buckets, default 7 days');
        $this->assertSame(90, substr_count($this->dashboard($a, '?range=90')->getContent(), 'class="wa-bar'));
        $this->assertSame('7', app(AnalyticsReport::class)->build($property, 'bogus', 'UTC')['range']);
    }

    public function test_hourly_and_daily_buckets_follow_the_account_timezone(): void
    {
        $a = $this->agencyWithLanding('Motion Grove Photo Booth');
        $key = $this->pageConfig($this->withHeader('User-Agent', self::PHONE_UA)->get('/photobooth')->assertOk()->getContent())['key'];
        $property = WebsiteAnalyticsProperty::query()->where('public_key', $key)->firstOrFail();

        // 03:30 UTC on Feb 25 is 22:30 on Feb 24 in New York (a different calendar day and hour bucket).
        Carbon::setTestNow('2027-02-25 03:30:00 UTC');
        $this->beacon($this->pv($key, $this->ids()))->assertNoContent();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');

        $ny = app(AnalyticsReport::class)->build($property, '7', 'America/New_York');
        $utc = app(AnalyticsReport::class)->build($property, '7', 'UTC');
        $day = fn (array $r, string $label) => collect($r['series'])->firstWhere('label', $label)['visits'];
        $this->assertSame(1, $day($ny, 'Feb 24'));
        $this->assertSame(1, $day($utc, 'Feb 25'));
        $today = app(AnalyticsReport::class)->build($property, 'today', 'America/New_York');
        $this->assertSame(0, $today['totals']['visits'], 'the visit was yesterday in New York, so it is not in Today');

        // Recent activity is newest first by when it happened, not by row order: a later-written, older event goes below.
        $late = $this->ids();
        $this->beacon($this->pv($key, $late))->assertNoContent();            // now (12:00 UTC)
        WebsiteAnalyticsEvent::query()->where('path', '/photobooth')->orderBy('id')->first()->forceFill(['occurred_at' => '2027-02-25 03:30:00'])->save();
        $recent = app(AnalyticsReport::class)->build($property, '7', 'UTC')['recent'];
        $this->assertSame('12:00 pm', substr($recent[0]['at_label'], -8));
        $this->assertSame('3:30 am', substr($recent[1]['at_label'], -7));
    }

    public function test_an_invalid_booking_counts_nothing_and_a_booking_with_no_context_is_not_attributed(): void
    {
        $a = $this->agencyWithLanding('Motion Grove Photo Booth');
        $config = $this->pageConfig($this->withHeader('User-Agent', self::PHONE_UA)->get('/photobooth')->assertOk()->getContent());
        $property = WebsiteAnalyticsProperty::query()->where('public_key', $config['key'])->firstOrFail();
        $mg = ['mg_pk' => $config['key'], 'mg_v' => $this->ids()['v'], 'mg_s' => $this->ids()['s']];

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->postJson(route('public.booking.store', [$a['type']->public_booking_uuid]), $mg + ['date' => '2027-03-01', 'time' => '10:00'])->assertStatus(422);
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, $this->events($property)->count());

        [$date, $time] = $this->firstSlot($a['type']);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->postJson(route('public.booking.store', [$a['type']->public_booking_uuid]), $this->bookingPayload($date, $time))->assertStatus(201);
        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(0, $this->events($property, 'booking_confirmed')->count(), 'no analytics context: the booking is real but unattributed');
    }

    public function test_a_calendar_opened_on_another_route_or_tab_carries_the_handoff_and_counts_there(): void
    {
        // No embedded calendar: the landing page's Book a Call button opens the standalone calendar page (another route / a new tab).
        $a = $this->agencyWithLanding('Motion Grove Photo Booth', 'photobooth', false);
        $key = $this->pageConfig($this->withHeader('User-Agent', self::PHONE_UA)->get('/photobooth')->assertOk()->getContent())['key'];
        $uuid = $a['type']->public_booking_uuid;
        $landing = $this->withHeader('User-Agent', self::PHONE_UA)->get('/photobooth')->assertOk()->getContent();
        $this->assertStringContainsString(route('public.booking.show', [$uuid]), $landing, 'the landing page links to the standalone calendar');
        $this->assertStringNotContainsString('data-role="public-scheduler"', $landing);

        // Without the handoff the standalone calendar page carries no analytics (it cannot tell whose visit this is).
        $plain = $this->get(route('public.booking.show', [$uuid]))->assertOk()->getContent();
        $this->assertStringNotContainsString('mg-analytics-config', $plain);
        // With a forged key for someone else's calendar, nothing is emitted either.
        $b = $this->agencyWithLanding('Beta Booth', 'dogs', false);
        $keyB = $this->pageConfig($this->withHeader('User-Agent', self::PHONE_UA)->get('/dogs')->assertOk()->getContent())['key'];
        $this->assertStringNotContainsString('mg-analytics-config', $this->get(route('public.booking.show', [$uuid]) . '?mgp=' . $keyB)->assertOk()->getContent());

        $html = $this->get(route('public.booking.show', [$uuid]) . '?mgp=' . $key . '&mgv=' . $this->ids()['v'])->assertOk()->getContent();
        $config = $this->pageConfig($html);
        $this->assertSame($key, $config['key']);
        $this->assertSame('handoff', $config['mode']);
        $this->assertFalse($config['require_consent'], 'the origin that collected already had consent; this origin must not wait for an answer it cannot get');
        $this->assertSame($uuid, $config['booking_type']);
        $this->assertStringContainsString('data-role="public-scheduler"', $html);

        // The calendar open from that other route/tab is attributed to the same site.
        $ids = $this->ids();
        $this->beacon(['k' => $key, 't' => 'calendar_open', 'v' => $ids['v'], 's' => $ids['s'], 'e' => bin2hex(random_bytes(16)), 'p' => '/book/' . $uuid, 'bt' => $uuid])->assertNoContent();
        $property = WebsiteAnalyticsProperty::query()->where('public_key', $key)->firstOrFail();
        $this->assertSame(1, $this->events($property, 'calendar_open')->count());
        $this->assertSame('/photobooth', $this->events($property, 'calendar_open')->first()->landing, 'the landing page filter still applies on another route');
    }

    public function test_a_malformed_handoff_never_breaks_the_calendar_page_and_a_late_business_is_linked(): void
    {
        $a = $this->agencyWithLanding('Motion Grove Photo Booth', 'photobooth', false);
        $uuid = $a['type']->public_booking_uuid;
        $this->get(route('public.booking.show', [$uuid]) . '?mgp[]=x')->assertOk();

        // A property created before the Agency's Business existed is linked to it on the next render.
        $key = $this->pageConfig($this->withHeader('User-Agent', self::PHONE_UA)->get('/photobooth')->assertOk()->getContent())['key'];
        $property = WebsiteAnalyticsProperty::query()->where('public_key', $key)->firstOrFail();
        $property->forceFill(['business_id' => null])->save();
        $this->get('/photobooth')->assertOk();
        $this->assertSame((int) $a['business']->id, (int) $property->fresh()->business_id);
    }

    public function test_a_legacy_follow_up_link_is_tagged_with_its_campaign(): void
    {
        $a = $this->agencyWithLanding('Motion Grove Photo Booth');
        $campaign = $this->managedCampaign($a['workspace'], ['name' => 'Legacy blast']);
        $text = "Just following up — here's the booking link again: " . url('/photobooth');
        $tagged = app(AnalyticsCampaignLinks::class)->tagForWorkspace($text, (int) $a['workspace']->id, $campaign);
        $this->assertStringContainsString('/photobooth?mgc=c', $tagged);
        $this->assertSame($text, app(AnalyticsCampaignLinks::class)->tagForWorkspace($text, (int) $a['workspace']->id, null), 'no campaign, no tag');
    }

    public function test_the_script_contract_consent_privacy_handoff_and_no_booking_claims(): void
    {
        $js = preg_replace('#/\*.*?\*/#s', '', file_get_contents(public_path('js/website-analytics.js')));

        foreach ([
            "consent() === 'denied'", 'navigator.globalPrivacyControl', "navigator.doNotTrack === '1'", 'config.require_consent',
            'document.prerendering', "document.visibilityState === 'visible'", 'IntersectionObserver',
            'event.origin !== origin', 'contentWindow === event.source', "data.deployment_uid !== frame.getAttribute('data-deployment-uid')", "send('form_inquiry'",
            "'mgp'", "'mgv'", "'mgs'", "'mgc'", 'mg_pk',
        ] as $needle) {
            $this->assertStringContainsString($needle, str_replace('frames[i].contentWindow === event.source', 'contentWindow === event.source', $js), $needle);
        }
        // A browser never reports a booking; no personal data is read; nothing is stored beyond random ids.
        $this->assertStringNotContainsString("send('booking", $js);
        $this->assertStringContainsString('own.indexOf(url.origin) === -1', $js, 'booking links to third-party hosts are never decorated');
        foreach (['email', 'phone', 'first_name', 'last_name'] as $personal) {
            $this->assertStringNotContainsString($personal, strtolower($js), 'the script never reads or sends a contact detail: ' . $personal);
        }
    }
}
