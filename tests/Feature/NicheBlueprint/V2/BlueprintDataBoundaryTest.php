<?php

namespace Tests\Feature\NicheBlueprint\V2;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\NicheBlueprint\InvalidComponentDescriptorException;
use App\Library\NicheBlueprint\NicheBlueprintPublisher;
use App\Library\NicheBlueprint\PhotoBoothBlueprintV2;
use App\Library\NicheBlueprint\Workspace\BlueprintDataBoundary;
use App\Library\NicheBlueprint\Workspace\BlueprintWorkspaceService;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Feature\NicheBlueprint\V2\Support\SeedsPhotoBoothBlueprintV2;
use Tests\TestCase;

/**
 * The CRITICAL DATA BOUNDARY: a Blueprint carries configuration, never
 * customer/operational data, credentials or provider state.
 */
class BlueprintDataBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPhotoBoothBlueprintV2;

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function forbiddenPayloads(): array
    {
        return [
            'an access token' => [['label' => 'x', 'access_token' => 'abc']],
            'a refresh token' => [['nested' => ['refresh_token' => 'abc']]],
            'a client secret' => [['client_secret' => 'abc']],
            'an api key' => [['api_key' => 'abc']],
            'contacts' => [['contacts' => [['name' => 'A']]]],
            'opportunities' => [['opportunities' => []]],
            'submissions' => [['submissions' => []]],
            'invoices' => [['invoices' => []]],
            'payments' => [['payments' => []]],
            'a business id' => [['business_id' => 5]],
            'users' => [['users' => []]],
            'wallet state' => [['wallet' => 12]],
            'google ads data' => [['google_ads' => []]],
            'rank observations' => [['rank_observations' => []]],
            'reviews' => [['reviews' => []]],
            'a real email' => [['body' => 'Call jane.doe@gmail.com']],
            'a real phone number' => [['body' => 'Text me on +1 (555) 123-4567']],
            'a stripe key' => [['body' => 'use sk_live_51HabcdefGHIJ']],
            'a google token' => [['body' => 'ya29.a0AfH6SMBx']],
        ];
    }

    /** @dataProvider forbiddenPayloads */
    public function test_forbidden_data_is_refused(array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);

        BlueprintDataBoundary::assertClean($payload);
    }

    public function test_merge_tokens_and_ordinary_configuration_pass(): void
    {
        BlueprintDataBoundary::assertClean([
            'body' => 'Hi {{contact.first_name}}, reach us at {{business.email}} or {{business.phone}}.',
            'payment_terms' => ['deposit_percent' => 25],
            'duration_minutes' => 20,
            'template_uid' => '81581046-8362-40e6-8f21-a034c1d4036d',
            'amount' => '49900',
        ]);

        $this->assertTrue(true);
    }

    public function test_every_photo_booth_component_is_clean(): void
    {
        foreach (PhotoBoothBlueprintV2::components() as $component) {
            BlueprintDataBoundary::assertClean($component['payload']);
        }

        $this->assertTrue(true);
    }

    public function test_publishing_a_draft_carrying_forbidden_data_is_refused_and_leaves_the_draft_untouched(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprintV2();
        $adminId = $this->platformAdminId();
        $publisher = app(NicheBlueprintPublisher::class);
        $draft = app(BlueprintWorkspaceService::class)->draftFor($blueprint, $adminId);

        // Bypass the Workspace's own save-time check to prove the publish gate stands alone.
        $publisher->addDraftComponent($adminId, $draft, 'smuggled', 'crm_tag_set', 'crm', ['tags' => ['ok'], 'api_key' => 'sk_live_x']);

        try {
            $publisher->publishVersion($adminId, $draft);
            $this->fail('A payload with a credential key must not publish.');
        } catch (InvalidComponentDescriptorException) {
            $this->assertSame('draft', $draft->fresh()->state->value);
        }
    }

    public function test_the_workspace_refuses_forbidden_data_at_save_time(): void
    {
        $blueprint = $this->seedPhotoBoothBlueprintV2();
        $adminId = $this->platformAdminId();
        $workspace = app(BlueprintWorkspaceService::class);
        $draft = $workspace->draftFor($blueprint, $adminId);

        $this->expectException(InvalidArgumentException::class);

        $workspace->saveComponent($adminId, $draft, 'crm_tag_set', null, ['tags' => "Wedding\nclient@example.com"]);
    }

    public function test_provisioning_a_business_copies_no_customer_or_operational_data(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        // Row counts in every operational/credential table that exists on this schema.
        $tables = [
            'contacts', 'crm_opportunities', 'appointments', 'form_submissions', 'conversations', 'conversation_messages',
            'business_documents', 'business_document_payments', 'business_document_refunds', 'business_stripe_connections',
            'business_google_connections', 'google_ads_connections', 'meta_ads_connections', 'seo_rank_observations', 'seo_citations',
            'seo_review_requests', 'usage_wallets', 'usage_wallet_ledger_entries',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty after provisioning.");
            }
        }
    }

    public function test_no_stored_blueprint_descriptor_or_installation_record_contains_a_business_id_or_secret(): void
    {
        $this->seedPhotoBoothBlueprintV2();
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        foreach (DB::table('niche_blueprint_components')->get(['component_key', 'payload']) as $row) {
            BlueprintDataBoundary::assertClean(json_decode((string) $row->payload, true) ?? []);
        }

        // The installation record is provenance (ids + hashes), never a copy of configuration.
        $columns = Schema::getColumnListing('business_blueprint_component_installations');
        $this->assertNotContains('payload', $columns);
        $this->assertGreaterThan(0, BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->count());
        $this->assertInstanceOf(Business::class, $business);
    }
}
