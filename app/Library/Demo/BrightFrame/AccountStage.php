<?php

namespace App\Library\Demo\BrightFrame;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Business\BusinessLocationManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The login, Workspace, Business, primary Location and the (complimentary,
 * legitimately assigned) Growth plan. Everything is found-or-created by a
 * stable key, so a re-run changes nothing it already made.
 *
 * It refuses to adopt a user it did not create: an existing user with the
 * login email must already own the demo Workspace, otherwise a real customer
 * could have their password reset by this command.
 */
final class AccountStage
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WorkspaceManager $workspaces,
        private readonly BusinessRepository $businesses,
        private readonly EntitlementManager $entitlements,
        private readonly BusinessLocationManager $locations,
    ) {
    }

    /**
     * @return array{0: User, 1: Customer, 2: Workspace, 3: Business, 4: BusinessLocation, 5: int, 6: bool} last = password was (re)set
     */
    public function run(string $loginEmail, ?string $password, bool $rotatePassword): array
    {
        $adminId = (int) User::query()->where('is_admin', true)->orderBy('id')->value('id');

        if ($adminId < 1) {
            throw new RuntimeException('No platform administrator exists to record the complimentary plan against.');
        }

        $user = User::query()->where('email', $loginEmail)->first();
        $passwordSet = false;

        if ($user === null) {
            if ($password === null || $password === '') {
                throw new RuntimeException('A password is required to create the demo login.');
            }

            $user = $this->users->store([
                'first_name' => 'Alex',
                'last_name' => 'Rivera',
                'email' => $loginEmail,
                'password' => $password,
                'status' => true,
                'phone' => BrightFrameData::PHONE_E164,
                'is_customer' => true,
            ], true);
            $passwordSet = true;
        } else {
            $this->assertOwnsDemoWorkspace($user);

            if ($rotatePassword) {
                if ($password === null || $password === '') {
                    throw new RuntimeException('--rotate-password needs a new password.');
                }

                $user->forceFill(['password' => Hash::make($password), 'password_changed_at' => now(), 'remember_token' => null])->save();
                $passwordSet = true;
            }
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'status' => true, 'timezone' => BrightFrameData::TIMEZONE])->save();

        $customer = Customer::query()->where('user_id', $user->id)->firstOrFail();
        $customer->forceFill([
            'company' => BrightFrameData::BUSINESS_NAME,
            'website' => BrightFrameData::WEBSITE,
            'city' => BrightFrameData::CITY,
            'state' => BrightFrameData::REGION,
            'country' => 'US',
            'phone' => BrightFrameData::PHONE_E164,
        ])->save();

        $workspace = Workspace::query()
            ->where('owner_user_id', $user->id)
            ->where('name', BrightFrameData::WORKSPACE_NAME)
            ->first() ?? $this->workspaces->createWorkspace((int) $user->id, BrightFrameData::WORKSPACE_NAME);

        $business = Business::query()->where('workspace_id', $workspace->id)->first()
            ?? $this->businesses->createForCustomerInWorkspace($customer, $workspace, [
                'name' => BrightFrameData::BUSINESS_NAME,
                'industry' => 'photo_booth_service',
                'country_code' => 'US',
                'timezone' => BrightFrameData::TIMEZONE,
                'currency_code' => 'USD',
            ]);

        $business->forceFill([
            'description' => BrightFrameData::ABOUT,
            'email' => BrightFrameData::EMAIL,
            'phone' => BrightFrameData::PHONE,
            'website_url' => BrightFrameData::WEBSITE,
            'canonical_domain' => 'brightframe-photobooths.example',
            'status' => BusinessStatus::Active->value,
            'activated_at' => $business->activated_at ?? now(),
        ])->save();

        // The real primary-location path: finds the existing primary location
        // and edits it, or creates it only when the Business has none.
        $location = $this->locations->upsertPrimaryLocation($business, [
            'name' => 'Charlotte Studio',
            'service_mode' => 'storefront',
            'address_line_1' => '212 Mill Street',
            'city' => BrightFrameData::CITY,
            'region' => BrightFrameData::REGION,
            'postal_code' => '28202',
            'country_code' => 'US',
            'public_address' => true,
        ]);

        // A complimentary Growth plan, assigned through the real entitlement
        // manager and recorded against a real platform administrator. No
        // Stripe subscription or payment instrument is created or attached.
        if (! DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->exists()) {
            $this->entitlements->assignFirstPlan(
                $workspace,
                WorkspacePlanTier::Growth,
                $adminId,
                'Sales demo account: complimentary Growth plan. Fictional data only; no billing.',
                true,
                0,
            );
        }

        return [$user->fresh(), $customer->fresh(), $workspace->fresh(), $business->fresh(), $location->fresh(), $adminId, $passwordSet];
    }

    private function assertOwnsDemoWorkspace(User $user): void
    {
        $owns = Workspace::query()
            ->where('owner_user_id', $user->id)
            ->where('name', BrightFrameData::WORKSPACE_NAME)
            ->exists();

        if (! $owns) {
            throw new RuntimeException("Refusing: [{$user->email}] already exists and is not the BrightFrame demo owner. Pick another --login-email.");
        }
    }
}
