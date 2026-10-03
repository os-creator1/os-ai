<?php

namespace Tests\Feature\Documents\PlatformTemplates;

use App\Library\Documents\Templates\DocumentTemplateService;
use App\Library\Documents\Templates\PlatformTemplateNicheAssignments;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Models\Business;
use App\Models\DocumentTemplate;
use App\Models\NicheBlueprint;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Documents\Templates\TemplateTestHelpers;

/**
 * Fixtures for the Contract 17B §6b platform-template tests. The using class also
 * uses SendsDocuments, CreatesDocumentsTestData and CreatesCatalogHttpFixtures
 * (through TemplateTestHelpers' contract), and RefreshDatabase.
 */
trait PlatformTemplateTestHelpers
{
    use TemplateTestHelpers;

    protected ?User $platformOwner = null;

    protected function owner(): User
    {
        return $this->platformOwner ??= User::create([
            'first_name' => 'Pat',
            'last_name' => 'Owner',
            'email' => 'pat-owner-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    /** Sign in as the Platform Owner (an `is_admin` account with the backend permission). */
    protected function asOwner(): User
    {
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($this->owner());

        return $this->owner();
    }

    /** An admin-portal account that is NOT a Platform Owner (`is_admin` false), with the backend permission string. */
    protected function asNonOwnerAdminAccount(): User
    {
        $user = User::create([
            'first_name' => 'Not',
            'last_name' => 'Owner',
            'email' => 'not-owner-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($user);

        return $user;
    }

    protected function asTenant(array $tenant): void
    {
        $this->authenticateAs($tenant['customer']);
    }

    /** Admin route URL for a platform template. */
    protected function adm(string $name, ?DocumentTemplate $template = null, array $extra = []): string
    {
        return $template === null
            ? route('admin.document-templates.' . $name, $extra)
            : route('admin.document-templates.' . $name, [$template->uid, ...$extra]);
    }

    protected function templates(): DocumentTemplateService
    {
        return app(DocumentTemplateService::class);
    }

    protected function assignments(): PlatformTemplateNicheAssignments
    {
        return app(PlatformTemplateNicheAssignments::class);
    }

    /** Blocks valid for a platform template (no image, no business data). */
    protected function platformBlocks(): array
    {
        return [
            ['id' => 'p-title', 'type' => 'heading', 'data' => ['level' => 1, 'align' => 'left', 'runs' => [['t' => 'Proposal for '], ['merge' => 'contact.first_name']]]],
            ['id' => 'p-intro', 'type' => 'text', 'data' => ['align' => 'left', 'runs' => [['t' => 'Thank you for choosing '], ['merge' => 'business.name'], ['t' => '.']]]],
            ['id' => 'p-details', 'type' => 'business_details', 'data' => ['show' => ['name', 'email']]],
            ['id' => 'p-products', 'type' => 'product_list', 'data' => ['show_description' => true, 'show_quantity' => true]],
            ['id' => 'p-payment', 'type' => 'payment_terms', 'data' => []],
            ['id' => 'p-sign', 'type' => 'signature', 'data' => ['label' => 'Client signature']],
        ];
    }

    /** A PUBLISHED (active) platform template, created and edited through the service as the owner. */
    protected function livePlatformTemplate(string $name = 'Platform proposal', ?array $blocks = null, string $type = 'proposal'): DocumentTemplate
    {
        $template = $this->templates()->createPlatform($name, $type, 'A platform layout', $this->owner());
        $template = $this->templates()->update($template, ['blocks' => $blocks ?? $this->platformBlocks()], (int) $template->lock_version, null);

        return $this->templates()->activatePlatform($template, $this->owner());
    }

    /** Run the real `blueprint:seed-photo-booth` (v1 published, CRM pipeline only). */
    protected function seedPhotoBoothBlueprint(): NicheBlueprint
    {
        $this->artisan('blueprint:seed-photo-booth', ['--actor' => $this->owner()->id])->assertExitCode(0);

        return NicheBlueprint::query()->where('key', 'photo_booth')->firstOrFail();
    }

    /** Save the assignment to the draft AND publish the blueprint version (both owner steps). */
    protected function assignAndPublish(DocumentTemplate $template, NicheBlueprint $blueprint): void
    {
        $this->assignments()->assign($this->owner(), $template, $blueprint);
        $this->assignments()->publishDraft($this->owner(), $blueprint);
    }

    protected function publisher(): NicheBlueprintPublisher
    {
        return app(NicheBlueprintPublisher::class);
    }

    /** A Photo Booth Business tenant (the default fixture industry) signed in as its owner. */
    protected function photoBoothTenant(string $name = 'Harbor Lane Studios'): array
    {
        $tenant = $this->editorTenant($name);
        DB::table('businesses')->where('id', $tenant['business']->id)->update(['industry' => 'photo_booth_service']);
        $tenant['business'] = Business::findOrFail($tenant['business']->id);

        return $tenant;
    }

    /** A Business in an UNRELATED niche. */
    protected function homeServicesTenant(string $name = 'Bright Home Services'): array
    {
        $tenant = $this->editorTenant($name);
        DB::table('businesses')->where('id', $tenant['business']->id)->update(['industry' => 'home_services']);
        $tenant['business'] = Business::findOrFail($tenant['business']->id);

        return $tenant;
    }

    /** A stable fingerprint of a template row (for "the platform template was never mutated"). */
    protected function templateHash(DocumentTemplate $template): string
    {
        return md5(json_encode(DB::table('document_templates')->where('id', $template->id)->first()));
    }

    /** @return array<int, string> uids the Business is recommended */
    protected function recommendedUids(Business $business): array
    {
        return app(\App\Library\Documents\Templates\RecommendedPlatformTemplates::class)
            ->forBusiness($business->fresh())->pluck('uid')->all();
    }
}
