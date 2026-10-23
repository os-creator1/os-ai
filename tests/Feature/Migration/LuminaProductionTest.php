<?php

namespace Tests\Feature\Migration;

use App\Library\Migration\Lumina\LuminaAuraProvisioner;
use App\Library\Migration\Lumina\LuminaProductionGuard;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class LuminaProductionTest extends TestCase
{
    use RefreshDatabase;

    private function guard(array $state = []): LuminaProductionGuard
    {
        return new class($state) extends LuminaProductionGuard {
            public function __construct(private array $state)
            {
            }

            public function inspect(): array
            {
                return $this->state + ['app_env' => 'production', 'app_host' => 'getmotiongrove.com', 'db_name' => 'motiongrove_app', 'db_host' => 'db.internal'];
            }
        };
    }

    private function owner(): User
    {
        User::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'admin@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);

        return app(\App\Repositories\Contracts\UserRepository::class)->store(['first_name' => 'Real', 'last_name' => 'Owner', 'email' => 'real-owner@example.test', 'password' => 'a-long-password-123', 'status' => true, 'phone' => '+13035550000', 'is_customer' => true], true);
    }

    public function test_the_guard_refuses_every_unsafe_target(): void
    {
        $this->owner();
        $ok = fn (LuminaProductionGuard $g, array $over = []) => $g->assertMayProvision(...array_merge(['motiongrove_app', 'backup-2026-11-14.sql.gz', LuminaProductionGuard::PHRASE, 'real-owner@example.test', true], $over));

        $this->assertSame('motiongrove_app', $ok($this->guard())['db_name']);

        foreach ([
            'staging host' => [$this->guard(['app_host' => 'staging.getmotiongrove.com']), []],
            'not production env' => [$this->guard(['app_env' => 'local']), []],
            'test-shaped database' => [$this->guard(['db_name' => 'motiongrove_staging']), ['motiongrove_staging']],
            'wrong confirm-database' => [$this->guard(), ['other_db']],
            'no backup reference' => [$this->guard(), [0 => 'motiongrove_app', 1 => '  ']],
            'wrong phrase' => [$this->guard(), [0 => 'motiongrove_app', 1 => 'b', 2 => 'yes']],
            'owner missing' => [$this->guard(), [0 => 'motiongrove_app', 1 => 'b', 2 => LuminaProductionGuard::PHRASE, 3 => 'nobody@example.test']],
            'not opted in' => [$this->guard(), [4 => false]],
        ] as $name => [$guard, $over]) {
            try {
                $ok($guard, $over);
                $this->fail("Guard accepted: {$name}");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        // The real test database name is refused for being test-shaped, whatever else is right.
        $this->expectException(RuntimeException::class);
        (new LuminaProductionGuard())->assertMayProvision((string) DB::connection()->getDatabaseName(), 'b', LuminaProductionGuard::PHRASE, 'real-owner@example.test', true);
    }

    public function test_adopting_the_real_owner_adds_lumina_without_touching_the_login_or_profile(): void
    {
        $owner = $this->owner();
        $before = [User::query()->find($owner->id)->getAttributes(), Customer::query()->where('user_id', $owner->id)->first()->getAttributes()];
        $users = User::query()->count();

        $result = app(LuminaAuraProvisioner::class)->provision('real-owner@example.test', null, [], adoptExistingOwner: true, complimentaryPlan: false);

        $this->assertSame($before[0], User::query()->find($owner->id)->getAttributes(), 'Owner login untouched (password, timezone, verification).');
        $this->assertSame($before[1], Customer::query()->where('user_id', $owner->id)->first()->getAttributes(), 'Owner customer profile untouched.');
        $this->assertSame($users, User::query()->count(), 'No user was created.');
        $this->assertSame((int) $owner->id, (int) $result['context']->workspace->owner_user_id);
        $this->assertSame(0, DB::table('workspace_plan_assignments')->where('workspace_id', $result['context']->workspace->id)->count(), '--no-plan assigns nothing.');
        $this->assertSame([], $result['violations']);

        // Re-running is a no-op (no duplicate account).
        app(LuminaAuraProvisioner::class)->provision('real-owner@example.test', null, [], adoptExistingOwner: true, complimentaryPlan: false);
        $this->assertSame(1, DB::table('businesses')->where('name', 'Lumina Aura Events')->count());
        $this->assertSame(1, DB::table('workspaces')->where('name', 'Lumina Aura Events')->count());
    }
}
