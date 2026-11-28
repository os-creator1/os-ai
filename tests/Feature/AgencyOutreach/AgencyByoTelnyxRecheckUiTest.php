<?php

namespace Tests\Feature\AgencyOutreach;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/** The Recheck connection action and the Phone & Messaging indicators. Telnyx is mocked; nothing is sent. */
class AgencyByoTelnyxRecheckUiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;
    use BuildsAgencyByoChannels;

    private function telnyx(int $profileStatus = 200): void
    {
        Http::swap(new Factory());
        Http::fake([
            'api.telnyx.com/v2/messaging_profiles/*' => Http::response($profileStatus === 200 ? ['data' => ['id' => 'p']] : ['errors' => [['code' => '10009']]], $profileStatus),
            'api.telnyx.com/v2/phone_numbers*' => Http::response(['data' => [['id' => 'n1', 'status' => 'active']]], 200),
        ]);
    }

    public function test_recheck_clears_a_stale_flag_without_the_key_and_sends_nothing(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $channel = $this->byoChannel($agency['workspace'], ['default' => true]);
        $channel->forceFill(['last_error_code' => 'invalid_api_key'])->save();
        $this->authenticateAs($agency['customer']);
        $this->telnyx();

        $this->post(route('customer.workspaces.prospecting.channels.recheck', [$agency['workspace']->uid, $channel->uid]))
            ->assertSessionHas('flash_success');

        $this->assertNull($channel->fresh()->last_error_code);
        $this->assertSame(0, Http::recorded(fn (Request $r) => $r->method() !== 'GET')->count());
    }

    public function test_recheck_failure_shows_the_real_reason_and_a_restriction_is_not_cleared(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $channel = $this->byoChannel($agency['workspace'], ['default' => true]);
        $channel->forceFill(['last_error_code' => 'invalid_api_key'])->save();
        $this->authenticateAs($agency['customer']);
        $this->telnyx(401);

        $this->post(route('customer.workspaces.prospecting.channels.recheck', [$agency['workspace']->uid, $channel->uid]))
            ->assertSessionHas('flash_error', fn ($m) => str_contains($m, 'rejected this access key'));
        $this->assertSame('invalid_api_key', $channel->fresh()->last_error_code);

        $channel->forceFill(['last_error_code' => 'sender_restricted', 'last_error_at' => now()])->save();
        $this->post(route('customer.workspaces.prospecting.channels.recheck', [$agency['workspace']->uid, $channel->uid]))
            ->assertSessionHas('flash_error');
        $this->assertSame('sender_restricted', $channel->fresh()->last_error_code);
    }

    public function test_a_foreign_agency_cannot_recheck_my_channel(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $other = $this->outreachAgency('Beta');
        $mine = $this->byoChannel($agency['workspace'], ['default' => true]);
        $mine->forceFill(['last_error_code' => 'invalid_api_key'])->save();
        $this->authenticateAs($other['customer']);
        $this->telnyx();

        $this->post(route('customer.workspaces.prospecting.channels.recheck', [$other['workspace']->uid, $mine->uid]))->assertStatus(404);
        $this->assertSame('invalid_api_key', $mine->fresh()->last_error_code);
        $this->assertSame(0, Http::recorded()->count());
    }

    public function test_the_page_dates_historical_checks_and_offers_recheck_only_when_an_auth_error_is_saved(): void
    {
        $agency = $this->outreachAgency('Alpha');
        $channel = $this->byoChannel($agency['workspace'], ['default' => true]);
        $channel->forceFill(['webhook_verified_at' => now()->subDays(3), 'sms_capability_confirmed_at' => now()->subDays(3)])->save();
        $this->authenticateAs($agency['customer']);
        $this->telnyx(401);

        $url = route('customer.workspaces.prospecting.phone-messaging', $agency['workspace']->uid);
        $this->get($url)->assertOk()->assertDontSee('Replies working')->assertDontSee('data-role="recheck"', false)
            ->assertSee('Reply webhook verified ' . now()->subDays(3)->format('M j'));

        $channel->forceFill(['last_error_code' => 'invalid_api_key', 'last_error_at' => now()])->save();
        $this->get($url)->assertOk()->assertSee('Recheck connection')->assertSee('Needs attention');
    }
}
