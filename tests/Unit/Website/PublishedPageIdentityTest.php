<?php

namespace Tests\Unit\Website;

use App\Library\Website\GuidedGeneration\PublishedPageIdentity;
use PHPUnit\Framework\TestCase;

/** Which rebuilt pages are the same pages as the live revision's: unique, reliable matches only; nothing guessed. */
class PublishedPageIdentityTest extends TestCase
{
    private function live(string $uid, ?string $slug, ?string $role = null, ?array $intent = null, bool $home = false): array
    {
        return ['uid' => $uid, 'slug' => $slug, 'is_home' => $home, 'role' => $role, 'intent' => $intent];
    }

    private function fresh(?string $slug, string $role, ?array $intent = null, bool $home = false): array
    {
        return ['slug' => $slug, 'is_home' => $home, 'role' => $role, 'intent' => $intent];
    }

    public function test_home_service_and_location_pages_keep_their_uid_by_identity_not_position(): void
    {
        $live = [
            $this->live('h', null, 'home', null, true),
            $this->live('s1', 'service-a', 'service', ['service_uid' => 'SVC-A']),
            $this->live('s2', 'service-b', 'service', ['service_uid' => 'SVC-B']),
            $this->live('l1', 'serving-portland-or', 'location', ['place' => 'Portland, OR']),
            $this->live('l2', 'serving-portland-me', 'location', ['place' => 'Portland, ME']),
        ];
        // Reordered, and a booth renamed (its address changed) — identity is the service uid / the place.
        $new = [
            $this->fresh('serving-portland-me', 'location', ['place' => 'Portland, ME']),
            $this->fresh('service-b-renamed', 'service', ['service_uid' => 'SVC-B']),
            $this->fresh(null, 'home', null, true),
            $this->fresh('serving-portland-or', 'location', ['place' => 'Portland, Oregon']),
            $this->fresh('service-a', 'service', ['service_uid' => 'SVC-A']),
        ];

        $this->assertSame(['l2', 's2', 'h', 'l1', 's1'], PublishedPageIdentity::match($new, $live));
    }

    public function test_a_new_page_gets_no_uid_and_a_removed_page_is_not_given_to_another(): void
    {
        $live = [$this->live('s1', 'service-a', 'service', ['service_uid' => 'SVC-A']), $this->live('s2', 'service-b', 'service', ['service_uid' => 'SVC-B'])];
        $new = [$this->fresh('service-a', 'service', ['service_uid' => 'SVC-A']), $this->fresh('service-c', 'service', ['service_uid' => 'SVC-C'])];

        $this->assertSame(['s1', null], PublishedPageIdentity::match($new, $live), 'SVC-B is gone; SVC-C is new and does not inherit its uid.');
    }

    public function test_same_named_cities_in_different_states_are_never_matched_to_each_other(): void
    {
        $live = [$this->live('l1', 'serving-portland-or', 'location', ['place' => 'Portland, OR'])];
        $new = [$this->fresh('serving-portland-me', 'location', ['place' => 'Portland, ME'])];

        $this->assertSame([null], PublishedPageIdentity::match($new, $live));
    }

    public function test_a_regionless_old_place_matches_only_when_the_city_is_unique_on_both_sides(): void
    {
        $live = [$this->live('l1', 'serving-cheney', 'location', ['place' => 'Cheney'])];
        $this->assertSame(['l1'], PublishedPageIdentity::match([$this->fresh('serving-cheney-wa', 'location', ['place' => 'Cheney, WA'])], $live));

        $this->assertSame([null, null], PublishedPageIdentity::match([
            $this->fresh('serving-cheney-wa', 'location', ['place' => 'Cheney, WA']),
            $this->fresh('serving-cheney-id', 'location', ['place' => 'Cheney, ID']),
        ], $live), 'Two Cheneys now: which one the old page was is unknown.');

        $two = [$this->live('a', 'serving-cheney', 'location', ['place' => 'Cheney']), $this->live('b', 'serving-cheney-2', 'location', ['place' => 'Cheney'])];
        $this->assertSame([null], PublishedPageIdentity::match([$this->fresh('serving-cheney-wa', 'location', ['place' => 'Cheney, WA'])], $two), 'Two old regionless Cheney pages: ambiguous.');
    }

    public function test_legacy_pages_match_by_exact_address_only_and_never_across_conflicting_roles(): void
    {
        $live = [$this->live('p', 'packages'), $this->live('x', 'our-story'), $this->live('g', 'gallery')];
        $new = [$this->fresh('packages', 'packages'), $this->fresh('our-story', 'service', ['service_uid' => 'Z']), $this->fresh('gallery-2', 'gallery')];

        $this->assertSame(['p', null, null], PublishedPageIdentity::match($new, $live), 'A different address is a different page; a conflicting role is never matched.');
    }

    public function test_a_published_uid_is_never_assigned_twice(): void
    {
        $live = [$this->live('s1', 'service-a', 'service', ['service_uid' => 'A'])];
        $new = [$this->fresh('service-a', 'service', ['service_uid' => 'A']), $this->fresh('service-a', 'service', ['service_uid' => 'A'])];

        $this->assertSame([null, null], PublishedPageIdentity::match($new, $live), 'Two candidates for one identity: neither is guessed.');
    }

    public function test_one_of_a_kind_pages_match_by_stored_role_when_each_side_has_exactly_one(): void
    {
        $live = [$this->live('c', 'contact-us', 'contact'), $this->live('a', 'about', 'about')];
        $new = [$this->fresh('photo-booth-contact', 'contact'), $this->fresh('photo-booth-about', 'about')];

        $this->assertSame(['c', 'a'], PublishedPageIdentity::match($new, $live));
    }
}
