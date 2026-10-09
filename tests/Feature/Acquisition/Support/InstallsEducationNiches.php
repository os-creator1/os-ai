<?php

namespace Tests\Feature\Acquisition\Support;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\NicheBlueprint\Support\CreatesBlueprintInstallationFixtures;

/**
 * Seeds the two real niche Blueprints through the shipped command and installs
 * one into a fresh Business, so every acceptance test runs against exactly what
 * a platform ships.
 */
trait InstallsEducationNiches
{
    use CreatesBlueprintInstallationFixtures;

    protected function seedNiche(string $niche): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $adminId = $this->platformAdminId();
        User::query()->whereKey($adminId)->update(['is_admin' => true]);

        $this->artisan('blueprint:seed-niche', ['niche' => $niche, '--actor' => $adminId])->assertExitCode(0);
    }

    /**
     * A fresh Business of the given industry that has received the niche
     * Blueprint. The fixture Business is created before the industry is set so
     * the plan-assignment listener cannot install anything early; the explicit
     * installer run is the one under test.
     *
     * @return array{0: \App\Models\Customer, 1: Business, 2: \App\Models\Workspace}
     */
    protected function businessInstalledFrom(string $industry, WorkspacePlanTier $tier = WorkspacePlanTier::Growth): array
    {
        [$customer, $business, $workspace] = $this->tenant($tier);

        DB::table('businesses')->where('id', $business->id)->update(['industry' => $industry, 'currency_code' => 'EUR']);
        $business = $business->fresh();

        app(NicheBlueprintInstaller::class)->installForBusiness($business);

        return [$customer, $business->fresh(), $workspace];
    }
}
