<?php

namespace Tests\Feature\Website\Gallery;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteAsset;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireSeeder;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round 4 (item 6) — a custom-section image
 * upload is a COMPOUND mutation: WebsiteGalleryManager::uploadMany() writes
 * the file and its WebsiteAsset row FIRST, then
 * WebsiteSetupSessionManager::updateAnswerInPlace() appends the new
 * asset's uid to the answer SECOND, both inside the SAME outermost
 * transaction (runIfNotGenerating()'s own DB::transaction). A failure in
 * that SECOND step must roll back the first step's DB row too (ordinary
 * transactional behavior) — but the FIRST step's file write is NOT
 * transactional (it is plain filesystem I/O), so without explicit
 * compensation it would survive as an orphan pointing at a row that no
 * longer exists. Proves the fix: the file is deleted too.
 */
class UploadOrphanFileCleanupTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireSeeder::class);
    }

    public function test_a_failure_after_file_storage_but_before_the_outer_transaction_commits_leaves_neither_a_row_nor_a_file(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.website.setup.template', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);

        $website = Website::where('business_id', $business->id)->sole();
        $response = QuestionnaireResponse::where('business_id', $business->id)->where('status', 'in_progress')->sole();
        $directory = public_path("images/websites/{$website->uid}");
        $realFiles = fn () => is_dir($directory) ? array_values(array_diff(scandir($directory), ['.', '..'])) : [];
        $filesBefore = $realFiles();

        // Independent-review correction round 4 (item 6) — rather than
        // mocking WebsiteSetupSessionManager (final, so Mockery cannot
        // produce an instance satisfying the controller's own type hint),
        // this forces updateAnswerInPlace()'s OWN real, already-enforced
        // MAX_ANSWERS_JSON_BYTES guard to trip genuinely: the response's
        // answers are padded to just under the ceiling, so appending the
        // new image's uid (the only thing this request's own
        // updateAnswerInPlace() call does) is what pushes it over —
        // a real failure happening AFTER uploadMany() has already
        // written the file and its row, inside the SAME outer
        // transaction, exactly as item 6 describes.
        $answers = [
            'custom_section' => [[
                'key' => 'section', 'name' => 'Section', 'description' => null,
                'body' => 'Existing body', 'layout' => 'stacked', 'images' => [],
            ]],
        ];
        // Matches WebsiteSetupSessionManager::MAX_ANSWERS_JSON_BYTES
        // exactly (private; this is the one constant this test must stay
        // in sync with if that limit ever changes).
        $maxAnswersJsonBytes = 200_000;
        $baseLength = strlen((string) json_encode($answers));
        $wrapperOverhead = strlen((string) json_encode(['_test_padding' => ''])) - strlen('{}');
        // Just under the cap, with just enough room left that the
        // request's own addition (a single new image uid, ~40 bytes once
        // JSON-encoded into the previously-empty `images` array) is what
        // tips the total over — not so much slack that it fits anyway.
        $targetLength = $maxAnswersJsonBytes - 20;
        $padding = str_repeat('a', max(0, $targetLength - $baseLength - $wrapperOverhead));
        $answers['_test_padding'] = $padding;
        $this->assertLessThan(200_000, strlen((string) json_encode($answers)), 'Sanity: padded answers must start UNDER the cap.');

        QuestionnaireResponse::whereKey($response->id)->update(['answers' => $answers]);

        $this->post(route('customer.workspaces.businesses.website.setup.custom-section.upload', [$workspace->uid, $business->uid]), [
            'photo' => $this->fakeImageUpload('orphan-check.png'),
        ])->assertStatus(500);

        $this->assertSame(0, WebsiteAsset::where('website_id', $website->id)->where('purpose', WebsiteAssetPurpose::CustomSection->value)->count(), 'The outer transaction must have rolled back the asset row.');
        $this->assertSame([], $response->fresh()->answer('custom_section')[0]['images'] ?? [], 'The answer must never have been left pointing at an image whose row/file do not exist.');

        $this->assertSame($filesBefore, $realFiles(), 'No orphaned file must remain on disk after the outer transaction rolled back.');
    }
}
