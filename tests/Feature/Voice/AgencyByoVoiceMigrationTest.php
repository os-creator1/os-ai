<?php

namespace Tests\Feature\Voice;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Runs the BYO-voice migration against a real MySQL schema that ALREADY holds platform-managed rows: the migration is
 * rolled back, legacy-shaped rows are written, and it is applied again.
 *
 * MySQL DDL commits implicitly, so RefreshDatabase's transaction cannot undo anything these tests do. Isolation is explicit
 * instead: every fixture row carries a reserved marker (business 9001 / sender numbers 141555500xx), tearDown always deletes
 * them, always turns FK checks back on, and always puts the schema back to the migrated state - even if a test failed
 * between down() and up().
 */
class AgencyByoVoiceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const BUSINESS = 9001;

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_26_090000_add_byo_voice_to_agency_prospecting_channels.php');
    }

    protected function tearDown(): void
    {
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // Restore the schema first (a failure between down() and up() leaves the voice columns missing).
            if (! Schema::hasColumn('business_voice_calls', 'agency_prospecting_channel_id')
                || ! Schema::hasColumn('business_voice_browser_credentials', 'session_started_at')) {
                try {
                    $this->migration()->down();
                } catch (\Throwable) {
                    // already (partly) rolled back
                }
                $this->migration()->up();
            }

            DB::table('business_voice_calls')->where('business_id', self::BUSINESS)->delete();
            DB::table('business_voice_browser_credentials')->where('business_id', self::BUSINESS)->delete();
            DB::table('agency_prospecting_channels')->where('sender_number', 'like', '141555500%')->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            parent::tearDown();
        }
    }

    private function legacyCall(int $id, int $numberId): array
    {
        return ['id' => $id, 'business_id' => self::BUSINESS, 'business_messaging_number_id' => $numberId, 'to_hash' => str_repeat('a', 64),
            'status' => 'ended', 'kind' => 'customer', 'channel' => 'browser', 'uid' => (string) Str::uuid(),
            'telnyx_call_control_id' => 'cc-' . $id, 'created_at' => now(), 'updated_at' => now()];
    }

    private function legacyCredential(int $user, string $id): array
    {
        return ['business_id' => self::BUSINESS, 'user_id' => $user, 'telnyx_credential_id' => $id, 'sip_username' => 'sip' . $id,
            'expires_at' => now()->addDays(5), 'created_at' => now(), 'updated_at' => now()];
    }

    private function channelRow(string $sender, int $ws, ?string $voiceKey = null): array
    {
        return ['uid' => (string) Str::uuid(), 'workspace_id' => $ws, 'sending_server_id' => $ws, 'provider' => 'Telnyx', 'sender_number' => $sender,
            'sender_key' => $sender, 'status' => 'active', 'voice_number_key' => $voiceKey, 'created_at' => now(), 'updated_at' => now()];
    }

    public function test_the_migration_applies_cleanly_over_existing_platform_rows_without_changing_them(): void
    {
        $m = $this->migration();
        $m->down();
        $this->assertFalse(Schema::hasColumn('business_voice_calls', 'agency_prospecting_channel_id'));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('business_voice_calls')->insert([$this->legacyCall(9101, 7001), $this->legacyCall(9102, 7002)]);
        DB::table('business_voice_browser_credentials')->insert([$this->legacyCredential(1, 'cred-a'), $this->legacyCredential(2, 'cred-b')]);
        $legacy = $this->channelRow('14155550001', 9501);
        $legacy['uid'] = '11111111-1111-4111-8111-111111111111';
        DB::table('agency_prospecting_channels')->insert($legacy);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $m->up();

        $calls = DB::table('business_voice_calls')->where('business_id', self::BUSINESS)->orderBy('id')->get();
        $this->assertCount(2, $calls);
        $this->assertSame([7001, 7002], $calls->pluck('business_messaging_number_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['cc-9101', 'cc-9102'], $calls->pluck('telnyx_call_control_id')->all());
        $this->assertSame([null, null], $calls->pluck('agency_prospecting_channel_id')->all());
        $this->assertSame(36, strlen($calls[0]->uid));
        $this->assertNotSame($calls[0]->uid, $calls[1]->uid);

        $creds = DB::table('business_voice_browser_credentials')->where('business_id', self::BUSINESS)->orderBy('id')->get();
        $this->assertSame(['cred-a', 'cred-b'], $creds->pluck('telnyx_credential_id')->all());
        $this->assertSame([0, 0], $creds->pluck('agency_prospecting_channel_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([null, null], $creds->pluck('webrtc_connection_id')->all());
        $this->assertSame([null, null], $creds->pluck('session_started_at')->all());

        $channel = DB::table('agency_prospecting_channels')->where('sender_number', '14155550001')->first();
        $this->assertSame(0, (int) $channel->voice_enabled);
        $this->assertNull($channel->voice_number_key);
        $this->assertNull($channel->voice_connection_verified_at);
    }

    public function test_the_business_voice_number_reference_is_nullable_and_the_new_unique_index_holds(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        DB::table('business_voice_calls')->insert(['business_id' => self::BUSINESS, 'business_messaging_number_id' => null, 'agency_prospecting_channel_id' => 5,
            'to_hash' => str_repeat('b', 64), 'status' => 'requested', 'kind' => 'customer', 'uid' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, DB::table('business_voice_calls')->where('business_id', self::BUSINESS)->whereNull('business_messaging_number_id')->count());

        $row = fn (int $user, int $channel) => $this->legacyCredential($user, 'c' . $user . $channel) + ['agency_prospecting_channel_id' => $channel];
        DB::table('business_voice_browser_credentials')->insert($row(1, 0));
        DB::table('business_voice_browser_credentials')->insert($row(1, 5)); // same user, an agency channel: allowed
        DB::table('business_voice_browser_credentials')->insert($row(1, 6)); // another agency channel: allowed

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('business_voice_browser_credentials')->insert($row(1, 0)); // a second platform credential for the same user
    }

    public function test_the_voice_number_is_unique_across_agency_channels(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('agency_prospecting_channels')->insert($this->channelRow('14155550010', 1));
        DB::table('agency_prospecting_channels')->insert($this->channelRow('14155550011', 2)); // many NULL voice keys allowed
        DB::table('agency_prospecting_channels')->insert($this->channelRow('14155550012', 3, '14155550142'));

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('agency_prospecting_channels')->insert($this->channelRow('14155550013', 4, '14155550142'));
    }

    public function test_rolling_back_and_reapplying_loses_no_platform_data_and_leaves_no_rows_behind_by_other_tests(): void
    {
        // Rows left committed by an earlier DDL test would make these exact counts fail: tearDown must have removed them.
        $this->assertSame(0, DB::table('business_voice_calls')->where('business_id', self::BUSINESS)->count());
        $this->assertSame(0, DB::table('business_voice_browser_credentials')->where('business_id', self::BUSINESS)->count());

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('business_voice_calls')->insert($this->legacyCall(9301, 7003));
        DB::table('business_voice_browser_credentials')->insert($this->legacyCredential(1, 'cred-z'));
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $m = $this->migration();
        $m->down();
        $this->assertSame(1, DB::table('business_voice_calls')->where('business_id', self::BUSINESS)->count());
        $this->assertSame(1, DB::table('business_voice_browser_credentials')->where('business_id', self::BUSINESS)->count());
        $m->up();
        $this->assertSame(1, DB::table('business_voice_calls')->where('business_id', self::BUSINESS)->count());
        $this->assertSame('cred-z', DB::table('business_voice_browser_credentials')->where('business_id', self::BUSINESS)->value('telnyx_credential_id'));
    }
}
