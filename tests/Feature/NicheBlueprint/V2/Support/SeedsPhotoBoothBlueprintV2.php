<?php

namespace Tests\Feature\NicheBlueprint\V2\Support;

use App\Models\NicheBlueprint;
use App\Models\User;
use Database\Seeders\WebsiteTemplateSeeder;
use Tests\Feature\NicheBlueprint\Support\CreatesBlueprintInstallationFixtures;

/**
 * Seeds the real Photo Booth Blueprint v1 + v2 through the shipped commands,
 * so the V2 tests run against exactly what an installation ships.
 */
trait SeedsPhotoBoothBlueprintV2
{
    use CreatesBlueprintInstallationFixtures;

    protected function seedPhotoBoothBlueprintV2(): NicheBlueprint
    {
        $this->ensureRequiredAppConfigRowsExist();
        $adminId = $this->platformAdminId();
        User::query()->whereKey($adminId)->update(['is_admin' => true]);

        (new WebsiteTemplateSeeder())->run();

        $this->artisan('blueprint:seed-photo-booth', ['--actor' => $adminId])->assertExitCode(0);
        $code = \Illuminate\Support\Facades\Artisan::call('documents:seed-photo-booth-templates', ['--actor' => $adminId]);
        $this->assertSame(0, $code, \Illuminate\Support\Facades\Artisan::output());
        $this->artisan('blueprint:seed-photo-booth-v2', ['--actor' => $adminId])->assertExitCode(0);

        return NicheBlueprint::query()->where('key', 'photo_booth')->firstOrFail();
    }
}
