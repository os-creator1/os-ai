<?php

namespace Tests\Feature\Crm\Tags;

use App\Events\Crm\ContactTagAdded;
use App\Events\Crm\ContactTagRemoved;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Library\Crm\TagManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactTag;
use App\Models\Contacts;
use App\Models\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Contact Tags foundation — the canonical `TagManager` boundary: tenancy,
 * idempotency, event production and Location semantics.
 */
class TagManagerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private function manager(): TagManager
    {
        return app(TagManager::class);
    }

    private function location(Business $business): BusinessLocation
    {
        return BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
    }

    // =================================================================
    // Tenancy
    // =================================================================

    public function test_business_a_cannot_use_business_bs_tag(): void
    {
        [, $businessA] = $this->crmTenant('Business A', 'Workspace A');
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');

        $tagB = $this->manager()->createTag($businessB, 'VIP');
        $contactA = $this->crmContact($businessA);

        $this->expectException(CrmRuleException::class);
        $this->manager()->attachTag($businessA, $contactA, $tagB);
    }

    public function test_a_contact_business_mismatch_fails_closed(): void
    {
        [, $businessA] = $this->crmTenant('Business A', 'Workspace A');
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');

        $tagA = $this->manager()->createTag($businessA, 'VIP');
        $contactB = $this->crmContact($businessB);

        $this->expectException(CrmRuleException::class);
        $this->manager()->attachTag($businessA, $contactB, $tagA);
    }

    public function test_the_same_name_inside_one_business_is_refused_deterministically(): void
    {
        [, $business] = $this->crmTenant();
        $this->manager()->createTag($business, 'VIP');

        $this->expectException(CrmRuleException::class);
        $this->manager()->createTag($business, 'vip'); // case-insensitive collision
    }

    public function test_the_same_visible_name_across_two_businesses_is_allowed(): void
    {
        [, $businessA] = $this->crmTenant('Business A', 'Workspace A');
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');

        $tagA = $this->manager()->createTag($businessA, 'VIP');
        $tagB = $this->manager()->createTag($businessB, 'VIP');

        $this->assertNotSame((int) $tagA->id, (int) $tagB->id);
        $this->assertSame('VIP', $tagA->name);
        $this->assertSame('VIP', $tagB->name);
    }

    // =================================================================
    // Attach / detach idempotency
    // =================================================================

    public function test_attaching_a_tag_succeeds(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);

        $membership = $this->manager()->attachTag($business, $contact, $tag);

        $this->assertInstanceOf(ContactTag::class, $membership);
        $this->assertTrue($this->manager()->tagsForContact($business, $contact)->contains('id', $tag->id));
    }

    public function test_duplicate_attach_is_idempotent(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);

        $first = $this->manager()->attachTag($business, $contact, $tag);
        $second = $this->manager()->attachTag($business, $contact, $tag);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A duplicate attach must be a no-op, not a second row.');
        $this->assertSame(1, DB::table('contact_tags')->where('contact_id', $contact->id)->where('tag_id', $tag->id)->count());
    }

    public function test_concurrent_duplicate_attach_cannot_create_duplicates(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);

        // Proves the DB's own UNIQUE(contact_id, tag_id) is the real
        // backstop, not merely the application's own idempotency check: a
        // raw second insert for the same pair — exactly what a genuine race
        // would attempt — is refused by the database itself.
        DB::table('contact_tags')->insert(['contact_id' => $contact->id, 'tag_id' => $tag->id, 'business_id' => $business->id, 'created_at' => now()]);

        $this->expectException(QueryException::class);
        DB::table('contact_tags')->insert(['contact_id' => $contact->id, 'tag_id' => $tag->id, 'business_id' => $business->id, 'created_at' => now()]);
    }

    public function test_removing_succeeds(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);
        $this->manager()->attachTag($business, $contact, $tag);

        $removed = $this->manager()->detachTag($business, $contact, $tag);

        $this->assertTrue($removed);
        $this->assertFalse($this->manager()->tagsForContact($business, $contact)->contains('id', $tag->id));
    }

    public function test_duplicate_remove_is_harmless(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);
        $this->manager()->attachTag($business, $contact, $tag);

        $first = $this->manager()->detachTag($business, $contact, $tag);
        $second = $this->manager()->detachTag($business, $contact, $tag);

        $this->assertTrue($first);
        $this->assertFalse($second, 'A duplicate detach must be a harmless no-op.');
    }

    // =================================================================
    // Rename / archive integrity
    // =================================================================

    public function test_tag_rename_preserves_memberships(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);
        $this->manager()->attachTag($business, $contact, $tag);

        $this->manager()->renameTag($business, $tag, 'Very Important Person');

        $this->assertTrue(
            $this->manager()->tagsForContact($business, $contact)->contains('id', $tag->id),
            'Renaming must never detach an existing membership.',
        );
        $this->assertSame('Very Important Person', $tag->fresh()->name);
    }

    public function test_archiving_a_tag_preserves_existing_memberships(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);
        $this->manager()->attachTag($business, $contact, $tag);

        $this->manager()->archiveTag($business, $tag);

        $this->assertTrue(
            $this->manager()->tagsForContact($business, $contact)->contains('id', $tag->id),
            'Archiving must never corrupt an existing membership.',
        );
        $this->assertTrue($tag->fresh()->isArchived());
    }

    public function test_an_archived_tag_cannot_be_attached_to_a_new_contact(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $this->manager()->archiveTag($business, $tag);
        $contact = $this->crmContact($business);

        $this->expectException(CrmRuleException::class);
        $this->manager()->attachTag($business, $contact, $tag);
    }

    // =================================================================
    // Transactional events
    // =================================================================

    public function test_a_rolled_back_transaction_emits_no_durable_event(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);

        Event::fake([ContactTagAdded::class]);

        try {
            DB::transaction(function () use ($business, $contact, $tag): void {
                $this->manager()->attachTag($business, $contact, $tag);

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        Event::assertNotDispatched(ContactTagAdded::class);
        $this->assertSame(0, DB::table('contact_tags')->count(), 'The membership itself must also have rolled back.');
    }

    public function test_a_successful_attach_creates_exactly_one_durable_tag_added_event(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);

        Event::fake([ContactTagAdded::class]);

        $this->manager()->attachTag($business, $contact, $tag);

        Event::assertDispatchedTimes(ContactTagAdded::class, 1);
    }

    public function test_a_duplicate_request_does_not_create_a_second_logical_occurrence(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);

        Event::fake([ContactTagAdded::class]);

        $this->manager()->attachTag($business, $contact, $tag);
        $this->manager()->attachTag($business, $contact, $tag); // the duplicate request

        Event::assertDispatchedTimes(ContactTagAdded::class, 1);
    }

    public function test_a_successful_detach_creates_exactly_one_durable_tag_removed_event(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);
        $this->manager()->attachTag($business, $contact, $tag);

        Event::fake([ContactTagRemoved::class]);

        $this->manager()->detachTag($business, $contact, $tag);
        $this->manager()->detachTag($business, $contact, $tag); // the duplicate request

        Event::assertDispatchedTimes(ContactTagRemoved::class, 1);
    }

    public function test_the_event_is_tenant_bound_and_carries_the_contacts_location(): void
    {
        [, $business] = $this->crmTenant();
        $location = $this->location($business);
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);
        $contact->forceFill(['location_id' => $location->id])->save();

        Event::fake([ContactTagAdded::class]);

        $membership = $this->manager()->attachTag($business, $contact, $tag->fresh());

        Event::assertDispatched(function (ContactTagAdded $event) use ($business, $contact, $tag, $location, $membership) {
            return $event->businessId === (int) $business->id
                && $event->contactId === (int) $contact->id
                && $event->tagId === (int) $tag->id
                && $event->locationId === (int) $location->id
                && $event->membershipId === (int) $membership->id
                && $event->occurrenceKey() === 'contact_tag_added:' . $membership->id;
        });
    }

    public function test_the_event_carries_a_null_location_when_the_contact_has_none(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);
        $this->assertNull($contact->location_id, 'Sanity: this contact must genuinely have no Location.');

        Event::fake([ContactTagAdded::class]);

        $this->manager()->attachTag($business, $contact, $tag);

        Event::assertDispatched(fn (ContactTagAdded $event) => $event->locationId === null);
    }

    public function test_a_detach_then_reattach_produces_a_new_distinct_occurrence(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);

        $first = $this->manager()->attachTag($business, $contact, $tag);
        $this->manager()->detachTag($business, $contact, $tag);
        $second = $this->manager()->attachTag($business, $contact, $tag);

        $this->assertNotSame((int) $first->id, (int) $second->id, 'Re-attaching must mint a genuinely new occurrence id.');
    }
}
