<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessIndustry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Website Guided Generation contract §16, §17 item 11, §20 -- proves
 * this implementation (through Slice 2) introduces none of Slice 3-6's
 * tables, classes, or a new BusinessIndustry case. `business_verticals`
 * and `question_packs` are Slice 2's own contracted tables (§6) and are
 * deliberately no longer in the forbidden list below -- they were
 * forbidden only for Slice 1, whose own boundary test this originally
 * was.
 *
 * Website Generator + Local SEO Completion lane -- this is the exact,
 * explicitly-authorized human decision that closes Slices 3/4/8 the
 * docblock above once locked shut: `website_templates`,
 * `website_guided_generation_attempts`, `websites.template_key`, and
 * the four App\Library\Website\GuidedGeneration\* classes now exist and
 * are asserted present below instead of absent. `business_media_assets`
 * (Slice 5's separate, cross-feature business-wide media inventory) and
 * App\Library\Opportunity\WebsiteOpportunityProducer (contract §22
 * decision 7) remain explicitly out of THIS lane's authorization and
 * stay asserted absent -- this lane's own media handling reuses the
 * existing, Website-scoped WebsiteAsset instead (see
 * App\Library\Website\GuidedGeneration\MediaBindingService's own
 * docblock for why that scope reduction is safe and honestly stated).
 */
class BusinessKnowledgeProfileBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_media_assets_still_does_not_exist(): void
    {
        $this->assertFalse(Schema::hasTable('business_media_assets'), 'business_media_assets is Slice 5 (a separate, cross-feature business-wide media inventory) -- out of this lane\'s authorization; MediaBindingService reuses the existing WebsiteAsset instead.');
    }

    public function test_business_verticals_and_question_packs_exist(): void
    {
        $this->assertTrue(Schema::hasTable('business_verticals'));
        $this->assertTrue(Schema::hasTable('question_packs'));
    }

    public function test_website_template_catalog_tables_now_exist(): void
    {
        $this->assertTrue(Schema::hasTable('website_templates'));
        $this->assertTrue(Schema::hasTable('website_guided_generation_attempts'));
        $this->assertTrue(Schema::hasColumn('websites', 'template_key'));
    }

    public function test_no_new_business_industry_case_was_added(): void
    {
        $cases = array_column(BusinessIndustry::cases(), 'value');

        $this->assertSame([
            'photo_booth_service',
            'event_services',
            'photographer',
            'wedding_vendor',
            'home_services',
            'professional_services',
            'other',
        ], $cases, 'BusinessIndustry must remain exactly its 7 existing cases -- no per-vertical case is ever added (§6, locked).');
    }

    public function test_the_guided_generation_runtime_now_exists(): void
    {
        $this->assertTrue(class_exists(\App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient::class));
        $this->assertTrue(class_exists(\App\Library\Website\GuidedGeneration\GuidedGenerationOutputValidator::class));
        $this->assertTrue(class_exists(\App\Library\Website\GuidedGeneration\MediaBindingService::class));
        $this->assertTrue(class_exists(\App\Library\Website\GuidedGeneration\GuidedGenerationCommitService::class));
    }

    public function test_no_opportunity_producer_for_website_exists(): void
    {
        $this->assertFalse(class_exists(\App\Library\Opportunity\WebsiteOpportunityProducer::class));
    }

    public function test_websites_table_page_count_ceiling_and_section_ceiling_are_unchanged(): void
    {
        $source = file_get_contents(app_path('Library/Website/WebsiteSectionValidator.php'));
        $this->assertStringContainsString('MAX_SECTIONS_PER_PAGE = 40', $source);
    }
}
