<?php

namespace Tests\Feature\Crm\Tags;

use App\Library\Crm\Migration\LegacyContactTagsBackfillV1;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Contact Tags foundation §5 — the legacy `contacts.tags` JSON backfill.
 *
 * This disposable test database is built from this branch's own migration
 * history, which does NOT create a `contacts.tags` column (confirmed: no
 * tracked migration anywhere adds one). Every test here adds the column
 * itself for the duration of the test, exercising the exact same code path
 * a database that genuinely has the column would take, then removes it —
 * proving the backfill's behavior on the data shape it was written for,
 * without asserting anything about which real-world databases carry the
 * column at all.
 */
class LegacyContactTagsBackfillV1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('contacts', 'tags')) {
            Schema::table('contacts', function ($table): void {
                $table->text('tags')->nullable();
            });
        }
    }

    protected function tearDown(): void
    {
        if (Schema::hasColumn('contacts', 'tags')) {
            Schema::table('contacts', function ($table): void {
                $table->dropColumn('tags');
            });
        }

        parent::tearDown();
    }

    private function backfill(): array
    {
        return (new LegacyContactTagsBackfillV1())->run();
    }

    public function test_the_backfill_reports_a_clean_skip_when_the_column_does_not_exist(): void
    {
        Schema::table('contacts', function ($table): void {
            $table->dropColumn('tags');
        });

        $result = $this->backfill();

        $this->assertFalse($result['ran']);
        $this->assertNotNull($result['reason']);

        // Re-add so tearDown()'s own drop does not fail.
        Schema::table('contacts', function ($table): void {
            $table->text('tags')->nullable();
        });
    }

    public function test_it_creates_a_canonical_tag_and_attaches_it_deterministically(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        DB::table('contacts')->where('id', $contact->id)->update(['tags' => json_encode(['VIP', 'lead-2025'])]);

        $result = $this->backfill();

        $this->assertTrue($result['ran']);
        $this->assertSame(2, $result['tags_created']);
        $this->assertSame(2, $result['memberships_created']);

        $tagIds = DB::table('contact_tags')->where('contact_id', $contact->id)->pluck('tag_id');
        $this->assertCount(2, $tagIds);

        $names = DB::table('tags')->whereIn('id', $tagIds)->pluck('name')->sort()->values()->all();
        $this->assertSame(['VIP', 'lead-2025'], $names);
    }

    public function test_the_first_seen_casing_wins_for_a_duplicate_normalized_name(): void
    {
        [, $business] = $this->crmTenant();
        $first = $this->crmContact($business);
        $second = $this->crmContact($business);
        DB::table('contacts')->where('id', $first->id)->update(['tags' => json_encode(['VIP'])]);
        DB::table('contacts')->where('id', $second->id)->update(['tags' => json_encode(['vip'])]);

        $result = $this->backfill();

        $this->assertSame(1, $result['tags_created'], 'Both contacts must resolve to the SAME tag row.');
        $this->assertSame(2, $result['memberships_created']);

        $tag = DB::table('tags')->where('business_id', $business->id)->where('normalized_name', 'vip')->first();
        $this->assertSame('VIP', $tag->name, 'The first-seen (ascending contact id) casing must win.');
    }

    public function test_the_same_name_in_two_businesses_creates_two_distinct_tags(): void
    {
        [, $businessA] = $this->crmTenant('Business A', 'Workspace A');
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');
        $contactA = $this->crmContact($businessA);
        $contactB = $this->crmContact($businessB);
        DB::table('contacts')->where('id', $contactA->id)->update(['tags' => json_encode(['VIP'])]);
        DB::table('contacts')->where('id', $contactB->id)->update(['tags' => json_encode(['VIP'])]);

        $result = $this->backfill();

        $this->assertSame(2, $result['tags_created'], 'Each Business must get its OWN tag row, never shared.');
    }

    public function test_a_contact_with_no_business_is_reported_as_unmappable(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        DB::table('contacts')->where('id', $contact->id)->update(['tags' => json_encode(['VIP']), 'business_id' => null]);

        $result = $this->backfill();

        $this->assertSame(1, $result['unmappable_contacts']);
        $this->assertSame(0, $result['tags_created']);
        $this->assertSame(0, DB::table('contact_tags')->where('contact_id', $contact->id)->count());
    }

    public function test_malformed_json_is_reported_and_skipped_not_guessed(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        DB::table('contacts')->where('id', $contact->id)->update(['tags' => 'not valid json{{{']);

        $result = $this->backfill();

        $this->assertSame(1, $result['malformed_contacts']);
        $this->assertSame(0, DB::table('contact_tags')->where('contact_id', $contact->id)->count());
    }

    public function test_a_malformed_array_entry_is_reported_but_siblings_still_migrate(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        DB::table('contacts')->where('id', $contact->id)->update(['tags' => json_encode(['VIP', '', '   ', 42])]);

        $result = $this->backfill();

        $this->assertSame(3, $result['malformed_entries'], 'The empty string, blank string and non-string entry are each reported.');
        $this->assertSame(1, $result['memberships_created'], 'The one genuinely valid entry ("VIP") must still migrate.');
    }

    public function test_the_backfill_is_idempotent_on_rerun(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        DB::table('contacts')->where('id', $contact->id)->update(['tags' => json_encode(['VIP'])]);

        $this->backfill();
        $second = $this->backfill();

        $this->assertSame(0, $second['tags_created'], 'Rerunning must create nothing new.');
        $this->assertSame(0, $second['memberships_created']);
        $this->assertSame(1, DB::table('contact_tags')->where('contact_id', $contact->id)->count());
    }

    public function test_the_backfill_never_overwrites_a_membership_the_live_product_already_created(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        DB::table('contacts')->where('id', $contact->id)->update(['tags' => json_encode(['VIP'])]);

        // The live TagManager already created a tag with the SAME
        // normalized name ("vip"), under different, deliberately-chosen
        // casing — the backfill must reuse that row, never create a
        // second, differently-cased duplicate for the same normalized name.
        $liveTag = app(\App\Library\Crm\TagManager::class)->createTag($business, 'Vip');
        $otherContact = $this->crmContact($business);
        app(\App\Library\Crm\TagManager::class)->attachTag($business, $otherContact, $liveTag);

        $result = $this->backfill();

        $this->assertSame(0, $result['tags_created'], 'The existing live tag must be reused by normalized name, not duplicated.');
        $this->assertSame(1, DB::table('tags')->where('business_id', $business->id)->count());
        $this->assertSame('Vip', $liveTag->fresh()->name, 'The backfill must never overwrite the live tag\'s own display name.');
        $this->assertSame(1, DB::table('contact_tags')->where('contact_id', $contact->id)->where('tag_id', $liveTag->id)->count());
    }
}
