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
 */
class BusinessKnowledgeProfileBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_slice_3_through_6_tables_exist(): void
    {
        $forbidden = [
            'website_templates',
            'website_guided_generation_attempts',
            'business_media_assets',
        ];

        foreach ($forbidden as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not exist -- this implementation owns only Slices 1-2.");
        }
    }

    public function test_business_verticals_and_question_packs_are_the_only_new_tables_this_slice_added(): void
    {
        $this->assertTrue(Schema::hasTable('business_verticals'));
        $this->assertTrue(Schema::hasTable('question_packs'));
    }

    public function test_websites_table_gained_no_template_key_column(): void
    {
        $this->assertFalse(Schema::hasColumn('websites', 'template_key'));
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

    public function test_no_guided_generation_classes_exist_yet(): void
    {
        $this->assertFalse(class_exists(\App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient::class));
        $this->assertFalse(class_exists(\App\Library\Website\GuidedGeneration\GuidedGenerationOutputValidator::class));
        $this->assertFalse(class_exists(\App\Library\Website\GuidedGeneration\MediaBindingService::class));
        $this->assertFalse(class_exists(\App\Library\Website\GuidedGeneration\GuidedGenerationCommitService::class));
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
