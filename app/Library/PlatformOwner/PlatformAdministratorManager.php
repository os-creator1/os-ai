<?php

namespace App\Library\PlatformOwner;

use App\Models\User;
use App\Repositories\Contracts\RoleRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Platform Owner V1 final — administrator accounts: invite, change roles,
 * activate/deactivate. No password is ever typed, shown or stored by this
 * flow: an invited administrator is created with a random unusable password
 * and receives the standard emailed "set your password" link. Every change is
 * audited. Roles are validated against RoleRepository::getAllowedRoles(), the
 * same allow-list the legacy screen used.
 */
class PlatformAdministratorManager
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly PlatformOwnerAuthority $authority,
        private readonly PlatformAdminAuditLog $audit,
    ) {
    }

    /**
     * @param  array{first_name: string, last_name?: ?string, email: string, roles: array<int, int|string>}  $data
     */
    public function invite(int $actorId, array $data): User
    {
        $this->authority->assertAdministrator($actorId);
        $roleIds = $this->validRoleIds($data['roles'] ?? []);

        $admin = DB::transaction(function () use ($data, $roleIds) {
            $admin = User::create([
                'uid' => (string) Str::uuid(),
                'first_name' => trim($data['first_name']),
                'last_name' => trim((string) ($data['last_name'] ?? '')),
                'email' => strtolower(trim($data['email'])),
                'password' => Hash::make(Str::random(48)),
                'is_admin' => true,
                'is_customer' => false,
                'status' => true,
                'email_verified_at' => now(),
                'active_portal' => 'admin',
                'locale' => config('app.locale'),
                'timezone' => config('app.timezone'),
            ]);
            $admin->roles()->sync($roleIds);

            return $admin;
        });

        $this->sendInvitation($admin);
        $this->audit->record($actorId, 'administrator.invited', 'administrator', $admin->uid, 'Invited ' . $admin->email . ' as an administrator', null, ['role_ids' => $roleIds]);

        return $admin;
    }

    public function resendInvitation(int $actorId, User $admin): void
    {
        $this->authority->assertAdministrator($actorId);
        $this->assertAdministratorTarget($admin);
        $this->sendInvitation($admin);
        $this->audit->record($actorId, 'administrator.invitation_resent', 'administrator', $admin->uid, 'Resent the invitation to ' . $admin->email);
    }

    /**
     * @param  array{first_name?: string, last_name?: ?string, roles: array<int, int|string>}  $data
     */
    public function updateProfileAndRoles(int $actorId, User $admin, array $data): User
    {
        $this->authority->assertAdministrator($actorId);
        $this->assertAdministratorTarget($admin);

        $roleIds = $this->validRoleIds($data['roles'] ?? []);

        if ($admin->id === $actorId && $roleIds === []) {
            throw ValidationException::withMessages(['roles' => __('You cannot remove every role from your own account.')]);
        }

        $before = $admin->roles()->pluck('roles.id')->map(fn ($i) => (int) $i)->sort()->values()->all();

        DB::transaction(function () use ($admin, $data, $roleIds) {
            if (isset($data['first_name']) && trim($data['first_name']) !== '') {
                $admin->forceFill(['first_name' => trim($data['first_name']), 'last_name' => trim((string) ($data['last_name'] ?? ''))])->save();
            }

            $admin->roles()->sync($roleIds);
        });

        $after = collect($roleIds)->sort()->values()->all();

        $this->audit->record($actorId, 'administrator.updated', 'administrator', $admin->uid, 'Updated administrator ' . $admin->email . ($before !== $after ? ' (roles changed)' : ''), null, ['roles_before' => $before, 'roles_after' => $after]);

        return $admin;
    }

    public function setActive(int $actorId, User $admin, bool $active, string $reason): void
    {
        $this->authority->assertAdministrator($actorId);
        $this->assertAdministratorTarget($admin);

        if (! $active && ($admin->id === $actorId || $admin->is_super_admin)) {
            throw new RuntimeException(__('This administrator account cannot be deactivated.'));
        }

        $admin->forceFill(['status' => $active] + ($active ? [] : ['password_changed_at' => now()]))->save();

        $this->audit->record($actorId, $active ? 'administrator.activated' : 'administrator.deactivated', 'administrator', $admin->uid, ($active ? 'Activated ' : 'Deactivated ') . $admin->email, $reason);
    }

    /** @return list<int> */
    private function validRoleIds(array $ids): array
    {
        $allowed = collect($this->roles->getAllowedRoles())->pluck('id')->map(fn ($i) => (int) $i)->all();
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (array_diff($ids, $allowed) !== []) {
            throw ValidationException::withMessages(['roles' => __('Choose from the roles listed.')]);
        }

        return $ids;
    }

    private function assertAdministratorTarget(User $admin): void
    {
        if (! $admin->is_admin) {
            throw new RuntimeException(__('That account is not an administrator.'));
        }
    }

    private function sendInvitation(User $admin): void
    {
        $status = Password::broker()->sendResetLink(['email' => $admin->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw new RuntimeException(__('The invitation email could not be sent right now. Use Resend invitation to try again.'));
        }
    }
}
