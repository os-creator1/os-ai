<?php

namespace Tests\Unit\Website;

use App\Library\Website\Seo\WebsitePageIntent;
use App\Library\Website\Seo\WebsitePageIntentClassifier;
use Tests\TestCase;

class WebsitePageIntentTest extends TestCase
{
    public function test_a_planned_page_gets_its_role_and_subject_from_the_plan_not_from_its_address(): void
    {
        $this->assertSame(['role' => 'home', 'intent' => null], WebsitePageIntent::forPlanPage(['page_type' => 'home', 'is_home' => true, 'entity' => null]));
        $this->assertSame(['role' => 'location', 'intent' => ['place' => 'Cheney']], WebsitePageIntent::forPlanPage(['page_type' => 'location', 'entity' => ['area' => 'Cheney']]));
        $this->assertSame(['role' => 'service', 'intent' => ['service_uid' => 'abc']], WebsitePageIntent::forPlanPage(['page_type' => 'service_detail', 'entity' => ['service_uid' => 'abc']]));
        $this->assertSame('services_hub', WebsitePageIntent::forPlanPage(['page_type' => 'services_overview'])['role']);
        $this->assertSame(['role' => 'event', 'intent' => ['service_uid' => 'ev1']], WebsitePageIntent::forPlanPage(['page_type' => 'event_detail', 'entity' => ['service_uid' => 'ev1']]));
        $this->assertSame('other', WebsitePageIntent::forPlanPage(['page_type' => 'custom_section'])['role']);
        $this->assertNull(WebsitePageIntent::validRole('made-up'));
        $this->assertNull(WebsitePageIntent::cleanIntent(['place' => '  ', 'junk' => 'x']));
    }

    private function migratedPhotoBoothSite(): WebsitePageIntentClassifier
    {
        return new WebsitePageIntentClassifier(
            ['Digital Photo Booth', 'Polaroid Photo Booth'],
            ['Spokane', 'Spokane Valley', 'Liberty Lake', 'Cheney', 'Airway Heights', "Coeur d'Alene", 'Post Falls'],
            ['Weddings', 'Corporate events', 'Parties'],
        );
    }

    public function test_a_site_moved_from_another_builder_is_classified_from_the_businesss_own_services_and_places(): void
    {
        $classifier = $this->migratedPhotoBoothSite();
        $role = fn (string $slug, string $text = '') => $classifier->classify(['slug' => $slug, 'is_home' => false, 'title' => '', 'text' => $text])['role'];

        $this->assertSame('event', $role('wedding-photo-booth-rental'), 'An event type is the occasion someone is hiring for, not a booth.');
        $this->assertSame('event', $role('corporate-photo-booth-rental'));
        $this->assertSame('event', $role('party-photo-booth-rental'));
        $this->assertSame('service', $role('digital-photo-booth-rental'), 'A booth or equipment page stays a service page.');
        $this->assertSame('location', $role('cheney'));
        $this->assertSame('location', $role('airway'));
        $this->assertSame('location', $role('spokane-valley'));
        $this->assertSame('location', $role('coeur-dalene'));
        $this->assertSame('location', $role('post-falls'));
        $this->assertSame('legal', $role('terms-and-conditions'));
        $this->assertSame('legal', $role('privacy-policy'));
        $this->assertSame('contact', $role('check-availability'));
        $this->assertSame('about', $role('about'));
        $this->assertSame('gallery', $role('gallery'));
        $this->assertSame('other', $role('backdrops-and-extras'));
    }

    public function test_the_place_of_a_location_page_is_the_businesss_own_place_name(): void
    {
        $classifier = $this->migratedPhotoBoothSite();

        $this->assertSame('Airway Heights', $classifier->classify(['slug' => 'airway', 'is_home' => false, 'title' => ''])['place']);
        $this->assertSame('Spokane Valley', $classifier->classify(['slug' => 'spokane-valley', 'is_home' => false, 'title' => ''])['place']);
        $this->assertSame('Spokane', $classifier->classify(['slug' => 'spokane', 'is_home' => false, 'title' => ''])['place']);
    }

    public function test_an_area_hub_needs_hub_words_in_its_address_and_several_real_places_in_its_text(): void
    {
        $classifier = $this->migratedPhotoBoothSite();
        $text = 'We serve Spokane, Spokane Valley, Liberty Lake and Cheney.';

        $this->assertSame('area_hub', $classifier->classify(['slug' => 'service-areas', 'is_home' => false, 'title' => 'Areas', 'text' => $text])['role']);
        $this->assertSame('other', $classifier->classify(['slug' => 'areas-we-cover', 'is_home' => false, 'title' => 'Areas', 'text' => 'We travel.'])['role']);
        $this->assertSame('other', $classifier->classify(['slug' => 'inspiration', 'is_home' => false, 'title' => '', 'text' => $text])['role']);
    }

    public function test_a_stored_role_and_the_generators_own_conventions_outrank_any_guess(): void
    {
        $classifier = $this->migratedPhotoBoothSite();

        $this->assertSame(['role' => 'service', 'place' => 'Cheney', 'offer' => null, 'source' => 'stored'], $classifier->classify(['slug' => 'cheney', 'is_home' => false, 'title' => '', 'role' => 'service', 'intent' => ['place' => 'Cheney']]));
        $this->assertSame('convention', $classifier->classify(['slug' => 'serving-cheney', 'is_home' => false, 'title' => ''])['source']);
        $this->assertSame('home', $classifier->classify(['slug' => null, 'is_home' => true, 'title' => 'Home'])['role']);
        $this->assertSame('other', (new WebsitePageIntentClassifier())->classify(['slug' => 'cheney', 'is_home' => false, 'title' => ''])['role'], 'a Business with no saved places and services is never guessed at');
    }

    public function test_the_offerings_page_is_found_by_what_it_is_not_by_a_fixed_address(): void
    {
        $packageSection = ['type' => 'services', 'data' => ['items' => [['catalog_item_uid' => 'p1', 'name' => 'Gold']]]];
        $pages = [
            ['uid' => 'h', 'slug' => null, 'is_home' => true, 'sections' => []],
            ['uid' => 'a', 'slug' => 'about', 'is_home' => false, 'sections' => []],
            ['uid' => 'b', 'slug' => 'photo-booth-prices', 'is_home' => false, 'sections' => [$packageSection]],
        ];

        $this->assertSame('b', WebsitePageIntent::offeringsPage($pages)['page']['uid']);
        $this->assertSame('See packages', WebsitePageIntent::offeringsPage($pages)['label']);

        $hubOnly = [['uid' => 'h', 'slug' => null, 'is_home' => true], ['uid' => 's', 'slug' => 'ours', 'is_home' => false, 'role' => 'services_hub', 'sections' => []]];
        $this->assertSame('See our services', WebsitePageIntent::offeringsPage($hubOnly)['label']);

        $none = [['uid' => 'h', 'slug' => null, 'is_home' => true], ['uid' => 'a', 'slug' => 'about', 'is_home' => false, 'sections' => []]];
        $this->assertNull(WebsitePageIntent::offeringsPage($none), 'no packages page on the site: no button, and never a link to a missing /packages');

        $noindexed = [['uid' => 'p', 'slug' => 'packages', 'is_home' => false, 'seo' => ['noindex' => true], 'sections' => []]];
        $this->assertNull(WebsitePageIntent::offeringsPage($noindexed));
    }
}
