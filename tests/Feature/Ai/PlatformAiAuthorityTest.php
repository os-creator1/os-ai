<?php

namespace Tests\Feature\Ai;

use App\Library\Ai\PlatformAiAuthority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Implementation Contract 19 §5.7a C, §6.7, R-28, sub-slice 19.H0 —
 * `PlatformAiAuthority` in isolation: a fresh read of `users.is_admin`,
 * never a cached verdict, never the permission-string path.
 */
class PlatformAiAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_user_is_authorized(): void
    {
        $admin = $this->makeUser(isAdmin: true);

        $this->assertTrue(app(PlatformAiAuthority::class)->authorize((int) $admin->id));
    }

    public function test_a_non_admin_user_is_not_authorized(): void
    {
        $user = $this->makeUser(isAdmin: false);

        $this->assertFalse(app(PlatformAiAuthority::class)->authorize((int) $user->id));
    }

    public function test_a_nonexistent_user_id_is_not_authorized(): void
    {
        $this->assertFalse(app(PlatformAiAuthority::class)->authorize(999_999_999));
    }

    public function test_zero_is_not_authorized(): void
    {
        // users.id === 1 (and any other id) is checked by a real row read —
        // never a special-cased bypass id, unlike the permission-string
        // path this class deliberately does not use.
        $this->assertFalse(app(PlatformAiAuthority::class)->authorize(0));
    }

    /**
     * The whole point of "re-read, never cache": the SAME authority
     * instance, asked twice for the same id, must answer differently once
     * the underlying row changes. Nothing about a prior verdict may be
     * remembered.
     */
    public function test_it_always_reads_fresh_never_a_cached_verdict(): void
    {
        $admin = $this->makeUser(isAdmin: true);
        $authority = app(PlatformAiAuthority::class);

        $this->assertTrue($authority->authorize((int) $admin->id));

        $admin->forceFill(['is_admin' => false])->save();

        $this->assertFalse($authority->authorize((int) $admin->id));
    }

    public function test_a_deleted_user_is_not_authorized(): void
    {
        $admin = $this->makeUser(isAdmin: true);
        $id = (int) $admin->id;
        $admin->delete();

        $this->assertFalse(app(PlatformAiAuthority::class)->authorize($id));
    }

    private function makeUser(bool $isAdmin): User
    {
        return User::create([
            'first_name' => 'Platform',
            'last_name' => 'Fixture',
            'email' => 'platform-authority-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => $isAdmin,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }
}
