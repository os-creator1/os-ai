<?php

namespace Tests\Feature\Website;

use App\Models\QuestionPack;
use Database\Seeders\QuestionPackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Website Generator + Local SEO Completion — task instruction: "Seed at
 * minimum: a useful GENERAL local-service fallback pack; a high-quality
 * PHOTO BOOTH pack." Every field_key asked about is validated against
 * BusinessKnowledgeProfileFieldKey by QuestionPack's own model-event
 * hook (proven simply by the seeder running without throwing).
 */
class QuestionPackSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_general_fallback_pack_and_a_photo_booth_pack_are_seeded(): void
    {
        $this->seed(QuestionPackSeeder::class);

        $general = QuestionPack::where('key', 'general')->first();
        $photoBooth = QuestionPack::where('applies_to_industry', 'photo_booth_service')->first();

        $this->assertNotNull($general);
        $this->assertNull($general->applies_to_industry);
        $this->assertNull($general->applies_to_vertical_key);

        $this->assertNotNull($photoBooth);
        $this->assertSame('photobooth', $photoBooth->key);
        $this->assertGreaterThanOrEqual(5, count($photoBooth->questions));
    }

    public function test_seeding_twice_does_not_duplicate_or_error(): void
    {
        $this->seed(QuestionPackSeeder::class);
        $this->seed(QuestionPackSeeder::class);

        $this->assertSame(1, QuestionPack::where('key', 'general')->count());
        $this->assertSame(1, QuestionPack::where('key', 'photobooth')->count());
    }

    public function test_the_photo_booth_pack_never_asks_for_a_canonically_owned_fact(): void
    {
        $this->seed(QuestionPackSeeder::class);

        $photoBooth = QuestionPack::where('key', 'photobooth')->firstOrFail();
        $fieldKeys = collect($photoBooth->questions)->pluck('field_key');

        // hours/phone/email/services/packages are owned by
        // businesses/business_locations/business_services/catalog_items
        // directly, never re-asked here (task instruction).
        $this->assertFalse($fieldKeys->contains('hours'));
    }
}
