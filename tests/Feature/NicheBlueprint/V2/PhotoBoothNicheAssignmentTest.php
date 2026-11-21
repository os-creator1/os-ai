<?php

namespace Tests\Feature\NicheBlueprint\V2;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\NicheBlueprint\PhotoBoothBlueprintProvisioner;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\BusinessKnowledgeProfile;
use App\Models\BusinessLocation;
use App\Models\BusinessVertical;
use App\Models\QuestionnaireDefinition;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\Workspace;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV2Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\NicheBlueprint\V2\Support\SeedsPhotoBoothBlueprintV2;
use Tests\TestCase;

/**
 * Photography clients (e.g. Kaleb Hill Photography, Greenbelt Photography) store the general industry
 * `photographer`. They can be given the Photo Booth website niche by EXPLICIT, per-Business assignment
 * (`blueprint:repair-photo-booth --business=… --assign-photo-booth`) without changing Business.industry,
 * and no sweep ever assigns it.
 */
class PhotoBoothNicheAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPhotoBoothBlueprintV2;

    private const KALEB = '6adcbf1d77011';

    private const GREENBELT = '6adcbf1dd6564';

    private function repairRaw(array $options): array
    {
        $code = Artisan::call('blueprint:repair-photo-booth', $options);

        return [$code, Artisan::output()];
    }

    private function ok(array $options): string
    {
        [$code, $out] = $this->repairRaw($options);
        $this->assertSame(0, $code, $out);

        return $out;
    }

    /** @return array{0: \App\Models\Customer, 1: Business} a Growth Business with a real location and the given industry/uid */
    private function client(string $name, string $industry, ?string $uid = null): array
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, $name, $name.' WS');
        Business::query()->whereKey($business->id)->update(array_filter(['industry' => $industry, 'uid' => $uid]));
        BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main studio', 'service_mode' => 'storefront', 'country_code' => 'US']);

        return [$customer, $business->fresh()];
    }

    private function wipe(Business $business): void
    {
        BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->delete();
    }

    private function records(Business $business): int
    {
        return BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->count();
    }

    private function snapshot(Business $business): array
    {
        return BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->orderBy('id')->get()->toArray();
    }

    private function profileVertical(Business $business): ?string
    {
        return BusinessKnowledgeProfile::query()->where('business_id', $business->id)->value('vertical_key');
    }

    public function test_kaleb_and_greenbelt_photographers_are_reported_by_dry_run_and_only_assigned_with_apply(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $kaleb] = $this->client('Kaleb Hill Photography', 'photographer', self::KALEB);
        [, $green] = $this->client('Greenbelt Photography', 'photographer', self::GREENBELT);
        $this->wipe($kaleb);
        $this->wipe($green);
        $selected = ['--business' => [self::KALEB, self::GREENBELT], '--assign-photo-booth' => true];

        // Without the explicit flag a photographer is simply not a Photo Booth Business.
        $plain = $this->ok(['--business' => [self::KALEB]]);
        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::NOT_PHOTO_BOOTH, $plain);

        $dry = $this->ok($selected);
        $this->assertStringContainsString('MODE: DRY RUN', $dry);
        $this->assertSame(2, substr_count($dry, PhotoBoothBlueprintProvisioner::ELIGIBLE_ASSIGNMENT.' '), $dry);
        $this->assertStringContainsString('eligible_explicit_assignment=2', $dry);
        foreach ([$kaleb, $green] as $b) {
            $this->assertSame(0, $this->records($b), 'a dry run writes no records');
            $this->assertNull($this->profileVertical($b), 'a dry run writes no profile');
        }
        $this->assertSame(0, BusinessVertical::query()->where('key', 'photo_booth_service')->count(), 'a dry run creates no catalog row');

        $applied = $this->ok($selected + ['--apply' => true]);
        $this->assertStringContainsString('MODE: APPLY', $applied);
        $this->assertSame(2, substr_count($applied, 'niche assigned; installed='), $applied);

        foreach ([$kaleb, $green] as $b) {
            $this->assertSame('photographer', $b->fresh()->industry->value, 'the general industry is never changed');
            $this->assertSame('photo_booth_service', $this->profileVertical($b));
            $this->assertGreaterThan(0, $this->records($b));
            $this->assertSame('photo_booth_editorial', app(BlueprintConfigReader::class)->websiteConfig($b)['template_key']);
        }

        $before = $this->snapshot($kaleb);
        $again = $this->ok($selected + ['--apply' => true]);
        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::ALREADY_PROVISIONED, $again);
        $this->assertSame($before, $this->snapshot($kaleb), 're-running changes nothing');
    }

    public function test_existing_photo_booth_service_clients_are_still_repaired_and_valid_ones_are_untouched(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $missing] = $this->client('Missing Booth', 'photo_booth_service');
        [, $valid] = $this->client('Valid Booth', 'photo_booth_service');
        $this->wipe($missing);
        $this->artisan('blueprint:install-missing', ['--business' => (string) $valid->id])->assertExitCode(0);
        $validBefore = $this->snapshot($valid);
        $this->assertNotEmpty($validBefore);

        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::ELIGIBLE, $this->ok([]));
        $this->assertSame(0, $this->records($missing));

        $this->ok(['--apply' => true]);

        $this->assertGreaterThan(0, $this->records($missing));
        $this->assertSame($validBefore, $this->snapshot($valid));
        $this->assertNull($this->profileVertical($missing), 'a photo_booth_service Business needs no assignment');
    }

    public function test_an_unrelated_photographer_is_never_touched_by_any_sweep_or_by_a_neighbours_assignment(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $kaleb] = $this->client('Kaleb Hill Photography', 'photographer', self::KALEB);
        [, $other] = $this->client('Unrelated Photographer', 'photographer');
        $this->wipe($kaleb);
        $this->wipe($other);

        $sweep = $this->ok(['--apply' => true]);
        $this->assertStringNotContainsString('Unrelated Photographer', $sweep);
        $this->assertStringNotContainsString('Kaleb Hill Photography', $sweep);

        $this->ok(['--business' => [self::KALEB], '--assign-photo-booth' => true, '--apply' => true]);
        $this->assertGreaterThan(0, $this->records($kaleb));

        $sweepAfter = $this->ok(['--apply' => true]);
        $this->assertStringNotContainsString('Unrelated Photographer', $sweepAfter);
        $this->assertSame(0, $this->records($other));
        $this->assertNull($this->profileVertical($other));
        $this->assertSame('photographer', $other->fresh()->industry->value);
        $this->assertNull(app(BlueprintConfigReader::class)->websiteConfig($other));
    }

    public function test_assignment_requires_explicit_selection_and_aborts_wholly_on_an_unknown_selector(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $kaleb] = $this->client('Kaleb Hill Photography', 'photographer', self::KALEB);
        $this->wipe($kaleb);

        [$code, $out] = $this->repairRaw(['--assign-photo-booth' => true, '--apply' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Nothing was changed', $out);

        [$code, $out] = $this->repairRaw(['--business' => [self::KALEB, 'doesnotexist'], '--assign-photo-booth' => true, '--apply' => true]);
        $this->assertSame(1, $code);
        $this->assertSame(0, $this->records($kaleb), 'the valid selector is not applied when another selector is unknown');
        $this->assertNull($this->profileVertical($kaleb));

        // A blank explicit selector must not become a global --apply sweep.
        // Include a native Photo Booth Business that a sweep WOULD install.
        [, $native] = $this->client('Untouched Photo Booth', 'photo_booth_service');
        $this->wipe($native);
        [$blankCode, $blankOut] = $this->repairRaw(['--business' => ['   '], '--apply' => true]);
        $this->assertSame(1, $blankCode);
        $this->assertStringContainsString('selector is empty', $blankOut);
        $this->assertSame(0, $this->records($native), 'blank selector must never install the native Photo Booth Business');
        $this->assertSame(0, $this->records($kaleb));
    }

    public function test_assignment_never_overwrites_another_niche_an_existing_blueprint_or_skips_the_plan_check(): void
    {
        $this->seedPhotoBoothBlueprintV2();

        // A photographer whose profile already names a different niche.
        [, $dog] = $this->client('Dog Niche Photographer', 'photographer');
        $this->wipe($dog);
        BusinessVertical::create(['key' => 'dog_boarding', 'display_name' => 'Dog boarding', 'broad_industry' => 'other', 'is_active' => true]);
        BusinessKnowledgeProfile::create(['business_id' => $dog->id, 'vertical_key' => 'dog_boarding', 'reviews_source' => 'none']);

        // A photographer already holding valid installation records for the Photo Booth Blueprint.
        [, $held] = $this->client('Held Photographer', 'photographer');
        $this->artisan('blueprint:install-missing', ['--business' => (string) $held->id])->assertExitCode(0);
        $heldBefore = $this->snapshot($held);
        $this->assertNotEmpty($heldBefore);

        // A photographer in a Workspace with no plan.
        [, $unplanned] = $this->businessWithoutPlan('Unplanned Photographer');
        Business::query()->whereKey($unplanned->id)->update(['industry' => 'photographer']);

        // An existing catalog row with an inactive niche key cannot be reused.
        // This must be assessed as blocked, not crash halfway through --apply.
        [, $inactive] = $this->client('Inactive Photo Booth Niche', 'photographer');
        $this->wipe($inactive);
        BusinessVertical::create(['key' => PhotoBoothBlueprintProvisioner::NICHE_KEY, 'display_name' => 'Photo Booth', 'broad_industry' => 'photo_booth_service', 'is_active' => false]);

        $out = $this->ok(['--business' => [(string) $dog->id, (string) $held->id, (string) $unplanned->id, (string) $inactive->id], '--assign-photo-booth' => true, '--apply' => true]);

        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::BLOCKED_OTHER_VERTICAL, $out);
        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::ALREADY_PROVISIONED, $out);
        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::BLOCKED_PLAN_UNASSIGNED, $out);
        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::BLOCKED_NICHE_CATALOG, $out);
        $this->assertSame('dog_boarding', $this->profileVertical($dog));
        $this->assertSame(0, $this->records($dog));
        $this->assertSame($heldBefore, $this->snapshot($held));
        $this->assertNull($this->profileVertical($held));
        $this->assertSame(0, $this->records($unplanned));
        $this->assertNull($this->profileVertical($unplanned), 'a blocked Business is not assigned either');
        $this->assertNull($this->profileVertical($inactive), 'an inactive niche catalog row must not be assigned');
        $this->assertSame(0, $this->records($inactive));
        $this->assertFalse((bool) BusinessVertical::query()->where('key', PhotoBoothBlueprintProvisioner::NICHE_KEY)->value('is_active'));
    }

    public function test_explicit_assignment_with_no_photo_booth_blueprint_fails_closed_without_writing(): void
    {
        // No seed: a selected photographer must receive a normal blocked result,
        // not an exception from dereferencing a missing Blueprint resolution.
        [, $kaleb] = $this->client('Kaleb Hill Photography', 'photographer', self::KALEB);
        $this->wipe($kaleb);

        $out = $this->ok([
            '--business' => [self::KALEB],
            '--assign-photo-booth' => true,
            '--apply' => true,
        ]);

        $this->assertStringContainsString(PhotoBoothBlueprintProvisioner::BLOCKED_NO_PUBLISHED_BLUEPRINT, $out);
        $this->assertSame(0, $this->records($kaleb));
        $this->assertNull($this->profileVertical($kaleb));
        $this->assertSame(0, BusinessVertical::query()->where('key', PhotoBoothBlueprintProvisioner::NICHE_KEY)->count());
        $this->assertSame('photographer', $kaleb->fresh()->industry->value);
    }

    public function test_websites_and_customisations_are_preserved_by_the_assignment(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $kaleb] = $this->client('Kaleb Hill Photography', 'photographer', self::KALEB);
        $this->wipe($kaleb);
        $website = Website::create(['business_id' => $kaleb->id, 'name' => 'Kaleb', 'template_key' => 'photo_booth_luxury']);
        $websiteBefore = $website->fresh()->toArray();

        $this->ok(['--business' => [self::KALEB], '--assign-photo-booth' => true, '--apply' => true]);

        $this->assertSame($websiteBefore, $website->fresh()->toArray());
        $this->assertSame(1, Website::query()->where('business_id', $kaleb->id)->count());
        $this->assertSame('photo_booth_luxury', $website->fresh()->template_key, 'the Blueprint only ever suggests a design');
    }

    public function test_the_owner_sees_the_photo_booth_setup_and_looks_only_after_assignment(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV2Seeder::class);
        [$customer, $kaleb] = $this->client('Kaleb Hill Photography', 'photographer', self::KALEB);
        $this->wipe($kaleb);
        $workspaceUid = Workspace::query()->findOrFail($kaleb->workspace_id)->uid;
        $this->authenticateAs($customer);
        $start = route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $kaleb->uid]);

        // Before: a photographer resolves no Photo Booth setup.
        $this->get($start)->assertOk();
        $this->assertSame(0, QuestionnaireResponse::query()->where('business_id', $kaleb->id)->count());
        $this->assertSame(0, Website::query()->where('business_id', $kaleb->id)->count());

        $this->ok(['--business' => [self::KALEB], '--assign-photo-booth' => true, '--apply' => true]);

        $this->get($start)->assertRedirect();
        $response = QuestionnaireResponse::query()->where('business_id', $kaleb->id)->sole();
        $this->assertSame('photo_booth_service', QuestionnaireDefinition::query()->findOrFail($response->questionnaire_definition_id)->niche_key);
        $this->assertSame('photo_booth_editorial', Website::query()->where('business_id', $kaleb->id)->sole()->template_key);

        $html = $this->get(route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $kaleb->uid, 'template']))->assertOk()->getContent();
        $this->assertStringContainsString('photo_booth_editorial', $html);
        $this->assertStringContainsString('photo_booth_luxury', $html);
    }
}
