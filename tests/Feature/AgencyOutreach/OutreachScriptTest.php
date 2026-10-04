<?php

namespace Tests\Feature\AgencyOutreach;

use App\Library\AgencyOutreach\AgencyOutreachBusinessResolver;
use App\Library\AgencyOutreach\OutreachScript;
use App\Library\AgencyOutreach\OutreachScriptDefaults;
use App\Library\AgencyOutreach\OutreachScriptManager;
use App\Library\AgencyOutreach\OutreachScriptRenderer;
use App\Library\AgencyOutreach\OutreachScriptTokens;
use App\Models\AgencyProspectingSetting;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Agency Outreach V1 contract §2/§3 — the script, its tokens and the Business it sends as.
 */
class OutreachScriptTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOutreach();
    }

    public function test_the_business_resolver_returns_only_the_workspaces_single_active_business(): void
    {
        [, $business, $workspace] = $this->agency();

        $this->assertSame($business->id, AgencyOutreachBusinessResolver::forWorkspace($workspace)?->id);

        DB::table('businesses')->where('id', $business->id)->update(['status' => 'inactive']);
        $this->assertNull(AgencyOutreachBusinessResolver::forWorkspace($workspace), 'An inactive Business is not a sender.');

        DB::table('businesses')->where('id', $business->id)->update(['status' => 'draft']);
        $this->assertNull(AgencyOutreachBusinessResolver::forWorkspace($workspace), 'A draft Business is not a sender either.');

        // The schema pins one Business per Workspace (businesses_workspace_id_unique), so "several"
        // cannot occur; the resolver still refuses it, and never reaches into another Workspace.
        [, $otherBusiness] = $this->agency('Other Co');
        DB::table('businesses')->where('id', $business->id)->update(['status' => 'active']);
        $this->assertSame($business->id, AgencyOutreachBusinessResolver::forWorkspace($workspace)?->id);
        $this->assertNotSame($otherBusiness->id, AgencyOutreachBusinessResolver::forWorkspace($workspace)?->id);
    }

    public function test_the_default_copy_is_neutral_and_uses_only_canonical_tokens(): void
    {
        $defaults = OutreachScriptDefaults::all();

        $this->assertSame(
            ['message_1', 'message_2', 'message_3', 'pricing_answer', 'location_answer', 'found_you_answer', 'what_we_do_answer', 'website_answer', 'clarify_answer', 'followup_message'],
            array_keys($defaults),
        );

        $all = strtolower(implode(' ', $defaults));

        foreach (['photo', 'booth', 'hvac', '9%', 'maps', 'jazmin', 'austin', '$', 'commission'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $all, "Default copy must not name '{$forbidden}'.");
        }

        $this->assertStringContainsString('{{agency.name}}', $defaults['message_1']);
        $this->assertStringContainsString('{{agency.calendar_link}}', $defaults['message_3']);
        $this->assertStringContainsString('{{agency.calendar_link}}', $defaults['followup_message']);
        $this->assertDoesNotMatchRegularExpression('/\{\{\s*(agency_name|website|calendar_link)\s*\}\}/', $all);
    }

    public function test_the_owners_vocabulary_is_rewritten_to_canonical_tokens_tolerant_of_spaces(): void
    {
        $this->assertSame(
            'a {{agency.name}} b {{agency.website}} c {{agency.calendar_link}} d {{agency.name}}',
            OutreachScriptTokens::canonicalise('a {{agency_name}} b {{ website }} c {{  calendar_link}} d {{Agency_Name }}'),
        );
        $this->assertSame('{{prospect.first_name}} {{unknown_thing}}', OutreachScriptTokens::canonicalise('{{prospect.first_name}} {{unknown_thing}}'));
    }

    public function test_tokens_resolve_through_the_canonical_engine_and_never_leak(): void
    {
        [, , $workspace] = $this->agency('Snap Booth Co');
        $this->saveScript($workspace);
        $prospect = $this->prospect($workspace);

        $text = OutreachScriptRenderer::render(
            'Hi {{prospect.first_name}} of {{prospect.company}} ({{prospect.full_name}}): {{agency.name}} {{agency.website}} {{agency.calendar_link}}',
            $workspace,
            $prospect,
        );

        $this->assertSame('Hi Dana of Maple Venue (Dana Maple): Snap Booth Co https://snap.example.test https://cal.example.test/snap', $text);

        $unknown = OutreachScriptRenderer::render('A {{nonsense.token}} B {{ agency.nope }} C {{broken', $workspace, $prospect);
        $this->assertStringNotContainsString('{{nonsense', $unknown);
        $this->assertStringNotContainsString('agency.nope', $unknown);
        $this->assertSame('A B C {{broken', $unknown, 'Unknown tokens render blank; only an unterminated brace is left alone.');

        $this->assertSame(['{{nonsense.token}}'], OutreachScriptRenderer::unknownTokens('Hi {{agency.name}} {{nonsense.token}}', $workspace));
    }

    public function test_an_invalid_url_renders_blank_and_a_missing_business_still_never_leaks_a_token(): void
    {
        [, $business, $workspace] = $this->agency();
        AgencyProspectingSetting::create(['workspace_id' => $workspace->id, 'agency_name' => 'Snap Booth Co', 'booking_url' => 'javascript:alert(1)']);

        $this->assertSame('Book:.', OutreachScriptRenderer::render('Book: {{agency.calendar_link}}.', $workspace));
        $this->assertNull(OutreachScript::forWorkspace($workspace)->calendarUrl());

        DB::table('businesses')->where('id', $business->id)->update(['status' => 'inactive']);

        $text = OutreachScriptRenderer::render('From {{agency.name}} {{business.name}} {{contact.first_name}} {{prospect.company}}', $workspace, $this->prospect($workspace));
        $this->assertStringNotContainsString('{{', $text);
        $this->assertStringStartsWith('From Snap Booth Co', $text, 'The saved Agency name still resolves without a resolvable Business.');
    }

    public function test_agency_a_and_agency_b_scripts_never_leak_into_each_other(): void
    {
        [, , $a] = $this->agency('Alpha Booths');
        [, , $b] = $this->agency('Beta Gyms');

        $this->saveScript($a, ['agency_name' => 'Alpha Booths', 'booking_url' => 'https://cal.example.test/alpha', 'message_1' => 'Alpha says hi from {{agency_name}}']);
        $this->saveScript($b, ['agency_name' => 'Beta Gyms', 'booking_url' => 'https://cal.example.test/beta', 'message_1' => 'Beta says hi from {{agency_name}}']);

        $prospectA = $this->prospect($a, '12025551001', ['contact_name' => 'Ann Alpha']);
        $prospectB = $this->prospect($b, '12025551002', ['contact_name' => 'Bob Beta']);

        $this->assertSame('Alpha says hi from Alpha Booths', OutreachScriptRenderer::render(OutreachScript::forWorkspace($a)->get('message_1'), $a, $prospectA));
        $this->assertSame('Beta says hi from Beta Gyms', OutreachScriptRenderer::render(OutreachScript::forWorkspace($b)->get('message_1'), $b, $prospectB));
        $this->assertSame('https://cal.example.test/alpha', OutreachScript::forWorkspace($a)->calendarUrl());
        $this->assertSame('https://cal.example.test/beta', OutreachScript::forWorkspace($b)->calendarUrl());

        // A prospect of Agency B rendered against Agency A's context is ABSENT, never B's data.
        $this->assertSame('Hi, Alpha Booths', OutreachScriptRenderer::render('Hi {{prospect.first_name}}, {{agency.name}}', $a, $prospectB));
    }

    public function test_get_returns_saved_text_else_the_default_and_version_defaults_to_one(): void
    {
        [, , $workspace] = $this->agency();
        $script = OutreachScript::forWorkspace($workspace);

        $this->assertSame(OutreachScriptDefaults::all()['message_2'], $script->get('message_2'));
        $this->assertSame(1, $script->version());
        $this->assertSame('', $script->get('not_a_field'));
        $this->assertSame('Snap Booth Co', $script->agencyName(), 'Falls back to the Agency Business name.');

        OutreachScriptManager::save($workspace, ['message_2' => 'Custom two']);
        $this->assertSame('Custom two', OutreachScript::forWorkspace($workspace)->get('message_2'));
        $this->assertSame(OutreachScriptDefaults::all()['message_3'], OutreachScript::forWorkspace($workspace)->get('message_3'));
    }

    public function test_save_canonicalises_bumps_the_version_only_on_text_change_and_pins_the_scheduling_mode(): void
    {
        [, , $workspace] = $this->agency();

        $settings = OutreachScriptManager::save($workspace, ['message_3' => 'Book: {{calendar_link}}', 'booking_url' => 'https://cal.example.test/x', 'scheduling_mode' => 'conversational_scheduling']);
        $this->assertSame('Book: {{agency.calendar_link}}', $settings->message_3);
        $this->assertSame('calendar_link', $settings->scheduling_mode, 'Conversational scheduling is not selectable in V1.');
        $version = $settings->script_version;
        $this->assertSame(2, $version);

        $same = OutreachScriptManager::save($workspace, ['message_3' => 'Book: {{calendar_link}}']);
        $this->assertSame($version, $same->script_version, 'Saving identical text does not re-version the script.');

        $switchOnly = OutreachScriptManager::save($workspace, ['ai_enabled' => false, 'followup_enabled' => '0', 'follow_up_delay_hours' => 48, 'offer' => 'Offer text']);
        $this->assertSame($version, $switchOnly->script_version, 'Settings that are not script text never bump the version.');
        $this->assertFalse($switchOnly->ai_enabled);
        $this->assertFalse($switchOnly->followup_enabled);
        $this->assertSame(48, $switchOnly->follow_up_delay_hours);

        $changed = OutreachScriptManager::save($workspace, ['message_3' => 'Different {{calendar_link}}']);
        $this->assertSame($version + 1, $changed->script_version);

        $cleared = OutreachScriptManager::save($workspace, ['message_3' => '   ']);
        $this->assertNull($cleared->message_3, 'A blank field clears back to the neutral default.');
        $this->assertSame($version + 2, $cleared->script_version);
    }

    public function test_save_rejects_non_http_urls_and_ignores_unknown_fields(): void
    {
        [, , $workspace] = $this->agency();

        foreach (['booking_url' => 'ftp://x.example.test', 'website_url' => 'javascript:alert(1)'] as $field => $bad) {
            try {
                OutreachScriptManager::save($workspace, [$field => $bad]);
                $this->fail("{$field} must be refused.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        try {
            OutreachScriptManager::save($workspace, ['follow_up_delay_hours' => 0]);
            $this->fail('A zero delay must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('follow_up_delay_hours', $e->errors());
        }

        $other = Business::query()->count();
        $settings = OutreachScriptManager::save($workspace, ['workspace_id' => 999999, 'uid' => 'forged', 'script_version' => 50, 'agency_name' => 'Ok']);
        $this->assertSame($workspace->id, $settings->workspace_id);
        $this->assertNotSame('forged', $settings->uid);
        $this->assertSame(1, $settings->script_version, 'script_version is the manager\'s to bump, never the caller\'s.');
        $this->assertSame('Ok', $settings->agency_name);
        $this->assertSame($other, Business::query()->count());
    }
}
