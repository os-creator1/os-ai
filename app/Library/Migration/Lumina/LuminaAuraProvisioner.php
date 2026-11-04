<?php

namespace App\Library\Migration\Lumina;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Business\BusinessLocationManager;
use App\Library\Demo\BrightFrame\DemoContext;
use App\Library\Demo\BrightFrame\DemoSafetyAudit;
use App\Library\Entitlement\EntitlementManager;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\PhotoBoothBusinessPackV3;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\UserRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Provisions the Lumina Aura Events Business in an ISOLATED staging database:
 * login, Workspace, Business, primary Location, complimentary plan, staff access and
 * the Photo Booth Business Pack (installed through the real adapters; workflows land
 * as drafts). Everything is found-or-created by a stable key, so a re-run changes
 * nothing it already made. It sends no invitation, mail or SMS.
 *
 * Business facts come ONLY from the verified GoHighLevel source: name, address,
 * timezone, e-mail, website, service areas, and the phone that is the account's LC
 * Phone / "Phone" custom value (+1 720-502-8306). The GHL profile's different phone
 * (+1 720-917-8741) is deliberately not used and is reported as a discrepancy.
 */
final class LuminaAuraProvisioner
{
    public const WORKSPACE_NAME = 'Lumina Aura Events';

    public const BUSINESS_NAME = 'Lumina Aura Events';

    public const TIMEZONE = 'America/Denver';

    public const PHONE = '+17205028306';

    public const PHONE_DISCREPANCY = '+17209178741';

    public const EMAIL = 'luminauraevents@gmail.com';

    public const WEBSITE = 'https://luminaauraevents.com/';

    public const PACK_TYPES = ['crm_tag_set', 'crm_custom_field', 'crm_pipeline', 'form', 'automation_workflow'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly WorkspaceManager $workspaces,
        private readonly BusinessRepository $businesses,
        private readonly EntitlementManager $entitlements,
        private readonly BusinessLocationManager $locations,
        private readonly BlueprintComponentAdapterRegistry $adapters,
        private readonly DemoSafetyAudit $audit,
    ) {
    }

    /** @return array{context: DemoContext, report: array<string, int|string>, password_set: bool, violations: list<string>} */
    /**
     * @param  list<array{first: string, last: string, email: string}>  $people  GHL users from the snapshot: the one matching
     *         $ownerEmail names the owner; every other becomes least-privilege staff.
     */
    public function provision(string $ownerEmail, ?string $ownerPassword, array $people = [], bool $adoptExistingOwner = false, bool $complimentaryPlan = true): array
    {
        $adminId = (int) User::query()->where('is_admin', true)->orderBy('id')->value('id');
        if ($adminId < 1) {
            throw new RuntimeException('No platform administrator exists to record the complimentary plan against.');
        }

        $passwordSet = false;
        $owner = User::query()->where('email', $ownerEmail)->first();
        if ($owner === null) {
            if ($ownerPassword === null || $ownerPassword === '') {
                throw new RuntimeException('A password is required to create the owner login.');
            }
            $named = collect($people)->first(fn ($p) => strtolower($p['email']) === strtolower($ownerEmail));
            $owner = $this->users->store([
                'first_name' => $named['first'] ?? 'Owner', 'last_name' => $named['last'] ?? '', 'email' => $ownerEmail,
                'password' => $ownerPassword, 'status' => true, 'phone' => self::PHONE, 'is_customer' => true,
            ], true);
            $passwordSet = true;
        } elseif (! $adoptExistingOwner && ! Workspace::query()->where('owner_user_id', $owner->id)->where('name', self::WORKSPACE_NAME)->exists()) {
            throw new RuntimeException("Refusing: [{$ownerEmail}] already exists and does not own the Lumina Aura Workspace.");
        }

        $customer = Customer::query()->where('user_id', $owner->id)->firstOrFail();

        // An ADOPTED existing owner (the real production login) is never modified: not their profile, timezone, verification
        // state, company, phone or password. Lumina Aura's own facts live on its Business and Location.
        if (! $adoptExistingOwner) {
            $owner->forceFill(['email_verified_at' => $owner->email_verified_at ?? now(), 'status' => true, 'timezone' => self::TIMEZONE])->save();
            $customer->forceFill(['company' => self::BUSINESS_NAME, 'website' => self::WEBSITE, 'city' => 'Thornton', 'state' => 'CO', 'country' => 'US', 'phone' => self::PHONE])->save();
        }

        $workspace = Workspace::query()->where('owner_user_id', $owner->id)->where('name', self::WORKSPACE_NAME)->first()
            ?? $this->workspaces->createWorkspace((int) $owner->id, self::WORKSPACE_NAME);

        $business = Business::query()->where('workspace_id', $workspace->id)->first()
            ?? $this->businesses->createForCustomerInWorkspace($customer, $workspace, [
                'name' => self::BUSINESS_NAME, 'industry' => 'photo_booth_service', 'country_code' => 'US',
                'timezone' => self::TIMEZONE, 'currency_code' => 'USD',
            ]);

        $business->forceFill([
            'description' => '360° video booth rental for weddings, corporate events, birthdays and brand activations across the Denver metro and Colorado Springs.',
            'email' => self::EMAIL, 'phone' => self::PHONE, 'website_url' => self::WEBSITE,
            'status' => BusinessStatus::Active->value, 'activated_at' => $business->activated_at ?? now(),
        ])->save();

        $location = $this->locations->upsertPrimaryLocation($business, [
            'name' => 'Thornton', 'service_mode' => 'service_area',
            'service_area_cities' => ['Denver Metro', 'Colorado Springs'],
            'address_line_1' => '10050 Quivas Street', 'city' => 'Thornton', 'region' => 'CO', 'postal_code' => '80260',
            'country_code' => 'US', 'public_address' => false,
        ]);

        if ($complimentaryPlan && ! DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->exists()) {
            $this->entitlements->assignFirstPlan($workspace, WorkspacePlanTier::Growth, $adminId, 'GHL migration pilot (staging): complimentary Growth plan. No billing.', true, 0);
        }

        $staff = $this->staff($workspace, $owner, array_values(array_filter($people, fn ($p) => strtolower($p['email']) !== strtolower($ownerEmail))));
        $installed = $this->installPack($business, (int) $owner->id);

        $context = new DemoContext($owner->fresh(), $customer->fresh(), $workspace->fresh(), $business->fresh(), $location->fresh(), $adminId, CarbonImmutable::now());
        $violations = $this->audit->violations($context);
        if ($adoptExistingOwner) {
            // These two checks are scoped to the OWNER's user id, not to the new Business. An existing production owner may
            // legitimately have a sending server or calendar connection for their other work; nothing here uses them
            // (Lumina's own mailbox/number/token checks stay in force).
            unset($violations['sending_servers'], $violations['external_calendar_connections']);
        }

        return [
            'context' => $context,
            'report' => ['staff_members' => $staff, 'pack_components_installed' => $installed, 'pack_version' => PhotoBoothBusinessPackV3::VERSION],
            'password_set' => $passwordSet,
            'violations' => $violations,
        ];
    }

    /** @param list<array{first: string, last: string, email: string}> $people */
    private function staff(Workspace $workspace, User $owner, array $people): int
    {
        $count = 0;
        foreach ($people as $person) {
            $user = User::query()->where('email', $person['email'])->first()
                ?? $this->users->store([
                    'first_name' => $person['first'], 'last_name' => $person['last'], 'email' => $person['email'],
                    // Unusable random password; no invitation or reset mail is sent. Access is granted by the owner later.
                    'password' => Str::password(32), 'status' => true, 'is_customer' => true, 'phone' => '',
                ], true);

            $this->workspaces->addMember((int) $owner->id, $workspace, (int) $user->id, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, LocationAccessScope::All);
            $count++;
        }

        return $count;
    }

    private function installPack(Business $business, int $actorUserId): int
    {
        $installed = 0;
        foreach (PhotoBoothBusinessPackV3::components() as $component) {
            if (! in_array($component['type'], self::PACK_TYPES, true)) {
                continue;
            }
            if (DB::table('external_import_records')->where('business_id', $business->id)->where('source_system', 'motiongrove_pack')->where('source_id', $component['key'])->exists()) {
                continue;
            }
            // Install and ledger together: a crash can never leave an installed component the ledger doesn't know.
            DB::transaction(function () use ($component, $business, $actorUserId): void {
                $ref = $this->adapters->adapterFor($component['type'])->install($business, $component['payload'], $actorUserId);
                DB::table('external_import_records')->insert([
                    'business_id' => $business->id, 'source_system' => 'motiongrove_pack', 'source_type' => $component['type'],
                    'source_id' => $component['key'], 'local_type' => (string) $ref->recordType, 'local_id' => (int) $ref->recordId,
                    'meta' => json_encode(['pack' => PhotoBoothBusinessPackV3::VERSION]), 'imported_at' => now(),
                ]);
            });
            $installed++;
        }

        return $installed;
    }
}
