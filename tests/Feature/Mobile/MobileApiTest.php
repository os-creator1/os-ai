<?php

namespace Tests\Feature\Mobile;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\MobileDevice;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * MotionGrove Mobile API: authentication, authorization and tenant isolation. No provider is reachable
 * (Http::preventStrayRequests) — the capability endpoint must never contact Telnyx.
 */
class MobileApiTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    /** @var array{0: Customer, 1: Business, 2: Workspace} */
    private array $a;
    /** @var array{0: Customer, 1: Business, 2: Workspace} */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        RateLimiter::clear('x');

        $this->a = $this->tenant(businessName: 'Alpha Studio', workspaceName: 'Alpha WS');
        $this->b = $this->tenant(businessName: 'Bravo Studio', workspaceName: 'Bravo WS');

        foreach ([$this->a, $this->b] as $t) {
            $t[0]->forceFill(['permissions' => json_encode($this->allCustomerPermissions())])->save();
            $t[0]->user->forceFill(['password' => Hash::make(self::PASSWORD)])->save();
        }
    }

    private function url(string $path = ''): string
    {
        return '/api/mobile/v1/' . ltrim($path, '/');
    }

    private function tenantPath(array $t, string $tail): string
    {
        return 'workspaces/' . $t[2]->uid . '/businesses/' . $t[1]->uid . '/' . $tail;
    }

    private function login(array $t, string $install = 'install-aaaa-0001'): string
    {
        $res = $this->postJson($this->url('auth/login'), [
            'email' => $t[0]->user->email, 'password' => self::PASSWORD,
            'device' => ['installation_id' => $install, 'platform' => 'android', 'name' => 'Pixel', 'app_version' => '0.1.0'],
        ])->assertOk();

        return $res->json('data.token');
    }

    private function as(string $token): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function box(array $t, string $to, string $text, ?int $businessId = -1): ChatBox
    {
        $box = new ChatBox(['user_id' => $t[1]->customer_id, 'business_id' => $businessId === -1 ? $t[1]->id : $businessId, 'from' => '15550000000', 'to' => $to, 'reply_by_customer' => true]);
        $box->uid = (string) Str::uuid();
        $box->save();
        DB::table('chat_box_messages')->insert(['box_id' => $box->id, 'message' => $text, 'direction' => 'incoming', 'sms_type' => 'plain', 'send_by' => 'to', 'created_at' => now(), 'updated_at' => now()]);

        return $box->refresh();
    }

    // ---- authentication ---------------------------------------------------------------------------------------

    public function test_login_issues_a_mobile_scoped_expiring_token_and_registers_the_device(): void
    {
        $token = $this->login($this->a);

        $row = DB::table('personal_access_tokens')->where('tokenable_id', $this->a[0]->user_id)->first();
        $this->assertSame(['mobile'], json_decode($row->abilities, true));
        $this->assertNotNull($row->expires_at);
        $this->assertSame(1, MobileDevice::query()->where('user_id', $this->a[0]->user_id)->count());
        $this->assertNotSame('', $token);
    }

    public function test_bad_credentials_unknown_email_and_disabled_account_are_one_identical_401(): void
    {
        $device = ['installation_id' => 'install-aaaa-0002', 'platform' => 'ios'];
        $wrong = $this->postJson($this->url('auth/login'), ['email' => $this->a[0]->user->email, 'password' => 'nope-nope', 'device' => $device]);
        $unknown = $this->postJson($this->url('auth/login'), ['email' => 'ghost@example.test', 'password' => 'nope-nope', 'device' => $device]);
        $this->a[0]->user->forceFill(['status' => false])->save();
        $disabled = $this->postJson($this->url('auth/login'), ['email' => $this->a[0]->user->email, 'password' => self::PASSWORD, 'device' => $device]);

        foreach ([$wrong, $unknown, $disabled] as $r) {
            $r->assertStatus(401);
            $this->assertSame('invalid_credentials', $r->json('error.code'));
        }
        $this->assertSame($wrong->getContent(), $unknown->getContent());
        $this->assertSame($wrong->getContent(), $disabled->getContent());
    }

    public function test_two_factor_accounts_get_a_challenge_not_a_token(): void
    {
        Notification::fake();
        $this->a[0]->user->forceFill(['two_factor' => true])->save();

        $res = $this->postJson($this->url('auth/login'), [
            'email' => $this->a[0]->user->email, 'password' => self::PASSWORD,
            'device' => ['installation_id' => 'install-aaaa-0003', 'platform' => 'ios'],
        ])->assertStatus(202)->assertJsonPath('data.two_factor_required', true);
        $this->assertNull($res->json('data.token'));
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        // The exchange itself is covered by MobileTwoFactorTest.
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $body = ['email' => $this->a[0]->user->email, 'password' => 'wrong-wrong', 'device' => ['installation_id' => 'install-aaaa-0004', 'platform' => 'ios']];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->url('auth/login'), $body)->assertStatus(401);
        }
        $this->postJson($this->url('auth/login'), $body)->assertStatus(429);
        // Even the correct password is refused while locked, so guessing cannot continue.
        $this->postJson($this->url('auth/login'), array_merge($body, ['password' => self::PASSWORD]))->assertStatus(429);
    }

    public function test_login_validates_its_input(): void
    {
        $this->postJson($this->url('auth/login'), ['email' => 'not-an-email', 'password' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_every_authenticated_route_requires_a_token(): void
    {
        $this->getJson($this->url('businesses'))->assertStatus(401);
        $this->getJson($this->url($this->tenantPath($this->a, 'conversations')))->assertStatus(401);
        $this->putJson($this->url('devices/current'), [])->assertStatus(401);
        $this->postJson($this->url('auth/logout'))->assertStatus(401);
    }

    public function test_a_non_mobile_token_including_a_wildcard_one_is_refused(): void
    {
        $wild = $this->a[0]->user->createToken('legacy', ['*'])->plainTextToken;
        $other = $this->a[0]->user->createToken('legacy2', ['read'])->plainTextToken;

        $this->as($wild)->getJson($this->url('businesses'))->assertStatus(401);
        $this->as($other)->getJson($this->url('businesses'))->assertStatus(401);
    }

    public function test_a_mobile_token_cannot_reach_the_legacy_v3_api(): void
    {
        $token = $this->login($this->a);

        $this->as($token)->getJson('/api/v3/me')->assertStatus(403);
        $this->as($token)->postJson('/api/v3/sms/send', [])->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_logout_and_refresh_retire_the_old_token(): void
    {
        $first = $this->login($this->a);
        $second = $this->as($first)->postJson($this->url('auth/refresh'))->assertOk()->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->as($first)->getJson($this->url('businesses'))->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->as($second)->getJson($this->url('businesses'))->assertOk();
        $this->assertSame(1, MobileDevice::query()->count());

        $this->as($second)->postJson($this->url('auth/logout'))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->as($second)->getJson($this->url('businesses'))->assertStatus(401);
        $this->assertSame(0, MobileDevice::query()->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_signing_in_again_on_the_same_install_leaves_one_live_token(): void
    {
        $this->login($this->a, 'install-same-0001');
        $this->login($this->a, 'install-same-0001');

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->assertSame(1, MobileDevice::query()->count());
    }

    public function test_a_disabled_account_loses_an_already_issued_token(): void
    {
        $token = $this->login($this->a);
        $this->a[0]->user->forceFill(['status' => false])->save();

        $this->as($token)->getJson($this->url('businesses'))->assertStatus(401);
    }

    // ---- business selector -----------------------------------------------------------------------------------

    public function test_forced_signout_or_password_reset_invalidates_an_existing_mobile_token_and_refresh(): void
    {
        $token = $this->login($this->a, 'install-revoked-001');
        $this->as($token)->getJson($this->url('businesses'))->assertOk();

        // Same persisted revocation mechanism as the web middleware, including
        // its one-second precision: do not let an old device refresh the token.
        $this->a[0]->user->forceFill(['password_changed_at' => now()->addSecond()])->save();
        $this->app['auth']->forgetGuards();
        $this->as($token)->getJson($this->url('businesses'))->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->as($token)->postJson($this->url('auth/refresh'))->assertStatus(401);

        // New sign-in after the revocation timestamp is authorized.
        $this->travel(3)->seconds();
        $this->app['auth']->forgetGuards();
        $newToken = $this->login($this->a, 'install-revoked-001');
        $this->app['auth']->forgetGuards();
        $this->as($newToken)->getJson($this->url('businesses'))->assertOk();
    }

    public function test_the_business_selector_lists_only_the_actors_own_businesses(): void
    {
        $res = $this->as($this->login($this->a))->getJson($this->url('businesses'))->assertOk();

        $uids = collect($res->json('data.businesses'))->pluck('business_uid')->all();
        $this->assertSame([$this->a[1]->uid], $uids);
        $this->assertTrue($res->json('data.businesses.0.features.conversations'));
        $this->assertStringNotContainsString($this->b[1]->uid, $res->getContent());
        $this->assertStringNotContainsString('push_token', $res->getContent());
    }

    public function test_a_locked_or_elapsed_grace_workspace_cannot_be_read_through_mobile(): void
    {
        $box = $this->box($this->a, '15551110001', 'Private lead under locked account');
        $token = $this->login($this->a, 'install-locked-001');
        $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'conversations')))->assertOk();

        $this->lockWorkspace($this->a[2], ['locked_at' => now()]);
        $this->app['auth']->forgetGuards();
        $list = $this->as($token)->getJson($this->url('businesses'))->assertOk();
        $this->assertNotContains($this->a[1]->uid, collect($list->json('data.businesses'))->pluck('business_uid')->all());

        $this->app['auth']->forgetGuards();
        $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'conversations')))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'conversations/' . $box->uid)))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'calls/capability')))->assertNotFound();

        // A missed background lock job must not leave an elapsed grace usable.
        $this->lockWorkspace($this->a[2], ['locked_at' => null, 'grace_started_at' => now()->subDays(8)]);
        $this->app['auth']->forgetGuards();
        $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'conversations')))->assertNotFound();
    }

    /** These writes affect only RefreshDatabase's disposable test rows. */
    private function lockWorkspace(Workspace $workspace, array $attributes): void
    {
        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update($attributes);
    }

    public function test_owner_hidden_modules_block_mobile_reads_even_when_member_permissions_and_plan_allow_them(): void
    {
        $member = $this->createCustomer();
        $member->forceFill(['permissions' => json_encode($this->allCustomerPermissions())])->save();
        $member->user->forceFill(['password' => Hash::make(self::PASSWORD)])->save();
        $membership = $this->member($this->a[2], $member->user, WorkspaceMembershipRole::Staff);
        $token = $this->login([$member, $this->a[1], $this->a[2]], 'install-hidden-001');

        // The owner hides these modules AFTER the phone signs in; a token
        // cannot continue reading a module the owner has switched off.
        $membership->forceFill(['hidden_modules' => ['conversations', 'contacts']])->save();

        $box = $this->box($this->a, '15551110001', 'Private lead conversation');
        $uid = (string) Str::uuid();
        $group = DB::table('contact_groups')->insertGetId([
            'uid' => (string) Str::uuid(), 'customer_id' => $this->a[1]->customer_id,
            'business_id' => $this->a[1]->id, 'name' => 'Mobile hidden test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contacts')->insert([
            'uid' => $uid, 'customer_id' => $this->a[1]->customer_id,
            'business_id' => $this->a[1]->id, 'group_id' => $group,
            'phone' => 15554445555, 'status' => 'subscribe',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->app['auth']->forgetGuards();
        $list = $this->as($token)->getJson($this->url('businesses'))->assertOk();
        $this->assertFalse($list->json('data.businesses.0.features.conversations'));
        $this->assertFalse($list->json('data.businesses.0.features.contacts'));
        $this->app['auth']->forgetGuards();
        $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'conversations')))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'conversations/' . $box->uid)))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'contacts/' . $uid)))->assertNotFound();

        // Hiding a module for this member never hides it for the owner.
        $this->app['auth']->forgetGuards();
        $ownerToken = $this->login($this->a, 'install-owner-002');
        $this->app['auth']->forgetGuards();
        $this->as($ownerToken)->getJson($this->url($this->tenantPath($this->a, 'conversations')))->assertOk();
    }

    // ---- tenant isolation ------------------------------------------------------------------------------------

    public function test_conversations_list_is_scoped_to_the_selected_business(): void
    {
        $mine = $this->box($this->a, '15551110001', 'Hello Alpha');
        $this->box($this->b, '15552220002', 'Secret Bravo text');
        $this->box($this->a, '15553330003', 'Legacy orphan', null);

        $res = $this->as($this->login($this->a))->getJson($this->url($this->tenantPath($this->a, 'conversations')))->assertOk();

        $this->assertSame([$mine->uid], collect($res->json('data'))->pluck('uid')->all());
        $this->assertStringNotContainsString('Secret Bravo text', $res->getContent());
        $this->assertStringNotContainsString('Legacy orphan', $res->getContent());
        $this->assertSame('inbound', $res->json('data.0.last_message.direction'));
    }

    public function test_another_businesses_routes_are_one_indistinguishable_404(): void
    {
        $theirs = $this->box($this->b, '15552220002', 'Secret Bravo text');
        $token = $this->login($this->a);

        $foreignBusiness = $this->as($token)->getJson($this->url($this->tenantPath($this->b, 'conversations')));
        $mixed = $this->as($token)->getJson($this->url('workspaces/' . $this->a[2]->uid . '/businesses/' . $this->b[1]->uid . '/conversations'));
        $crossBox = $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'conversations/' . $theirs->uid)));
        $foreignBox = $this->as($token)->getJson($this->url($this->tenantPath($this->b, 'conversations/' . $theirs->uid)));
        $missing = $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'conversations/' . Str::uuid())));
        $numericId = $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'conversations/' . $theirs->id)));
        $nonsense = $this->as($token)->getJson($this->url('workspaces/nope/businesses/nope/conversations'));

        foreach ([$foreignBusiness, $mixed, $crossBox, $foreignBox, $missing, $numericId, $nonsense] as $r) {
            $r->assertStatus(404);
            $this->assertSame('not_found', $r->json('error.code'));
            $this->assertStringNotContainsString('Secret Bravo text', $r->getContent());
        }
        $this->assertSame($foreignBusiness->getContent(), $crossBox->getContent());
        $this->assertSame($foreignBusiness->getContent(), $nonsense->getContent());
    }

    public function test_conversation_detail_returns_the_thread_for_the_own_business(): void
    {
        $box = $this->box($this->a, '15551110001', 'Need a quote please');
        $res = $this->as($this->login($this->a))->getJson($this->url($this->tenantPath($this->a, 'conversations/' . $box->uid)))->assertOk();

        $this->assertSame('Need a quote please', $res->json('data.messages.0.body'));
        $this->assertSame('inbound', $res->json('data.messages.0.direction'));
        $this->assertFalse($res->json('data.has_more'));
        // Read-only in Phase 1: opening a conversation must not clear unread state.
        $this->assertSame((int) $box->notification, (int) $box->fresh()->notification);
    }

    public function test_the_conversations_permission_is_enforced_for_a_token(): void
    {
        $this->box($this->a, '15551110001', 'Hello Alpha');
        $this->a[0]->forceFill(['permissions' => json_encode(['access_backend'])])->save();

        $this->as($this->login($this->a))->getJson($this->url($this->tenantPath($this->a, 'conversations')))->assertStatus(404);
    }

    public function test_a_contact_of_another_business_is_not_found(): void
    {
        $contactId = fn (array $t) => DB::table('contacts')->insertGetId([
            'uid' => (string) Str::uuid(), 'customer_id' => $t[1]->customer_id, 'business_id' => $t[1]->id,
            'group_id' => DB::table('contact_groups')->insertGetId(['uid' => (string) Str::uuid(), 'customer_id' => $t[1]->customer_id, 'business_id' => $t[1]->id, 'name' => 'g', 'created_at' => now(), 'updated_at' => now()]),
            'phone' => 15554440000 + $t[1]->id, 'status' => 'subscribe', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mine = DB::table('contacts')->where('id', $contactId($this->a))->value('uid');
        $theirs = DB::table('contacts')->where('id', $contactId($this->b))->value('uid');
        $token = $this->login($this->a);

        $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'contacts/' . $mine)))->assertOk()->assertJsonPath('data.uid', $mine);
        $this->as($token)->getJson($this->url($this->tenantPath($this->a, 'contacts/' . $theirs)))->assertStatus(404);
        $this->as($token)->getJson($this->url($this->tenantPath($this->b, 'contacts/' . $theirs)))->assertStatus(404);
    }

    // ---- devices, calling ------------------------------------------------------------------------------------

    public function test_push_registration_is_stored_hidden_and_moves_with_the_phone(): void
    {
        $pushToken = 'ExponentPushToken[abcdefghijklmnop]';
        $ta = $this->login($this->a, 'install-shared-01');
        $res = $this->as($ta)->putJson($this->url('devices/current'), ['push_provider' => 'expo', 'push_token' => $pushToken])->assertOk();
        $this->assertStringNotContainsString($pushToken, $res->getContent());
        $this->assertSame($pushToken, MobileDevice::query()->where('user_id', $this->a[0]->user_id)->firstOrFail()->push_token);

        // The same phone signs in as another person: the previous owner no longer receives pushes there.
        $this->app['auth']->forgetGuards();
        $tb = $this->login($this->b, 'install-shared-01');
        $this->app['auth']->forgetGuards();
        $this->as($tb)->putJson($this->url('devices/current'), ['push_provider' => 'expo', 'push_token' => $pushToken])->assertOk();
        $this->assertNull(MobileDevice::query()->where('user_id', $this->a[0]->user_id)->first()?->push_token);
        $this->assertSame($pushToken, MobileDevice::query()->where('user_id', $this->b[0]->user_id)->firstOrFail()->push_token);

        $this->as($tb)->deleteJson($this->url('devices/current'))->assertOk();
        $this->assertNull(MobileDevice::query()->where('user_id', $this->b[0]->user_id)->firstOrFail()->push_token);
    }

    public function test_push_registration_validates_input(): void
    {
        $this->as($this->login($this->a))->putJson($this->url('devices/current'), ['push_provider' => 'sms', 'push_token' => 'x'])->assertStatus(422);
    }

    public function test_calling_capability_never_contacts_a_provider(): void
    {
        $res = $this->as($this->login($this->a))->getJson($this->url($this->tenantPath($this->a, 'calls/capability')))->assertOk();

        $this->assertFalse($res->json('data.available'));
        $this->assertNotEmpty($res->json('data.reason'));
        Http::assertNothingSent();

        $this->as($this->login($this->a))->getJson($this->url($this->tenantPath($this->b, 'calls/capability')))->assertStatus(404);
    }
}
