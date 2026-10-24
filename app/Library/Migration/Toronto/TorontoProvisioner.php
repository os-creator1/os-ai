<?php

namespace App\Library\Migration\Toronto;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Business\BusinessLocationManager;
use App\Library\Demo\BrightFrame\DemoContext;
use App\Library\Demo\BrightFrame\DemoSafetyAudit;
use App\Library\Entitlement\EntitlementManager;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\UserRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Finds or creates the Toronto Photo Booth customer: login, Workspace, Business, primary Location and a complimentary
 * Growth plan (no Stripe object). Everything is keyed by a stable name so a re-run changes nothing it already made, and
 * it never touches another customer, the platform administrator, a phone number, a provider or a domain.
 *
 * It deliberately does NOT install the Photo Booth Business Pack: production has no consolidated pack version yet and V3
 * has overlapping components (see docs/migrations/toronto-photo-booth/README.md). The GHL pipeline is imported instead.
 *
 * Business facts come ONLY from the verified GoHighLevel location 9Yjeolqf4nEkFxyPmqJY.
 */
final class TorontoProvisioner
{
    public const SOURCE_LOCATION = '9Yjeolqf4nEkFxyPmqJY';

    public const WORKSPACE_NAME = 'Toronto Photo Booth';

    public const BUSINESS_NAME = 'The Photo Booth Company of Toronto';

    public const TIMEZONE = 'America/Toronto';

    public const PHONE = '+16476547286';

    public const EMAIL = 'smiles@pbto.ca';

    public const WEBSITE = 'https://emphotobooth.site';

    public const SERVICE_AREAS = ['Toronto', 'North York', 'Mississauga', 'Vaughan', 'Scarborough', 'Etobicoke', 'Markham'];

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

    /**
     * @param  string  $ownerEmail  an agency-controlled login; never the customer's address, so no invitation can reach them
     * @param  list<array{name: string, stages: list<string>}>  $pipelines  GHL pipelines to recreate (names preserved)
     * @return array{context: DemoContext, report: array<string, int|string|bool>, password: ?string, violations: array}
     */
    public function provision(string $ownerEmail, array $pipelines = []): array
    {
        $adminId = (int) User::query()->where('is_admin', true)->orderBy('id')->value('id');
        if ($adminId < 1) {
            throw new RuntimeException('No platform administrator exists to record the complimentary plan against.');
        }

        $password = null;
        $owner = User::query()->where('email', $ownerEmail)->first();
        if ($owner === null) {
            $password = bin2hex(random_bytes(12)).'Aa1!';
            $owner = $this->users->store([
                'first_name' => 'Toronto', 'last_name' => 'Photo Booth', 'email' => $ownerEmail,
                'password' => $password, 'status' => true, 'phone' => '', 'is_customer' => true,
            ], true);
        } elseif ((bool) $owner->is_admin || ! Workspace::query()->where('owner_user_id', $owner->id)->where('name', self::WORKSPACE_NAME)->exists()) {
            // Never adopt an unrelated login (and never the platform administrator) as this customer's owner.
            throw new RuntimeException("Refusing: [{$ownerEmail}] already exists and is not the owner of the Toronto Photo Booth Workspace.");
        }

        $customer = Customer::query()->where('user_id', $owner->id)->firstOrFail();
        $owner->forceFill(['email_verified_at' => $owner->email_verified_at ?? now(), 'status' => true, 'timezone' => self::TIMEZONE])->save();

        $workspace = Workspace::query()->where('owner_user_id', $owner->id)->where('name', self::WORKSPACE_NAME)->first()
            ?? $this->workspaces->createWorkspace((int) $owner->id, self::WORKSPACE_NAME);

        $business = Business::query()->where('workspace_id', $workspace->id)->first()
            ?? $this->businesses->createForCustomerInWorkspace($customer, $workspace, [
                'name' => self::BUSINESS_NAME, 'industry' => 'photo_booth_service', 'country_code' => 'CA',
                'timezone' => self::TIMEZONE, 'currency_code' => 'CAD',
            ]);

        $business->forceFill([
            'description' => 'Luxury photo booth rental for weddings, corporate events, birthdays and brand activations across Toronto and the GTA.',
            'email' => self::EMAIL, 'phone' => self::PHONE, 'website_url' => self::WEBSITE,
            'status' => BusinessStatus::Active->value, 'activated_at' => $business->activated_at ?? now(),
        ])->save();

        // The GHL profile has no street address and a placeholder postal code (11111): neither is invented here.
        $location = $this->locations->upsertPrimaryLocation($business, [
            'name' => 'Toronto', 'service_mode' => 'service_area', 'service_area_cities' => self::SERVICE_AREAS,
            'city' => 'Toronto', 'region' => 'ON', 'country_code' => 'CA', 'public_address' => false,
        ]);

        if (! DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->exists()) {
            $this->entitlements->assignFirstPlan($workspace, WorkspacePlanTier::Growth, $adminId, 'GHL migration: complimentary Growth plan. No billing.', true, 0);
        }

        $created = $this->pipelines($business, $pipelines, (int) $owner->id);

        $context = new DemoContext($owner->fresh(), $customer->fresh(), $workspace->fresh(), $business->fresh(), $location->fresh(), $adminId, CarbonImmutable::now());

        return [
            'context' => $context,
            'report' => ['pipelines_created' => $created, 'account_created' => $password !== null],
            'password' => $password,
            'violations' => $this->audit->violations($context),
        ];
    }

    /** @param list<array{name: string, stages: list<string>}> $pipelines */
    private function pipelines(Business $business, array $pipelines, int $actorUserId): int
    {
        $created = 0;
        foreach ($pipelines as $pipeline) {
            $key = 'ghl_pipeline:'.$pipeline['name'];
            if (DB::table('external_import_records')->where('business_id', $business->id)->where('source_system', 'motiongrove_pack')->where('source_id', $key)->exists()) {
                continue;
            }
            $stages = [];
            foreach (array_values($pipeline['stages']) as $i => $name) {
                $stages[] = ['name' => $name, 'semantic_key' => $i === 0 ? 'new_inquiry' : null];
            }
            DB::transaction(function () use ($pipeline, $stages, $business, $actorUserId, $key): void {
                $ref = $this->adapters->adapterFor('crm_pipeline')->install($business, [
                    'template_key' => 'ghl_import', 'template_version' => 1, 'pipeline_key' => 'ghl_'.substr(md5($pipeline['name']), 0, 10),
                    'name' => $pipeline['name'], 'stages' => $stages,
                ], $actorUserId);
                DB::table('external_import_records')->insert([
                    'business_id' => $business->id, 'source_system' => 'motiongrove_pack', 'source_type' => 'crm_pipeline',
                    'source_id' => $key, 'local_type' => (string) $ref->recordType, 'local_id' => (int) $ref->recordId,
                    'meta' => json_encode(['origin' => 'ghl_import']), 'imported_at' => now(),
                ]);
            });
            $created++;
        }

        return $created;
    }
}
