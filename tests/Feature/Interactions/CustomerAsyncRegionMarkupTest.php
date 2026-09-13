<?php

namespace Tests\Feature\Interactions;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Analytics\AnalyticsDateRange;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Controls that only change what a customer screen SHOWS — a date range, a
 * search, a page number — update one region in place (window.AsyncRegion,
 * resources/js/core/async-region.js) instead of reloading the page.
 *
 * The browser behaviour is proven by a browser smoke. What these tests pin is
 * the server-side half of the contract the module relies on:
 *
 *   - every in-place control is an ordinary GET form or a real link, so the
 *     no-JavaScript fallback, a refresh and a bookmark all request the same URL
 *     the in-place update requested;
 *   - the page for that URL contains the region under the same name, so the
 *     module can take it out of the response;
 *   - only the controls that are meant to be in-place are marked: a POST form
 *     beside them, and every link of every other page, stay ordinary.
 */
class CustomerAsyncRegionMarkupTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
    }

    public function test_the_customer_shell_loads_the_module_once_after_app_js_with_a_localized_error_message(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Shell Venue', 'Shell Account');
        $this->authenticateAs($customer);

        $html = $this->get(route('user.home'))->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('#<script src="[^"]*/js/core/async-region\.js"#', $html));
        $this->assertStringContainsString(
            'data-error-message="' . e(__('locale.exceptions.something_went_wrong')) . '"',
            $html
        );
        $this->assertLessThan(
            strpos($html, '/js/core/async-region.js'),
            strpos($html, '/js/core/app.js'),
            'Loaded after the theme scripts and before any page script that listens for its updates.'
        );
    }

    public function test_business_home_performance_band_is_a_region_updated_by_its_own_get_range_form(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Home Venue', 'Home Account');
        $this->authenticateAs($customer);

        $xpath = $this->xpath($this->get(route('user.home'))->assertOk()->getContent());

        $regions = $xpath->query('//*[@data-async-region="business-performance"]');
        $this->assertSame(1, $regions->length, 'Exactly one Business performance region.');

        /** @var DOMElement $band */
        $band = $regions->item(0);
        $this->assertSame('headlines', $band->getAttribute('data-band'));

        $form = $this->onlyElement($xpath, './/form[@data-async-form="business-performance"]', $band);
        $this->assertSame('get', strtolower($form->getAttribute('method')), 'An ordinary GET: the no-JavaScript fallback.');
        $this->assertSame(route('user.home'), $form->getAttribute('action'));
        $this->assertSame(1, $xpath->query('.//select[@name="range"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//button[@type="submit"]', $form)->length);

        // Everything that follows the range lives inside the region it updates.
        $this->assertSame(1, $xpath->query('.//*[@data-role="range-caption"]', $band)->length);
        $this->assertSame(1, $xpath->query('.//*[@data-role="chart-new-contacts"][@data-series-url]', $band)->length);

        // A POST beside it (Explain this change) is never handled in place.
        foreach ($xpath->query('//form[@data-async-form]') as $marked) {
            $this->assertSame('get', strtolower($marked->getAttribute('method')));
        }
    }

    public function test_business_home_range_url_renders_that_range_in_the_region_so_refresh_and_bookmark_match(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Bookmark Venue', 'Bookmark Account');
        $this->authenticateAs($customer);

        // The exact URL the in-place update pushes: the form's own GET serialisation.
        $url = route('user.home') . '?range=' . AnalyticsDateRange::PRESET_LAST_MONTH . '&start=&end=';
        $xpath = $this->xpath($this->get($url)->assertOk()->getContent());

        $band = $this->onlyElement($xpath, '//*[@data-async-region="business-performance"]');
        $caption = trim(preg_replace('/\s+/', ' ', $this->onlyElement($xpath, './/*[@data-role="range-caption"]', $band)->textContent));
        $this->assertStringContainsString('Last month', $caption);
        $this->assertStringContainsString('Aug 1', $caption);

        $selected = $this->onlyElement($xpath, './/select[@name="range"]/option[@selected]', $band);
        $this->assertSame(AnalyticsDateRange::PRESET_LAST_MONTH, $selected->getAttribute('value'));

        $series = $this->onlyElement($xpath, './/*[@data-role="chart-new-contacts"]', $band)->getAttribute('data-series-url');
        $this->assertStringContainsString('range=' . AnalyticsDateRange::PRESET_LAST_MONTH, $series, 'The chart the update re-mounts follows the new range.');
    }

    public function test_results_overview_and_campaigns_range_forms_update_their_own_regions(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Results Venue', 'Results Account');
        $this->authenticateAs($customer);

        $overview = route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]);
        $campaigns = route('customer.workspaces.businesses.analytics.campaigns', [$workspace->uid, $business->uid]);

        foreach (['results' => $overview, 'results-campaigns' => $campaigns] as $name => $page) {
            $xpath = $this->xpath($this->get($page . '?range=' . AnalyticsDateRange::PRESET_LAST_7_DAYS)->assertOk()->getContent());

            $region = $this->onlyElement($xpath, '//*[@data-async-region="' . $name . '"]');
            $form = $this->onlyElement($xpath, './/form[@data-async-form="' . $name . '"]', $region);

            $this->assertSame('get', strtolower($form->getAttribute('method')));
            $this->assertSame($page, $form->getAttribute('action'));
            $this->assertSame(
                AnalyticsDateRange::PRESET_LAST_7_DAYS,
                $this->onlyElement($xpath, './/select[@name="range"]/option[@selected]', $form)->getAttribute('value')
            );
        }

        // The overview's charts re-mount from the series URL the NEW region carries.
        $xpath = $this->xpath($this->get($overview . '?range=' . AnalyticsDateRange::PRESET_LAST_MONTH)->assertOk()->getContent());
        $this->assertStringContainsString(
            'range=' . AnalyticsDateRange::PRESET_LAST_MONTH,
            $this->onlyElement($xpath, '//*[@data-async-region="results"]')->getAttribute('data-series-url')
        );
    }

    public function test_contacts_search_and_pagination_update_the_list_in_place_with_the_search_box_outside_it(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Contacts Venue', 'Contacts Account');
        $this->contactsAdded($business, 30, '2026-09-02');
        $this->authenticateAs($customer);

        $people = route('customer.workspaces.businesses.people.index', [$workspace->uid, $business->uid]);
        $xpath = $this->xpath($this->get($people)->assertOk()->getContent());

        $region = $this->onlyElement($xpath, '//*[@data-async-region="contacts-list"]');
        $form = $this->onlyElement($xpath, '//form[@data-async-form="contacts-list"]');

        $this->assertSame('get', strtolower($form->getAttribute('method')));
        $this->assertSame($people, $form->getAttribute('action'));
        $this->assertSame(0, $xpath->query('.//form', $region)->length, 'What someone types is never inside the region that gets replaced.');
        $this->assertGreaterThan(0, $xpath->query('.//*[@data-role="contact-row"]', $region)->length);

        $next = $this->onlyElement($xpath, './/a[@rel="next"]', $region);
        $this->assertSame('contacts-list', $next->getAttribute('data-async-link'));
        $this->assertSame($people . '?page=2', $next->getAttribute('href'), 'Still a real link: new tab, refresh and no-JavaScript work.');

        // A contact's own page is a real navigation, never intercepted.
        foreach ($xpath->query('.//*[@data-role="contact-row"]//a', $region) as $link) {
            $this->assertFalse($link->hasAttribute('data-async-link'));
        }

        // The searched URL renders the matching list in the same region.
        $xpath = $this->xpath($this->get($people . '?q=zzznomatch')->assertOk()->getContent());
        $this->assertStringContainsString('No contacts match', $this->onlyElement($xpath, '//*[@data-async-region="contacts-list"]')->textContent);
        $this->assertSame('zzznomatch', $this->onlyElement($xpath, '//form[@data-async-form="contacts-list"]//input[@name="q"]')->getAttribute('value'));
    }

    public function test_pagination_links_are_ordinary_unless_a_region_is_named(): void
    {
        $paginator = new LengthAwarePaginator(range(1, 10), 30, 10, 2, ['path' => 'http://localhost/things']);

        $plain = Blade::render('<x-pagination :paginator="$paginator" />', ['paginator' => $paginator]);
        $this->assertStringNotContainsString('data-async-link', $plain);
        $this->assertSame(2, substr_count($plain, '<a '));

        $async = Blade::render('<x-pagination :paginator="$paginator" async-region="things" />', ['paginator' => $paginator]);
        $this->assertSame(2, substr_count($async, 'data-async-link="things"'), 'Both previous and next.');
        $this->assertStringContainsString('href="http://localhost/things?page=1"', $async);
        $this->assertStringContainsString('href="http://localhost/things?page=3"', $async);
    }

    public function test_the_range_control_is_an_ordinary_form_when_no_region_is_named(): void
    {
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_THIS_MONTH, 'America/New_York');

        $html = view('customer.business.analytics._range', ['range' => $range, 'formAction' => 'http://localhost/results'])->render();

        $this->assertStringContainsString('data-role="analytics-range"', $html);
        $this->assertStringNotContainsString('data-async-form', $html);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function onlyElement(DOMXPath $xpath, string $expression, ?DOMElement $context = null): DOMElement
    {
        $nodes = $context ? $xpath->query($expression, $context) : $xpath->query($expression);

        $this->assertSame(1, $nodes->length, "Expected exactly one match for {$expression}.");

        return $nodes->item(0);
    }
}
