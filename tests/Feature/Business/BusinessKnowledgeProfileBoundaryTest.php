<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessIndustry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Website Guided Generation contract §16, §17 item 11, §20 -- proves
 * this Slice 1 implementation introduces none of Slice 2-6's tables,
 * classes, or a new BusinessIndustry case.
 */
class BusinessKnowledgeProfileBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_slice_2_through_6_tables_exist(): void
    {
        $forbidden = [
            'business_verticals',
            'question_packs',
            'website_templates',
            'website_guided_generation_attempts',
            'business_media_assets',
        ];

        foreach ($forbidden as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} must not exist -- Slice 1 owns only the Business Knowledge Profile foundation.");
        }
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
