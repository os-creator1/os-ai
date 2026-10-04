<?php

namespace Tests\Feature\AgencyOutreach;

use App\Models\AgencyProspectingSetting;
use App\Models\BookingType;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/**
 * Script & Settings: what is shown, the save round trip (canonical tokens,
 * calendar URL, version), warnings that never block, and the live preview.
 */
class UiScriptTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    public function test_an_agency_that_never_saved_sees_the_neutral_defaults_and_the_coming_later_booking_mode(): void
    {
        $a = $this->outreachAgency('Alpha', ['script' => false]);
        $this->authenticateAs($a['customer']);

        $html = $this->get($this->outreachRoute('script.show', $a['workspace']))->assertOk()->getContent();

        $this->assertStringContainsString('{{agency.calendar_link}}', $html);
        $this->assertStringContainsString('Calendar link', $html);
        $this->assertStringContainsString('coming later', $html);
        $this->assertStringContainsString('data-preview-target="message_3"', $html);
        $this->assertStringContainsString('data-merge-insert="{{agency.name}}"', $html);
        $this->assertStringContainsString('data-merge-insert="{{prospect.company}}"', $html);
        $this->assertSame(0, AgencyProspectingSetting::where('workspace_id', $a['workspace']->id)->count(), 'Defaults are never persisted by merely viewing.');
    }

    public function test_save_round_trip_canonicalises_tokens_updates_the_calendar_url_and_bumps_the_version(): void
    {
        $a = $this->outreachAgency('Alpha');
        $this->authenticateAs($a['customer']);
        $before = (int) AgencyProspectingSetting::where('workspace_id', $a['workspace']->id)->value('script_version');

        $this->post($this->outreachRoute('script.update', $a['workspace']), [
            'agency_name' => 'Round Trip Co',
            'message_1' => 'Hi, {{ Agency_Name }} here.',
            'message_2' => 'Open to a call?',
            'message_3' => 'Book here: {{calendar_link}}',
            'booking_url' => 'https://book.example.com/slot',
            'website_url' => 'https://roundtrip.example.com',
            'follow_up_delay_hours' => 48,
            'followup_enabled' => '1',
            'ai_enabled' => '0',
        ])->assertRedirect($this->outreachRoute('script.show', $a['workspace']))->assertSessionHas('flash_success');

        $row = AgencyProspectingSetting::where('workspace_id', $a['workspace']->id)->first();
        $this->assertSame('Hi, {{agency.name}} here.', $row->message_1);
        $this->assertSame('Book here: {{agency.calendar_link}}', $row->message_3);
        $this->assertSame('https://book.example.com/slot', $row->booking_url);
        $this->assertSame('Round Trip Co', $row->agency_name);
        $this->assertSame(48, (int) $row->follow_up_delay_hours);
        $this->assertFalse((bool) $row->ai_enabled);
        $this->assertTrue((bool) $row->followup_enabled);
        $this->assertSame('calendar_link', $row->scheduling_mode);
        $this->assertGreaterThan($before, (int) $row->script_version);

        $this->get($this->outreachRoute('script.show', $a['workspace']))
            ->assertSee('https://book.example.com/slot')->assertSee('Hi, {{agency.name}} here.', false);
    }

    public function test_unknown_tokens_and_a_message_three_without_the_link_warn_but_still_save(): void
    {
        $a = $this->outreachAgency('Alpha');
        $this->authenticateAs($a['customer']);

        $this->post($this->outreachRoute('script.update', $a['workspace']), [
            'message_1' => 'Hello {{nonsense.token}}',
            'message_3' => 'No link here.',
        ])->assertSessionHas('flash_success')->assertSessionHas('outreach_warnings');

        $warnings = implode(' ', session('outreach_warnings'));
        $this->assertStringContainsString('calendar link', $warnings);
        $this->assertStringContainsString('nonsense.token', $warnings);
        $this->assertSame('No link here.', AgencyProspectingSetting::where('workspace_id', $a['workspace']->id)->value('message_3'));
    }

    public function test_an_invalid_calendar_url_is_refused_and_nothing_is_saved(): void
    {
        $a = $this->outreachAgency('Alpha');
        $this->authenticateAs($a['customer']);

        $this->post($this->outreachRoute('script.update', $a['workspace']), [
            'message_1' => 'Should not persist', 'booking_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('booking_url');

        $row = AgencyProspectingSetting::where('workspace_id', $a['workspace']->id)->first();
        $this->assertSame('https://cal.example.com/alpha', $row->booking_url);
        $this->assertStringNotContainsString('Should not persist', (string) $row->message_1);
    }

    public function test_the_preview_renders_with_the_saved_agency_and_a_sample_prospect_and_never_another_agencys_data(): void
    {
        $a = $this->outreachAgency('Alpha');
        $this->outreachAgency('Bravo');
        $this->authenticateAs($a['customer']);

        $json = $this->postJson($this->outreachRoute('script.preview', $a['workspace']), [
            'text' => 'Hi {{prospect.first_name}} at {{prospect.company}} from {{agency_name}}: {{calendar_link}} {{bogus.x}}',
        ])->assertOk()->json();

        $this->assertStringContainsString('Alex', $json['preview']);
        $this->assertStringContainsString('Example Company', $json['preview']);
        $this->assertStringContainsString('Alpha Agency', $json['preview']);
        $this->assertStringContainsString('https://cal.example.com/alpha', $json['preview']);
        $this->assertStringNotContainsString('Bravo', $json['preview']);
        $this->assertStringNotContainsString('{{', $json['preview']);
        $this->assertTrue($json['has_calendar_link']);
        $this->assertSame(['{{bogus.x}}'], $json['unknown']);
    }

    public function test_the_booking_type_picker_lists_only_active_booking_types_of_the_agencys_own_business(): void
    {
        $a = $this->outreachAgency('Alpha');
        $b = $this->outreachAgency('Bravo');

        $mk = function ($business, string $name, bool $active) {
            $location = BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US', 'name' => $name . ' Loc']);

            return BookingType::create(['business_location_id' => $location->id, 'name' => $name, 'duration_minutes' => 30, 'is_active' => $active]);
        };
        $mine = $mk($a['business'], 'Discovery Call', true);
        $mk($a['business'], 'Retired Call', false);
        $mk($b['business'], 'Bravo Secret Call', true);

        $this->authenticateAs($a['customer']);
        $html = $this->get($this->outreachRoute('script.show', $a['workspace']))->assertOk()->getContent();

        $this->assertStringContainsString('Discovery Call', $html);
        $this->assertStringContainsString(route('public.booking.show', $mine->public_booking_uuid), $html);
        $this->assertStringNotContainsString('Retired Call', $html);
        $this->assertStringNotContainsString('Bravo Secret Call', $html);
    }

    public function test_the_existing_agent_setup_route_still_works(): void
    {
        $a = $this->outreachAgency('Alpha');
        $this->authenticateAs($a['customer']);

        $this->get($this->outreachRoute('settings.show', $a['workspace']))->assertOk();
    }
}
