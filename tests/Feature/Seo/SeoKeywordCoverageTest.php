<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoKeywordCoverageStatus;
use App\Library\Seo\SeoKeywordCoverageReader;
use App\Library\Seo\SeoPhraseNormalizer;
use App\Library\Seo\SeoPublishedContentReader;
use App\Models\Business;
use App\Models\SeoKeyword;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * Contract 18 §5.2.3 / §8.4 — Core keyword coverage against the PUBLISHED
 * Website snapshot: normalized, whole-word, page-counted, and pure.
 */
class SeoKeywordCoverageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    private int $nextId = 1;

    private function keyword(string $phrase): SeoKeyword
    {
        $keyword = new SeoKeyword(['phrase' => $phrase, 'phrase_normalized' => SeoPhraseNormalizer::normalize($phrase)]);
        $keyword->id = $this->nextId++;

        return $keyword;
    }

    private function coverageFor(Business $business, string ...$phrases): array
    {
        $keywords = array_map(fn (string $p) => $this->keyword($p), $phrases);
        $results = app(SeoKeywordCoverageReader::class)->forKeywords($keywords, app(SeoPublishedContentReader::class)->forBusiness($business));

        $byPhrase = [];
        foreach ($keywords as $k) {
            $byPhrase[$k->phrase] = $results[$k->id];
        }

        return $byPhrase;
    }

    private function business(): Business
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Core);

        return $business;
    }

    private function textSection(string $body, ?string $heading = null): array
    {
        return ['type' => 'text', 'data' => ['heading' => $heading, 'body' => $body]];
    }

    public function test_a_keyword_in_a_page_title_description_and_body_is_counted_by_page(): void
    {
        $business = $this->business();
        $this->publishWebsite($business, [
            $this->snapshotPage('a', 'Home', ['seo_title' => 'Best Bakery in Tampa', 'meta_description' => 'Fresh bread daily.'], [$this->textSection('We are a local shop.')], true),
            $this->snapshotPage('b', 'About', ['meta_description' => 'The best bakery story.'], [$this->textSection('Our best bakery began in 1990.')]),
            $this->snapshotPage('c', 'Contact', [], [$this->textSection('Call us.')]),
        ]);

        $r = $this->coverageFor($business, 'best bakery')['best bakery'];

        $this->assertSame(SeoKeywordCoverageStatus::Covered, $r->status);
        $this->assertSame(3, $r->pagesTotal);
        $this->assertSame(1, $r->titlePages);
        $this->assertSame(1, $r->descriptionPages);
        $this->assertSame(1, $r->bodyPages);
    }

    public function test_a_phrase_that_appears_nowhere_is_not_covered(): void
    {
        $business = $this->business();
        $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', ['seo_title' => 'Plumbing'], [$this->textSection('Pipes and drains.')], true)]);

        $r = $this->coverageFor($business, 'best bakery')['best bakery'];

        $this->assertSame(SeoKeywordCoverageStatus::NotCovered, $r->status);
        $this->assertSame([1, 0, 0, 0], [$r->pagesTotal, $r->titlePages, $r->descriptionPages, $r->bodyPages]);
    }

    public function test_the_page_title_falls_back_when_there_is_no_seo_title(): void
    {
        $business = $this->business();
        $this->publishWebsite($business, [$this->snapshotPage('a', 'Best Bakery', [], [], true)]);

        $this->assertSame(1, $this->coverageFor($business, 'best bakery')['best bakery']->titlePages);
    }

    public function test_matching_uses_the_same_normalization_on_both_sides(): void
    {
        $business = $this->business();
        $this->publishWebsite($business, [
            $this->snapshotPage('a', 'Home', ['seo_title' => "BEST\u{00A0}BAKERY"], [$this->textSection("the best\n   bakery   in town", 'ＣＡＦÉ')], true),
        ]);

        $r = $this->coverageFor($business, 'Best Bakery', 'CAFÉ', 'café');

        $this->assertSame(SeoKeywordCoverageStatus::Covered, $r['Best Bakery']->status);
        $this->assertSame(1, $r['Best Bakery']->titlePages);
        $this->assertSame(1, $r['Best Bakery']->bodyPages, 'Whitespace runs and line breaks collapse.');
        $this->assertSame(1, $r['CAFÉ']->bodyPages, 'Fullwidth text NFKC-normalizes and case-folds.');
        $this->assertSame(1, $r['café']->bodyPages);
    }

    public function test_matching_is_by_whole_words_never_inside_a_word(): void
    {
        $business = $this->business();
        $this->publishWebsite($business, [
            $this->snapshotPage('a', 'Home', [], [$this->textSection('We throw a party and eat bakeries and bake bread; art, arts and smart.')], true),
        ]);

        $r = $this->coverageFor($business, 'art', 'party', 'bakery', 'bake', 'best bake', 'smart', 'bread', 'arts');

        $this->assertSame(1, $r['art']->bodyPages, '"art," is a whole word.');
        $this->assertSame(1, $r['party']->bodyPages);
        $this->assertSame(0, $r['bakery']->bodyPages, '"bakery" is not inside "bakeries".');
        $this->assertSame(1, $r['bake']->bodyPages);
        $this->assertSame(0, $r['best bake']->bodyPages);
        $this->assertSame(1, $r['smart']->bodyPages);
        $this->assertSame(1, $r['bread']->bodyPages);
        $this->assertSame(1, $r['arts']->bodyPages);
    }

    public function test_regex_metacharacters_in_a_phrase_are_matched_literally(): void
    {
        $business = $this->business();
        $this->publishWebsite($business, [
            $this->snapshotPage('a', 'Home', [], [$this->textSection('We hire c++ developers and a.b testers (24/7).')], true),
        ]);

        $r = $this->coverageFor($business, 'c++', 'a.b', 'aXb', '24/7', '(24/7)', '.*', 'a|b');

        $this->assertSame(1, $r['c++']->bodyPages);
        $this->assertSame(1, $r['a.b']->bodyPages);
        $this->assertSame(0, $r['aXb']->bodyPages, '"." must not act as a wildcard.');
        $this->assertSame(1, $r['24/7']->bodyPages);
        $this->assertSame(0, $r['.*']->bodyPages);
        $this->assertSame(0, $r['a|b']->bodyPages, '"|" must not act as alternation.');
    }

    public function test_only_human_visible_text_counts_not_urls_labels_or_uids(): void
    {
        $business = $this->business();
        $this->publishWebsite($business, [
            $this->snapshotPage('secretpageuid', 'Home', [], [
                ['type' => 'hero', 'data' => [
                    'heading' => 'Welcome', 'background_image' => 'bakeryasset',
                    'primary_cta' => ['label' => 'Bakery Sale', 'url' => 'https://bakery.example/'],
                ]],
                ['type' => 'contact_details', 'data' => ['show_phone' => true, 'show_email' => true, 'show_address' => true, 'resolved' => ['address' => '1 Bakery Lane']]],
            ], true),
        ]);

        $r = $this->coverageFor($business, 'bakery', 'secretpageuid', 'bakery sale', 'bakery lane')['bakery'];

        $this->assertSame(SeoKeywordCoverageStatus::NotCovered, $r->status);
    }

    public function test_unpublished_draft_content_never_counts(): void
    {
        $business = $this->business();
        $website = $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', [], [$this->textSection('Plumbing only.')], true)]);
        WebsitePage::create([
            'website_id' => $website->id, 'title' => 'Best Bakery Draft', 'slug' => 'draft',
            'sections' => [], 'meta_description' => 'best bakery draft',
        ]);

        $this->assertSame(SeoKeywordCoverageStatus::NotCovered, $this->coverageFor($business, 'best bakery')['best bakery']->status);
    }

    public function test_without_a_published_website_every_keyword_says_so(): void
    {
        $business = $this->business();

        $r = $this->coverageFor($business, 'best bakery', 'plumber');

        foreach ($r as $result) {
            $this->assertSame(SeoKeywordCoverageStatus::NoPublishedWebsite, $result->status);
            $this->assertSame(0, $result->pagesTotal);
        }
    }

    public function test_a_published_site_with_no_pages_is_not_covered_rather_than_unpublished(): void
    {
        $business = $this->business();
        $this->publishWebsite($business, []);

        $r = $this->coverageFor($business, 'best bakery')['best bakery'];

        $this->assertSame(SeoKeywordCoverageStatus::NotCovered, $r->status);
        $this->assertSame(0, $r->pagesTotal);
    }

    public function test_another_business_never_sees_this_websites_coverage(): void
    {
        $mine = $this->business();
        $other = $this->business();
        $this->publishWebsite($mine, [$this->snapshotPage('a', 'Home', ['seo_title' => 'Best Bakery'], [], true)]);

        $this->assertSame(SeoKeywordCoverageStatus::Covered, $this->coverageFor($mine, 'best bakery')['best bakery']->status);
        $this->assertSame(SeoKeywordCoverageStatus::NoPublishedWebsite, $this->coverageFor($other, 'best bakery')['best bakery']->status);
    }

    public function test_a_location_attributed_keyword_is_checked_against_the_whole_site(): void
    {
        // Website has no Location pages yet (Contract 18 G-1), so attribution
        // changes nothing about WHERE a phrase is looked for.
        [$owner, $business, , $location] = $this->growthTenantWithLocation();
        $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', [], [$this->textSection('Plumber in Tampa.')], true)]);
        $local = app(\App\Library\Seo\SeoKeywordManager::class)->create((int) $owner->user_id, $business, 'plumber in tampa', $location);

        $results = app(SeoKeywordCoverageReader::class)->forKeywords([$local], app(SeoPublishedContentReader::class)->forBusiness($business));

        $this->assertSame(SeoKeywordCoverageStatus::Covered, $results[$local->id]->status);
    }

    public function test_the_reader_is_pure_and_costs_nothing_however_many_keywords(): void
    {
        $business = $this->business();
        $this->publishWebsite($business, array_map(
            fn (int $i) => $this->snapshotPage("p{$i}", "Page {$i}", ['meta_description' => "desc {$i}"], [$this->textSection("body {$i} best bakery")], $i === 0),
            range(0, 19),
        ));
        $content = app(SeoPublishedContentReader::class)->forBusiness($business);
        $keywords = array_map(fn (int $i) => $this->keyword("phrase {$i}"), range(1, 50));
        $before = $this->dbFingerprint($this->seoProtectedTables());

        $queries = $this->capturedQueries(fn () => app(SeoKeywordCoverageReader::class)->forKeywords($keywords, $content));

        $this->assertSame([], $queries, 'Coverage is computed from the already-read snapshot: no query at all.');
        $this->assertSame($before, $this->dbFingerprint($this->seoProtectedTables()));
    }

    public function test_results_are_keyed_by_keyword_id_and_report_no_score_or_position(): void
    {
        $business = $this->business();
        $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', ['seo_title' => 'Best Bakery'], [], true)]);
        $keyword = $this->keyword('best bakery');

        $results = app(SeoKeywordCoverageReader::class)->forKeywords([$keyword], app(SeoPublishedContentReader::class)->forBusiness($business));

        $this->assertSame([(int) $keyword->id], array_keys($results));
        $this->assertSame(
            ['status', 'pagesTotal', 'titlePages', 'descriptionPages', 'bodyPages'],
            array_keys(get_object_vars($results[$keyword->id])),
            'Coverage is a content fact: no rank, position, score or percentage field exists.',
        );
    }
}
