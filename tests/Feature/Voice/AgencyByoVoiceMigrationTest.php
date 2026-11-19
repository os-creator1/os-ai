<?php

namespace Tests\Feature\Voice;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runs the BYO-voice migration against a real MySQL schema that ALREADY holds platform-managed rows: the migration is
 * rolled back, legacy-shaped rows are written, and it is applied again. Nothing may be lost or altered.
 */
class AgencyByoVoiceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_26_090000_add_byo_voice_to_agency_prospecting_channels.php');
    }

    private function legacyCall(int $id, int $numberId): array
    {
        return ['id' => $id, 'business_id' => 9001, 'business_messaging_number_id' => $numberId, 'to_hash' => str_repeat('a', 64),
            'status' => 'ended', 'kind' => 'customer', 'channel' => 'browser', 'uid' => sprintf('00000000-0000-0000-0000-%012d', $id),
            'telnyx_call_control_id' => 'cc-' . $id, 'created_at' => now(), 'updated_at' => now()];
    }

    public function test_the_migration_applies_cleanly_over_existing_platform_rows_without_changing_them(): void
    {
        $m = $this->migration();
        $m->down();

        $this->assertFalse(Schema::hasColumn('business_voice_calls', 'agency_prospecting_channel_id'));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('business_voice_calls')->insert([$this->legacyCall(9101, 7001), $this->legacyCall(9102, 7002)]);
        DB::table('business_voice_browser_credentials')->insert([
            ['business_id' => 9001, 'user_id' => 1, 'telnyx_credential_id' => 'cred-a', 'sip_username' => 'sipa', 'expires_at' => now()->addDays(5), 'created_at' => now(), 'updated_at' => now()],
            ['business_id' => 9001, 'user_id' => 2, 'telnyx_credential_id' => 'cred-b', 'sip_username' => 'sipb', 'expires_at' => now()->addDays(5), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('agency_prospecting_channels')->insert([
            'uid' => '11111111-1111-1111-1111-111111111111', 'workspace_id' => 9501, 'sending_server_id' => 9601,
            'provider' => 'Telnyx', 'sender_number' => '14155550001', 'status' => 'active', 'sender_key' => '14155550001',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $m->up();

        // Existing platform calls: intact, still pointing at their managed number, not attributed to any agency channel.
        $calls = DB::table('business_voice_calls')->orderBy('id')->get();
        $this->assertCount(2, $calls);
        $this->assertSame([7001, 7002], $calls->pluck('business_messaging_number_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['cc-9101', 'cc-9102'], $calls->pluck('telnyx_call_control_id')->all());
        $this->assertSame([null, null], $calls->pluck('agency_prospecting_channel_id')->all());

        // Existing credentials: intact, migrated to the platform-managed marker (0) with no connection binding.
        $creds = DB::table('business_voice_browser_credentials')->orderBy('id')->get();
        $this->assertSame(['cred-a', 'cred-b'], $creds->pluck('telnyx_credential_id')->all());
        $this->assertSame([0, 0], $creds->pluck('agency_prospecting_channel_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([null, null], $creds->pluck('webrtc_connection_id')->all());

        // Existing channel: intact, voice off, nothing verified.
        $channel = DB::table('agency_prospecting_channels')->first();
        $this->assertSame('14155550001', $channel->sender_number);
        $this->assertSame(0, (int) $channel->voice_enabled);
        $this->assertNull($channel->voice_number_key);
        $this->assertNull($channel->voice_connection_verified_at);
    }

    public function test_the_business_voice_number_reference_is_nullable_and_the_new_unique_index_holds(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // A BYO call has no managed number row.
        DB::table('business_voice_calls')->insert(['business_id' => 9001, 'business_messaging_number_id' => null, 'agency_prospecting_channel_id' => 5,
            'to_hash' => str_repeat('b', 64), 'status' => 'requested', 'kind' => 'customer', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, DB::table('business_voice_calls')->whereNull('business_messaging_number_id')->count());

        $row = fn (int $user, int $channel) => ['business_id' => 9001, 'user_id' => $user, 'agency_prospecting_channel_id' => $channel,
            'telnyx_credential_id' => 'c', 'sip_username' => 's' . $user . $channel, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()];

        DB::table('business_voice_browser_credentials')->insert($row(1, 0));
        DB::table('business_voice_browser_credentials')->insert($row(1, 5)); // same user, an agency channel: allowed
        DB::table('business_voice_browser_credentials')->insert($row(1, 6)); // another agency channel: allowed

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('business_voice_browser_credentials')->insert($row(1, 0)); // a second platform credential for the same user
    }

    public function test_the_voice_number_is_unique_across_agency_channels(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $channel = fn (string $uid, int $ws, string $sender, ?string $voiceKey) => [
            'uid' => $uid, 'workspace_id' => $ws, 'sending_server_id' => $ws, 'provider' => 'Telnyx', 'sender_number' => $sender,
            'sender_key' => $sender, 'status' => 'active', 'voice_number_key' => $voiceKey, 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('agency_prospecting_channels')->insert($channel('22222222-2222-2222-2222-222222222222', 1, '14155550010', null));
        DB::table('agency_prospecting_channels')->insert($channel('33333333-3333-3333-3333-333333333333', 2, '14155550011', null)); // many NULLs allowed
        DB::table('agency_prospecting_channels')->insert($channel('44444444-4444-4444-4444-444444444444', 3, '14155550012', '14155550142'));

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('agency_prospecting_channels')->insert($channel('55555555-5555-5555-5555-555555555555', 4, '14155550013', '14155550142'));
    }

    public function test_rolling_back_and_reapplying_loses_no_platform_data(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('business_voice_calls')->insert($this->legacyCall(9301, 7003));
        DB::table('business_voice_browser_credentials')->insert(['business_id' => 9001, 'user_id' => 1, 'telnyx_credential_id' => 'cred-z', 'sip_username' => 'sipz', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $m = $this->migration();
        $m->down();
        $this->assertSame(1, DB::table('business_voice_calls')->count());
        $this->assertSame(1, DB::table('business_voice_browser_credentials')->count());
        $m->up();
        $this->assertSame(1, DB::table('business_voice_calls')->count());
        $this->assertSame('cred-z', DB::table('business_voice_browser_credentials')->value('telnyx_credential_id'));
    }
}
