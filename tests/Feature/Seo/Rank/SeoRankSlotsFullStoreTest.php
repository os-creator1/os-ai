<?php

namespace Tests\Feature\Seo\Rank;

use App\Models\SeoKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Rank\Concerns\CreatesRankFixtures;
use Tests\TestCase;

/**
 * A full rank-tracking allowance never blocks adding an SEO keyword (up to the
 * existing 50): it is saved with rank tracking off and the owner is told why.
 */
class SeoRankSlotsFullStoreTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRankFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rankSetUp();
    }

    public function test_a_keyword_added_while_all_slots_are_used_is_saved_untracked_with_an_explanation(): void
    {
        [$owner, $business, $workspace] = $this->coreTenant();

        foreach (range(1, 5) as $i) {
            $this->track($owner, $business, $this->keyword($owner, $business, "tracked keyword {$i}"));
        }

        $this->authenticateAsSeoCustomer($owner);

        $response = $this->post(route('customer.workspaces.businesses.seo.keywords.store', [$workspace->uid, $business->uid]), [
            'phrase' => 'sixth keyword',
            'track_rank' => '0',
        ]);

        $response->assertRedirect();
        $message = (string) session('message');
        $this->assertStringContainsString('Keyword saved', $message);
        $this->assertStringContainsString('Rank tracking off', $message);
        $this->assertStringContainsString('5 of 5 rank-tracked keywords are in use', $message);

        $keyword = SeoKeyword::query()->where('phrase', 'sixth keyword')->firstOrFail();
        $this->assertSame(0, $keyword->business_id === $business->id ? \App\Models\SeoRankTarget::query()->where('seo_keyword_id', $keyword->id)->count() : -1);
        $this->assertSame(6, SeoKeyword::query()->where('business_id', $business->id)->count());
    }
}
