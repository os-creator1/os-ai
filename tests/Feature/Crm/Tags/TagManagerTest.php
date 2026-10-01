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
use App\Repositories\Contracts\ContactTagRepository;
use App\Repositories\Contracts\TagRepository;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
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

    // =================================================================
    // Correction round 1, §1 — authoritative re-derivation, never the
    // caller's own (possibly forged or stale) model instance.
    // =================================================================

    public function test_a_forged_contact_business_id_cannot_be_attached_to_another_businesss_tag(): void
    {
        [, $businessA] = $this->crmTenant('Business A', 'Workspace A');
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');

        $tagA = $this->manager()->createTag($businessA, 'VIP');
        $realContactB = $this->crmContact($businessB);

        // A REAL Contact row, genuinely belonging to Business B — only its
        // in-memory property is forged to look like Business A's.
        $realContactB->business_id = $businessA->id;
        $this->assertSame((int) $businessB->id, (int) DB::table('contacts')->where('id', $realContactB->id)->value('business_id'), 'Sanity: the PERSISTED row must still genuinely belong to Business B.');

        Event::fake([ContactTagAdded::class]);

        try {
            $this->manager()->attachTag($businessA, $realContactB, $tagA);
            $this->fail('Expected a CrmRuleException refusing the forged Contact.');
        } catch (CrmRuleException) {
            // expected
        }

        Event::assertNotDispatched(ContactTagAdded::class);
        $this->assertSame(0, DB::table('contact_tags')->count(), 'No membership row may exist after a refused forged attach.');
    }

    public function test_a_forged_tag_business_id_cannot_be_attached_under_another_business(): void
    {
        [, $businessA] = $this->crmTenant('Business A', 'Workspace A');
        [, $businessB] = $this->crmTenant('Business B', 'Workspace B');

        $realTagB = $this->manager()->createTag($businessB, 'VIP');
        $contactA = $this->crmContact($businessA);

        // A REAL Tag row, genuinely belonging to Business B — only its
        // in-memory property is forged to look like Business A's.
        $realTagB->business_id = $businessA->id;
        $this->assertSame((int) $businessB->id, (int) DB::table('tags')->where('id', $realTagB->id)->value('business_id'), 'Sanity: the PERSISTED row must still genuinely belong to Business B.');

        Event::fake([ContactTagAdded::class]);

        try {
            $this->manager()->attachTag($businessA, $contactA, $realTagB);
            $this->fail('Expected a CrmRuleException refusing the forged Tag.');
        } catch (CrmRuleException) {
            // expected
        }

        Event::assertNotDispatched(ContactTagAdded::class);
        $this->assertSame(0, DB::table('contact_tags')->count(), 'No membership row may exist after a refused forged attach.');
    }

    public function test_stale_in_memory_contact_location_never_leaks_into_the_event(): void
    {
        [, $business] = $this->crmTenant();
        $locationOld = $this->location($business);
        $locationNew = $this->location($business);
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);
        $contact->forceFill(['location_id' => $locationOld->id])->save();

        // The caller's own in-memory copy, loaded BEFORE the Location
        // changed underneath it.
        $staleContact = Contacts::query()->findOrFail($contact->id);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $locationNew->id]);
        $this->assertSame((int) $locationOld->id, (int) $staleContact->location_id, 'Sanity: the caller\'s copy must genuinely still read the OLD Location.');

        Event::fake([ContactTagAdded::class]);

        $this->manager()->attachTag($business, $staleContact, $tag);

        Event::assertDispatched(function (ContactTagAdded $event) use ($locationNew) {
            return $event->locationId === (int) $locationNew->id;
        });
    }

    public function test_stale_in_memory_tag_name_never_leaks_into_the_event(): void
    {
        [, $business] = $this->crmTenant();
        $tag = $this->manager()->createTag($business, 'VIP');
        $contact = $this->crmContact($business);

        // The caller's own in-memory copy, loaded BEFORE the rename.
        $staleTag = Tag::query()->findOrFail($tag->id);
        $this->manager()->renameTag($business, $tag->fresh(), 'Renamed');
        $this->assertSame('VIP', $staleTag->name, 'Sanity: the caller\'s copy must genuinely still read the OLD name.');

        Event::fake([ContactTagAdded::class]);

        $this->manager()->attachTag($business, $contact, $staleTag);

        Event::assertDispatched(fn (ContactTagAdded $event) => $event->tagName === 'Renamed');
    }

    // =================================================================
    // Correction round 1, §2 — the DB unique index, not the pre-write
    // check, is the real backstop against a normalized-name race.
    // =================================================================

    public function test_two_racing_creates_of_the_same_normalized_name_leave_exactly_one_canonical_tag(): void
    {
        [, $business] = $this->crmTenant();

        $first = $this->manager()->createTag($business, 'VIP');

        try {
            $this->manager()->createTag($business, 'vip'); // same normalized name
            $this->fail('Expected a CrmRuleException, not a raw database exception.');
        } catch (CrmRuleException) {
            // expected — a raw QueryException/UniqueConstraintViolationException
            // here would fail this test as an uncaught exception.
        }

        $this->assertSame(1, DB::table('tags')->where('business_id', $business->id)->where('normalized_name', 'vip')->count());
        $survivor = Tag::query()->where('business_id', $business->id)->where('normalized_name', 'vip')->firstOrFail();
        $this->assertSame((int) $first->id, (int) $survivor->id);
    }

    public function test_a_rename_racing_an_existing_tags_normalized_name_leaves_exactly_one_canonical_tag(): void
    {
        [, $business] = $this->crmTenant();
        $tagA = $this->manager()->createTag($business, 'VIP');
        $tagB = $this->manager()->createTag($business, 'Lead');

        try {
            $this->manager()->renameTag($business, $tagB, 'vip'); // collides with $tagA's normalized name
            $this->fail('Expected a CrmRuleException, not a raw database exception.');
        } catch (CrmRuleException) {
            // expected
        }

        $this->assertSame('Lead', $tagB->fresh()->name, 'A refused rename must never partially apply.');
        $this->assertSame(1, DB::table('tags')->where('business_id', $business->id)->where('normalized_name', 'vip')->count());
        $this->assertSame((int) $tagA->id, (int) Tag::query()->where('business_id', $business->id)->where('normalized_name', 'vip')->firstOrFail()->id);
    }

    /**
     * Deterministically exercises the genuine RACE-WINDOW branch itself (the
     * `catch` around the write, not the ordinary pre-check): the repository
     * is doubled so its pre-check reports no conflict — exactly what a real
     * concurrent request's pre-check would also see — and its write throws
     * the same `UniqueConstraintViolationException` MySQL's own
     * `tags_business_normalized_unique` index would raise. Proves the
     * translation itself, which the sequential tests above cannot reach
     * (their pre-check always already sees the conflict).
     */
    public function test_a_genuine_create_race_window_violation_is_translated_not_leaked(): void
    {
        [, $business] = $this->crmTenant();

        $tagRepository = Mockery::mock(TagRepository::class);
        $tagRepository->shouldReceive('findByNormalizedName')->once()->andReturnNull();
        $tagRepository->shouldReceive('create')->once()->andThrow(
            new UniqueConstraintViolationException('mysql', 'insert into `tags` ...', [], new Exception('Duplicate entry for key tags_business_normalized_unique')),
        );

        $manager = new TagManager($tagRepository, app(ContactTagRepository::class));

        try {
            $manager->createTag($business, 'VIP');
            $this->fail('Expected a CrmRuleException, not the raw UniqueConstraintViolationException.');
        } catch (CrmRuleException $exception) {
            $this->assertSame('A tag named "VIP" already exists.', $exception->getMessage());
        }
    }

    /** The rename-side sibling of the create race-window test above. */
    public function test_a_genuine_rename_race_window_violation_is_translated_not_leaked(): void
    {
        [, $business] = $this->crmTenant();
        $realTag = $this->manager()->createTag($business, 'Lead');

        $tagRepository = Mockery::mock(TagRepository::class);
        $tagRepository->shouldReceive('findByNormalizedName')->once()->andReturnNull();
        $tagRepository->shouldReceive('rename')->once()->andThrow(
            new UniqueConstraintViolationException('mysql', 'update `tags` ...', [], new Exception('Duplicate entry for key tags_business_normalized_unique')),
        );

        $manager = new TagManager($tagRepository, app(ContactTagRepository::class));

        try {
            $manager->renameTag($business, $realTag, 'VIP');
            $this->fail('Expected a CrmRuleException, not the raw UniqueConstraintViolationException.');
        } catch (CrmRuleException $exception) {
            $this->assertSame('A tag named "VIP" already exists.', $exception->getMessage());
        }
    }
}
