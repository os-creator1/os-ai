<?php

namespace Tests\Feature\Website\Setup;

use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\TestCase;

/**
 * v3 opening: confirm the basics (prefilled from the real Business), where
 * you are based, and where you serve — with canonical write-through and
 * grounded, never-overriding service-area suggestions.
 */
class WizardV3BasicsAndAreasTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGuidedWizard();
    }

    private function basics(array $values = []): array
    {
        $values += ['business_name' => 'Glow Booths', 'phone' => '555-0100', 'email' => 'hello@glowbooths.test'];

        return [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => ['business_name', 'phone', 'email', 'logo'],
            's' => collect($values)->map(fn ($v) => ['value' => $v])->all(),
        ];
    }

    private function postBase(array $place): void
    {
        $this->jpost($this->url('autosave', 'base_location'), [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => ['base_location'],
            'value' => $place,
        ])->assertOk();
    }

    private function postAreas(array $areas, string $step = 'service_area_cities')
    {
        return $this->jpost($this->url('autosave', $step), [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => [$step],
            'value' => $areas,
        ]);
    }

    // ---------------------------------------------------------------- the screens

    public function test_a_new_setup_starts_on_the_one_basics_screen_then_one_question_per_page(): void
    {
        $this->tenant();

        $this->assertSame(3, (int) $this->wizardSession()->version->version_number);

        $this->get($this->url('step', 'business_name'))
            ->assertOk()
            ->assertSee('Confirm your business details')
            ->assertSee('name="s[business_name][value]"', false)
            ->assertSee('name="s[phone][value]"', false)
            ->assertSee('name="s[email][value]"', false)
            ->assertSee('data-logo-box', false);

        // Every other v3 question is a screen of its own.
        $steps = $this->wizardSession()->version->steps();
        $screens = [];
        foreach ($steps as $step) {
            $screens[$step['screen'] ?? $step['key']][] = $step['key'];
        }
        foreach ($screens as $screen => $keys) {
            $this->assertCount($screen === 'basics' ? 4 : 1, $keys, "Screen {$screen} holds one question (only the basics are grouped).");
        }
    }

    public function test_the_basics_are_prefilled_from_the_real_business(): void
    {
        $this->tenant(['name' => 'Snap Booth Co', 'phone' => '+1 555 0123', 'email' => 'owner@snap.test']);

        $this->get($this->url('step', 'business_name'))
            ->assertSee('value="Snap Booth Co"', false)
            ->assertSee('value="+1 555 0123"', false)
            ->assertSee('value="owner@snap.test"', false)
            ->assertSee('Shared with the rest of MotionGrove');
    }

    public function test_confirming_the_basics_writes_them_to_the_canonical_business_straight_away(): void
    {
        $this->tenant(['name' => 'Snap Booth Co', 'phone' => '+1 555 0123', 'email' => 'owner@snap.test', 'description' => 'Our story.']);

        $this->jpost($this->url('autosave', 'business_name'), $this->basics(['business_name' => 'Glow Booths', 'phone' => '555-0199']))
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('shared_updated', ['Business name', 'Phone number', 'Email address']);

        $business = $this->business->fresh();
        $this->assertSame('Glow Booths', $business->name);
        $this->assertSame('555-0199', $business->phone);
        $this->assertSame('hello@glowbooths.test', $business->email);
        $this->assertSame('Our story.', $business->description, 'A field this screen does not own is never touched.');
    }

    public function test_a_blank_optional_value_never_erases_a_confirmed_business_record(): void
    {
        $this->tenant(['name' => 'Snap Booth Co', 'phone' => '+1 555 0123', 'email' => 'owner@snap.test']);

        $payload = $this->basics();
        $payload['s']['phone']['value'] = '';
        // Continue demands a phone (it is required), so the canonical value cannot be blanked this way either.
        $this->jpost($this->url('autosave', 'business_name'), $payload)->assertStatus(422);

        $this->assertSame('+1 555 0123', $this->business->fresh()->phone);
    }

    public function test_where_are_you_based_is_prefilled_from_the_primary_location_and_updates_it(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA', 'latitude' => 33.6695, 'longitude' => -117.8231, 'address_line_1' => '1 Main St']);

        $this->get($this->url('step', 'base_location'))
            ->assertOk()
            ->assertSee('Where are you based?')
            ->assertSee('value="Irvine"', false)
            ->assertSee('value="CA"', false)
            ->assertSee('<option value="US" selected>', false);

        $this->postBase(['city' => 'Anaheim', 'region' => 'CA', 'country_code' => 'US']);

        $location = $this->business->fresh()->primaryLocation()->first();
        $this->assertSame('Anaheim', $location->city);
        $this->assertSame('1 Main St', $location->address_line_1, 'Unrelated location fields are untouched.');
        $this->assertNull($location->latitude, 'Coordinates for the old city are cleared rather than left describing the wrong place.');
        $this->assertSame(1, BusinessLocation::where('business_id', $this->business->id)->count());
    }

    public function test_a_business_with_no_location_yet_gets_its_primary_location_from_the_base_question(): void
    {
        $this->tenant();
        $this->assertSame(0, BusinessLocation::where('business_id', $this->business->id)->count());

        $this->get($this->url('step', 'base_location'))->assertSee('<option value="US" selected>', false);
        $this->postBase(['city' => 'Irvine', 'region' => 'CA', 'country_code' => 'US']);

        $location = $this->business->fresh()->primaryLocation()->first();
        $this->assertNotNull($location);
        $this->assertSame('Irvine', $location->city);
        $this->assertSame('US', $location->country_code);
    }

    public function test_the_base_question_requires_a_city_and_a_real_country(): void
    {
        $this->tenant();

        $this->jpost($this->url('autosave', 'base_location'), [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => ['base_location'],
            'value' => ['city' => '', 'region' => 'CA', 'country_code' => 'US'],
        ])->assertStatus(422);

        $this->jpost($this->url('autosave', 'base_location'), [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => ['base_location'],
            'value' => ['city' => 'Irvine', 'region' => 'CA', 'country_code' => 'ZZ'],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------ service areas

    public function test_with_no_saved_areas_ten_editable_rows_are_suggested_from_the_confirmed_base(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA']);
        $this->postBase(['city' => 'Irvine', 'region' => 'CA', 'country_code' => 'US']);

        $html = $this->get($this->url('step', 'service_area_cities'))
            ->assertOk()
            ->assertSee('Where do you provide your services?')
            ->assertSee('data-area-note="suggested"', false)
            ->assertSee('suggestions, not verified')
            ->assertSee('Suggest more nearby areas')
            ->assertSee('+ Add another location')
            ->getContent();

        preg_match_all('/name="value\[\]"[^>]*value="([^"]*)"/', $html, $m);
        $rows = array_values(array_filter($m[1]));
        $this->assertCount(10, $rows);
        $this->assertSame('Irvine, CA', $rows[0]);
        $this->assertContains('Costa Mesa, CA', $rows);
        $this->assertSame(1, substr_count($html, 'data-move-row="up"') > 0 ? 1 : 0, 'Every row has move controls.');
        $this->assertStringContainsString('data-remove-row', $html);

        // Suggestions are display only until the owner continues: nothing was written.
        $this->assertArrayNotHasKey('service_area_cities', $this->wizardSession()->answers ?? []);
        $this->assertEmpty($this->business->fresh()->primaryLocation()->first()->service_area_cities);
    }

    public function test_saved_service_areas_are_prefilled_first_and_never_replaced_by_suggestions(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA', 'service_area_cities' => ['Tustin', 'Orange', 'Brea']]);
        $this->postBase(['city' => 'Irvine', 'region' => 'CA', 'country_code' => 'US']);

        $html = $this->get($this->url('step', 'service_area_cities'))
            ->assertSee('data-area-note="canonical"', false)
            ->assertDontSee('data-area-note="suggested"', false)
            ->getContent();

        preg_match_all('/name="value\[\]"[^>]*value="([^"]*)"/', $html, $m);
        $this->assertSame(['Tustin', 'Orange', 'Brea'], array_values(array_filter($m[1])));
    }

    public function test_the_owners_saved_list_wins_over_the_canonical_one_and_keeps_its_exact_order(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA', 'service_area_cities' => ['Tustin', 'Orange', 'Brea']]);
        $this->postBase(['city' => 'Irvine', 'region' => 'CA', 'country_code' => 'US']);

        $mine = ['Orange, CA', 'Irvine, CA', 'Newport Beach, CA', 'Costa Mesa, CA', 'Tustin, CA'];
        $this->postAreas($mine)->assertOk()->assertJsonPath('shared_updated', ['Service areas']);

        $this->assertSame($mine, $this->business->fresh()->primaryLocation()->first()->service_area_cities, 'Written to the canonical location, in order.');
        $this->assertSame(1, BusinessLocation::where('business_id', $this->business->id)->count(), 'A service area is never turned into a physical branch.');

        $html = $this->get($this->url('step', 'service_area_cities'))->getContent();
        preg_match_all('/name="value\[\]"[^>]*value="([^"]*)"/', $html, $m);
        $this->assertSame($mine, array_values(array_filter($m[1])));
    }

    public function test_changing_the_base_never_silently_replaces_the_owners_areas_but_offers_a_refresh(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA']);
        $this->postBase(['city' => 'Irvine', 'region' => 'CA', 'country_code' => 'US']);
        $mine = ['Irvine, CA', 'Tustin, CA'];
        $this->postAreas($mine)->assertOk();

        $this->get($this->url('step', 'service_area_cities'))->assertDontSee('<div class="alert alert-warning py-2" data-area-note="base-changed">', false);

        $this->postBase(['city' => 'Naperville', 'region' => 'IL', 'country_code' => 'US']);

        $html = $this->get($this->url('step', 'service_area_cities'))
            ->assertSee('<div class="alert alert-warning py-2" data-area-note="base-changed">', false)
            ->assertSee('Your base location is now')
            ->assertSee('Replace them with fresh suggestions')
            ->getContent();

        preg_match_all('/name="value\[\]"[^>]*value="([^"]*)"/', $html, $m);
        $this->assertSame($mine, array_values(array_filter($m[1])), 'The owner\'s areas are unchanged.');
    }

    public function test_the_suggestion_endpoint_returns_grounded_places_and_honours_the_exclude_list(): void
    {
        $this->tenant();

        $first = $this->jget($this->url('area-suggestions'), ['city' => 'Irvine', 'region' => 'CA', 'country' => 'US', 'count' => 5])
            ->assertOk()
            ->assertJsonPath('resolved', true)
            ->assertJsonPath('base.label', 'Irvine, CA')
            ->json('suggestions');
        $this->assertCount(5, $first);

        $more = $this->jget($this->url('area-suggestions'), [
            'city' => 'Irvine', 'region' => 'CA', 'country' => 'US', 'count' => 5,
            'exclude' => array_column($first, 'label'),
        ])->assertOk()->json('suggestions');

        $this->assertCount(5, $more);
        $this->assertSame([], array_values(array_intersect(array_column($first, 'label'), array_column($more, 'label'))));
    }

    public function test_an_unknown_base_gets_no_suggestions_and_the_screen_says_so(): void
    {
        $this->tenant([], ['city' => 'Nowheresville', 'region' => 'KS']);
        $this->postBase(['city' => 'Nowheresville', 'region' => 'KS', 'country_code' => 'US']);

        $this->jget($this->url('area-suggestions'), ['city' => 'Nowheresville', 'region' => 'KS', 'country' => 'US'])
            ->assertOk()
            ->assertJson(['resolved' => false, 'suggestions' => []]);

        $this->get($this->url('step', 'service_area_cities'))
            ->assertOk()
            ->assertSee('data-area-note="no-suggestions"', false)
            ->assertSee('We couldn&rsquo;t find nearby communities', false);
    }

    public function test_the_suggestion_endpoint_validates_input_and_is_tenant_scoped(): void
    {
        $this->tenant();

        $this->jget($this->url('area-suggestions'), ['city' => 'Irvine', 'country' => 'USA'])->assertStatus(422);
        $this->jget($this->url('area-suggestions'), ['country' => 'US'])->assertStatus(422);

        [$other] = $this->entitledTenant();
        $this->authenticateAsCustomer($other);
        $this->jget($this->url('area-suggestions'), ['city' => 'Irvine', 'region' => 'CA', 'country' => 'US'])->assertStatus(404);
    }

    public function test_changing_a_conditional_or_earlier_answer_does_not_wipe_unrelated_answers(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA']);
        $this->postBase(['city' => 'Irvine', 'region' => 'CA', 'country_code' => 'US']);
        $this->postAreas(['Irvine, CA', 'Tustin, CA'])->assertOk();

        // Re-confirming the basics later changes only the basics' own answers.
        $this->jpost($this->url('autosave', 'business_name'), $this->basics(['business_name' => 'Glow Booths 2']))->assertOk();

        $answers = $this->wizardSession()->answers;
        $this->assertSame(['Irvine, CA', 'Tustin, CA'], $answers['service_area_cities']);
        $this->assertSame('Irvine', $answers['base_location']['city']);
        $this->assertSame('Glow Booths 2', $answers['business_name']);
    }
}
