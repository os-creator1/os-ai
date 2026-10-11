<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Security\Concerns\PreparesSecurityTenants;
use Tests\TestCase;

/**
 * F3: a sub-account (team member) could log in as the account owner via GET /account/{parent uid}/impersonate.
 *
 * The check was `authUser->parent_id === target->id`, i.e. the CHILD may become the PARENT. Identity-based ownership
 * checks (Auth::id(), the V1 workspace/business authority) then run as the owner while only the Gate permission set
 * stays the child's - so a restricted team member reached the owner's data and owner-level V1 surfaces.
 * The switch is now off unless an operator explicitly enables account.allow_parent_switch.
 */
class SubAccountParentSwitchTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use PreparesSecurityTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPlaceholderSuperAdmin();
        $this->ensureRequiredAppConfigRowsExist();
    }

    /** @return array{0: User, 1: User} [parent, child] */
    private function family(): array
    {
        $parent = $this->createCustomer()->user;
        $child = $this->createCustomer()->user;
        $child->forceFill(['parent_id' => $parent->id, 'email_verified_at' => now()])->save();
        $parent->forceFill(['email_verified_at' => now()])->save();

        return [$parent, $child];
    }

    private function actAs(User $u): void
    {
        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($u);
    }

    public function test_a_sub_account_cannot_become_its_parent_by_default(): void
    {
        [$parent, $child] = $this->family();
        $this->actAs($child);

        $this->get(route('user.account.login_as', $parent->uid));

        $this->assertAuthenticatedAs($child);
        $this->assertNull(session('parent_user_id'));
    }

    public function test_an_unrelated_user_can_never_switch_to_someone_elses_account(): void
    {
        config(['account.allow_parent_switch' => true]);
        [$parent] = $this->family();
        $stranger = $this->createCustomer()->user;
        $stranger->forceFill(['email_verified_at' => now()])->save();
        $this->actAs($stranger);

        $this->get(route('user.account.login_as', $parent->uid));

        $this->assertAuthenticatedAs($stranger);
    }

    public function test_a_sibling_sub_account_cannot_switch_to_the_other_sub_account_or_parent(): void
    {
        config(['account.allow_parent_switch' => true]);
        [$parent, $child] = $this->family();
        $sibling = $this->createCustomer()->user;
        $sibling->forceFill(['parent_id' => $parent->id, 'email_verified_at' => now()])->save();
        $this->actAs($sibling);

        $this->get(route('user.account.login_as', $child->uid));

        $this->assertAuthenticatedAs($sibling);
    }

    public function test_an_inactive_parent_or_child_cannot_be_switched_into_even_when_enabled(): void
    {
        config(['account.allow_parent_switch' => true]);
        [$parent, $child] = $this->family();
        $parent->forceFill(['status' => false])->save();
        $this->actAs($child);

        $this->get(route('user.account.login_as', $parent->uid));

        $this->assertAuthenticatedAs($child);
    }

    public function test_an_administrator_parent_can_never_be_switched_into(): void
    {
        config(['account.allow_parent_switch' => true]);
        [$parent, $child] = $this->family();
        $parent->forceFill(['is_admin' => true])->save();
        $this->actAs($child);

        $this->get(route('user.account.login_as', $parent->uid));

        $this->assertAuthenticatedAs($child);
    }

    public function test_the_legacy_switch_still_works_only_when_an_operator_enables_it(): void
    {
        config(['account.allow_parent_switch' => true]);
        [$parent, $child] = $this->family();
        $this->actAs($child);

        $this->get(route('user.account.login_as', $parent->uid));

        $this->assertAuthenticatedAs($parent);
        $this->assertSame($child->id, session('parent_user_id'));   // the way back to the child is recorded
    }

    public function test_the_dashboard_hides_the_switch_when_it_is_disabled(): void
    {
        [, $child] = $this->family();
        $frame = \App\Library\Navigation\CustomerFrame::cases()[0];
        $source = \App\Library\Navigation\ContextSource::cases()[0];
        $context = new \App\Library\Navigation\CustomerContext($child->id, $frame, [], null, null, $source, null, false);

        $this->assertNull(app(\App\Library\Dashboard\ParentAccountSwitch::class)->for($child, $context));

        config(['account.allow_parent_switch' => true]);
        $this->assertNotNull(app(\App\Library\Dashboard\ParentAccountSwitch::class)->for($child, $context));
    }
}
