<?php

namespace Tests\Feature\WebsiteAnalytics;

use App\Enums\Forms\FormDeploymentSource;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Library\Website\Tracking\WebsiteTrackingSettings;
use App\Library\WebsiteAnalytics\AnalyticsReport;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\BusinessLocation;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use App\Models\Website;
use App\Models\WebsiteAnalyticsEvent;
use App\Models\WebsiteAnalyticsProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Growth → Website → Analytics, end to end over HTTP: a published Growth site → Check availability → its real embedded form →
 * a STORED submission, reported as one confirmed inquiry for that Business only. Invalid or abandoned submissions are zero.
 */
class GrowthWebsiteFunnelAnalyticsTest extends TestCase
{
    use CreatesContentFixtures, CreatesFormsFixtures {
        CreatesContentFixtures::platformAdminId insteadof CreatesFormsFixtures;
        CreatesContentFixtures::ensureRequiredAppConfigRowsExist insteadof CreatesFormsFixtures;
        CreatesContentFixtures::createCustomer insteadof CreatesFormsFixtures;
        CreatesContentFixtures::businessAttributes insteadof CreatesFormsFixtures;
        CreatesContentFixtures::createBusinessWithWorkspace insteadof CreatesFormsFixtures;
        CreatesContentFixtures::assignPaidPlanFixture insteadof CreatesFormsFixtures;
    }
    use RefreshDatabase;

    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';

    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->flush();
    }

    /** @return array{customer: mixed, business: Business, workspace: mixed, website: Website, deployment: FormDeployment, host: string} */
    private function growthSite(bool $form = true): array
    {
        [$customer, $business, $workspace, $website] = $this->photoBoothContentTenant();
        $this->formsLocation($business, 'Chicago Studio');
        $this->formsPipeline($business);
        $host = 'http://' . $website->domains()->value('domain');
        $deployment = null;

        if ($form) {
            $formModel = $this->makeForm($business, ['name' => 'Check Availability'], true);
            (new BusinessBlueprintComponentInstallation())->forceFill([
                'business_id' => $business->id, 'blueprint_id' => $this->blueprintId(), 'component_key' => 'pb3_form_lead', 'component_type' => 'form',
                'installed_from_version' => 1, 'state' => 'installed', 'required_feature_key' => 'forms', 'installed_record_id' => $formModel->id,
            ])->save();
            $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
            $deployment = app(FormManager::class)->setDeployment($business, $formModel, $location, true, FormDeploymentSource::Website);

            // Publish the form onto the contact page ("Check availability") of the live snapshot.
            $revision = $website->publishedRevision;
            $snapshot = $revision->snapshot;
            $resolved = app(\App\Library\Website\Forms\WebsiteFormsModuleReferences::class)->resolve($business, $deployment->uid);
            foreach ($snapshot['pages'] as &$page) {
                if (($page['slug'] ?? null) === 'photo-booth-contact') {
                    $page['sections'][] = ['type' => 'forms_module_form', 'data' => ['heading' => 'Check availability', 'forms_module_deployment_uid' => $deployment->uid, 'resolved' => $resolved]];
                }
            }
            unset($page);
            $revision->forceFill(['snapshot' => $snapshot])->save();
            app('cache')->flush();
        }

        return compact('customer', 'business', 'workspace', 'website', 'deployment', 'host');
    }

    private function blueprintId(): int
    {
        return (int) (\App\Models\NicheBlueprint::query()->value('id') ?? \App\Models\NicheBlueprint::create(['key' => 'test_niche', 'display_name' => 'Test niche', 'broad_industry' => 'photo_booth_service'])->id);
    }

    /** @return array<string, mixed> */
    private function config(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="mg-analytics-config">(.*?)</script>#s', $html, $m), 'the published page carries the analytics config');

        return json_decode($m[1], true);
    }

    /** The beacon goes where the page told it to: the platform host, absolute, because a customer's own domain accepts no POST. */
    private function beacon(array $payload, string $ua = self::UA)
    {
        return $this->call('POST', rtrim((string) config('app.url'), '/') . '/analytics/collect', [], [], [], ['HTTP_USER_AGENT' => $ua, 'CONTENT_TYPE' => 'text/plain'], json_encode($payload));
    }

    private function base(string $key, string $kind, array $ids, array $extra = []): array
    {
        return array_merge(['k' => $key, 't' => $kind, 'v' => $ids['v'], 's' => $ids['s'], 'e' => bin2hex(random_bytes(16)), 'p' => '/photo-booth-contact'], $extra);
    }

    private function ids(): array
    {
        return ['v' => bin2hex(random_bytes(16)), 's' => bin2hex(random_bytes(16))];
    }

    /** A platform-host URL. After a request to a customer's own domain Laravel would otherwise build links on THAT host. */
    private function platform(string $name, array $parameters = []): string
    {
        return rtrim((string) config('app.url'), '/') . route($name, $parameters, false);
    }

    private function property(string $key): WebsiteAnalyticsProperty
    {
        return WebsiteAnalyticsProperty::query()->where('public_key', $key)->firstOrFail();
    }

    private function eventCount(WebsiteAnalyticsProperty $p, string $kind): int
    {
        return WebsiteAnalyticsEvent::query()->where('property_id', $p->id)->where('kind', $kind)->count();
    }

    private function kpi(string $html, string $metric): int
    {
        $this->assertSame(1, preg_match('#data-metric="' . $metric . '"><div class="v">([0-9,]+)</div>#', $html, $m), "KPI {$metric} is on the screen");

        return (int) str_replace(',', '', $m[1]);
    }

    private function dash(array $g, string $query = '')
    {
        $this->authenticateAs($g['customer']);

        return $this->get($this->platform('customer.workspaces.businesses.website.analytics.index', [$g['workspace']->uid, $g['business']->uid]) . $query);
    }

    // ------------------------------------------------------------------------------------------------------------------

    public function test_check_availability_to_a_stored_form_inquiry_is_one_confirmed_inquiry_for_that_business(): void
    {
        $g = $this->growthSite();

        // The published page carries the config and the real embedded lead form (marked for the verified signal).
        $html = $this->withHeader('User-Agent', self::UA)->get($g['host'] . '/photo-booth-contact')->assertOk()->getContent();
        $config = $this->config($html);
        $this->assertStringContainsString('data-mg-lead-form="1"', $html);
        $this->assertFalse($config['require_consent'], 'no Tracking setting, so no consent bar is configured');
        $this->assertSame(rtrim((string) config('app.url'), '/') . '/analytics/collect', $config['endpoint'], 'a customer domain reports to the platform host');
        $this->assertStringNotContainsString('googletagmanager', $html, 'native analytics needs no Google account or tag');
        $property = $this->property($config['key']);
        $this->assertSame((int) $g['business']->id, (int) $property->business_id);
        $this->assertSame((int) $g['workspace']->id, (int) $property->workspace_id);
        $this->assertSame(0, WebsiteAnalyticsEvent::query()->count(), 'serving the page records nothing');

        $ids = $this->ids();
        $this->beacon($this->base($config['key'], 'pageview', $ids))->assertNoContent();
        $this->beacon($this->base($config['key'], 'form_open', $ids))->assertNoContent();
        $this->beacon($this->base($config['key'], 'form_open', $ids))->assertNoContent();
        $this->assertSame(1, $this->eventCount($property, 'form_open'), 'one form open per visit');

        // An abandoned form and an invalid submission are zero inquiries (a refused post never reaches the thank-you page).
        $this->post($this->platform('public.forms.submit', [$g['deployment']->uid]), [FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($g['deployment'])])->assertStatus(422);
        $this->assertSame(0, FormSubmission::query()->count());
        $this->beacon($this->base($config['key'], 'form_inquiry', $ids, ['sub' => (string) \Illuminate\Support\Str::uuid()]))->assertNoContent();
        $this->assertSame(0, $this->eventCount($property, 'form_inquiry'), 'an unknown submission id is no inquiry');

        // A real, valid submission through the real public form endpoint, then the thank-you announcement.
        $input = $this->submitInput($g['deployment']);
        $response = $this->post($this->platform('public.forms.submit', [$g['deployment']->uid]), $input)->assertRedirect();
        $submission = FormSubmission::query()->latest('id')->firstOrFail();
        $thanks = $this->get($this->platform('public.forms.thanks', ['deploymentUid' => $g['deployment']->uid, 's' => $submission->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('motiongrove:lead_submitted', $thanks);

        $this->beacon($this->base($config['key'], 'form_inquiry', $ids, ['sub' => $submission->uid]))->assertNoContent();
        $this->beacon($this->base($config['key'], 'form_inquiry', $ids, ['sub' => $submission->uid]))->assertNoContent(); // replay / reload
        $this->assertSame(1, $this->eventCount($property, 'form_inquiry'));
        $this->assertSame(0, $this->eventCount($property, 'booking_confirmed'), 'a form inquiry is never a booking');

        // Replaying the same form post (double click / retry) stores no second submission and no second inquiry.
        $this->post($this->platform('public.forms.submit', [$g['deployment']->uid]), $input)->assertRedirect();
        $this->assertSame(1, FormSubmission::query()->count());

        $html = $this->dash($g)->assertOk()->assertSee('data-testid="website-analytics"', false)->getContent();
        $this->assertSame(1, $this->kpi($html, 'visits'));
        $this->assertSame(1, $this->kpi($html, 'page_views'));
        $this->assertSame(1, $this->kpi($html, 'form_opens'));
        $this->assertSame(1, $this->kpi($html, 'inquiries'));
        $this->assertStringContainsString('Check availability', $html);
        $this->assertStringContainsString('Confirmed inquiries', $html);
        $this->assertStringContainsString('Sent an inquiry', $html);
        $this->assertStringContainsString('/photo-booth-contact', $html);
        $this->assertStringContainsString('Direct / unknown', $html);
    }

    public function test_another_tenants_submission_or_site_can_never_count_for_this_business_and_its_report_stays_private(): void
    {
        $g = $this->growthSite();
        $other = $this->growthSite();

        $configA = $this->config($this->withHeader('User-Agent', self::UA)->get($g['host'] . '/photo-booth-contact')->assertOk()->getContent());
        $configB = $this->config($this->withHeader('User-Agent', self::UA)->get($other['host'] . '/photo-booth-contact')->assertOk()->getContent());
        $this->assertNotSame($configA['key'], $configB['key']);
        $propA = $this->property($configA['key']);
        $propB = $this->property($configB['key']);

        // B has a real, stored submission; A's key cannot claim it.
        $this->post($this->platform('public.forms.submit', [$other['deployment']->uid]), $this->submitInput($other['deployment']))->assertRedirect();
        $foreign = FormSubmission::query()->where('business_id', $other['business']->id)->firstOrFail();
        $ids = $this->ids();
        $this->beacon($this->base($configA['key'], 'form_inquiry', $ids, ['sub' => $foreign->uid]))->assertNoContent();
        $this->assertSame(0, $this->eventCount($propA, 'form_inquiry'), 'another tenant\'s submission is not this tenant\'s inquiry');
        $this->beacon($this->base($configB['key'], 'form_inquiry', $ids, ['sub' => $foreign->uid]))->assertNoContent();
        $this->assertSame(1, $this->eventCount($propB, 'form_inquiry'));

        // Reports are separated, and A's owner cannot open B's report or learn anything from the URL.
        $this->assertSame(0, $this->kpi($this->dash($g)->assertOk()->getContent(), 'inquiries'));
        $this->assertSame(1, $this->kpi($this->dash($other)->assertOk()->getContent(), 'inquiries'));
        $this->authenticateAs($g['customer']);
        $this->get($this->platform('customer.workspaces.businesses.website.analytics.index', [$other['workspace']->uid, $other['business']->uid]))->assertStatus(404);
        $this->get($this->platform('customer.workspaces.businesses.website.analytics.index', [$g['workspace']->uid, $other['business']->uid]))->assertStatus(404);
    }

    public function test_consent_configuration_is_respected_and_native_analytics_works_without_any_google_tag(): void
    {
        $g = $this->growthSite();
        $settings = app(WebsiteTrackingSettings::class);

        // No Tracking settings: nothing to consent to, and the Tracking loader is not on the page.
        $html = $this->withHeader('User-Agent', self::UA)->get($g['host'] . '/')->assertOk()->getContent();
        $this->assertFalse($this->config($html)['require_consent']);
        $this->assertStringNotContainsString('mg-tracking-config', $html);

        // Consent required and NO third-party tag: analytics shows its own consent bar and waits for the visitor.
        $settings->save($g['website'], '*', ['require_consent' => true, 'head_snippet' => '<meta name="x" content="y">']);
        $settings->save($g['website'], '*', ['require_consent' => true, 'meta_pixel_id' => '', 'google_tag_id' => '', 'head_snippet' => '']); // all empty removes the row
        app('cache')->flush();
        \App\Models\WebsiteTrackingSetting::query()->create(['website_id' => $g['website']->id, 'scope_key' => '*', 'is_enabled' => true, 'require_consent' => true]);
        $config = $this->config($this->withHeader('User-Agent', self::UA)->get($g['host'] . '/')->assertOk()->getContent());
        $this->assertTrue($config['require_consent']);
        $this->assertTrue($config['own_banner'], 'no tracking loader on the page, so analytics asks itself');

        // Consent required WITH a Google tag: the existing Tracking loader owns the banner; analytics only listens.
        \App\Models\WebsiteTrackingSetting::query()->where('website_id', $g['website']->id)->update(['google_tag_id' => 'G-ABC123XYZ']);
        $html = $this->withHeader('User-Agent', self::UA)->get($g['host'] . '/')->assertOk()->getContent();
        $config = $this->config($html);
        $this->assertTrue($config['require_consent']);
        $this->assertFalse($config['own_banner']);
        $this->assertStringContainsString('mg-tracking-config', $html, 'GA4/GTM/Meta integration keeps working beside it');

        // Consent not required once the owner switches it off.
        \App\Models\WebsiteTrackingSetting::query()->where('website_id', $g['website']->id)->update(['require_consent' => false]);
        $this->assertFalse($this->config($this->withHeader('User-Agent', self::UA)->get($g['host'] . '/')->assertOk()->getContent())['require_consent']);

        // The loader tells the analytics script when the visitor answers the bar.
        $this->assertStringContainsString("new CustomEvent('mg:consent'", file_get_contents(public_path('js/website-tracking.js')));
    }

    public function test_previews_unpublished_sites_and_scanners_are_never_counted_for_a_growth_site(): void
    {
        $g = $this->growthSite();
        $key = $this->config($this->withHeader('User-Agent', self::UA)->get($g['host'] . '/')->assertOk()->getContent())['key'];
        $property = $this->property($key);

        $page = $g['website']->pages()->create(['title' => 'Preview me', 'slug' => 'preview-me', 'is_home' => false, 'sort_order' => 9, 'noindex' => false, 'sections' => []]);
        $this->authenticateAs($g['customer']);
        $preview = $this->get($this->platform('customer.workspaces.businesses.website.preview', [$g['workspace']->uid, $g['business']->uid, $page->uid]))->assertOk()->getContent();
        $this->assertStringNotContainsString('mg-analytics-config', $preview, 'a draft preview never emits analytics');

        foreach (['facebookexternalhit/1.1', 'Mozilla/5.0 (compatible; bingbot/2.0)', 'WhatsApp/2.23'] as $ua) {
            $this->beacon($this->base($key, 'pageview', $this->ids()), $ua)->assertNoContent();
        }
        $this->assertSame(0, $this->eventCount($property, 'pageview'));

        $g['website']->forceFill(['status' => \App\Enums\Website\WebsiteStatus::Draft])->save();
        app('cache')->flush();
        $this->beacon($this->base($key, 'pageview', $this->ids()))->assertNoContent();
        $this->assertSame(0, $this->eventCount($property, 'pageview'), 'an unpublished site accepts nothing');
    }

    public function test_a_site_without_a_lead_form_shows_traffic_only_and_says_so(): void
    {
        $g = $this->growthSite(false);
        $config = $this->config($this->withHeader('User-Agent', self::UA)->get($g['host'] . '/')->assertOk()->getContent());
        $this->beacon($this->base($config['key'], 'pageview', $this->ids(), ['p' => '/']))->assertNoContent();

        $html = $this->dash($g)->assertOk()->getContent();
        $this->assertSame(1, $this->kpi($html, 'visitors'));
        $this->assertStringContainsString('data-testid="no-goal-note"', $html);
        $this->assertStringNotContainsString('data-metric="inquiries"', $html);
        $this->assertMatchesRegularExpression('#<a href="[^"]*/website/analytics"[^>]*>\s*Analytics#', $html, 'the Website tab strip names Analytics');
        $this->assertMatchesRegularExpression('#<a href="[^"]*/website/tracking"[^>]*>\s*Tracking#', $html, 'Tracking stays available for configuration');
    }

    public function test_a_site_that_was_never_viewed_is_reported_as_not_measured_not_as_zero(): void
    {
        $g = $this->growthSite(false);
        $html = $this->dash($g)->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="analytics-not-started"', $html);
        $this->assertStringContainsString('not even zeros', $html);
        $this->assertStringNotContainsString('data-metric="visits"', $html);
    }
}
