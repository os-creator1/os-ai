<?php

namespace Tests\Feature\Documents;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\CustomerMenuBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Implementation Contract 17 §12.G — the Payments & Contracts nav entry.
 *
 * Mirrors CatalogNavigationTest exactly (Contract 16 §12.E's own precedent):
 * visibility is presentation, NEVER authorization. These tests prove the
 * entry appears exactly when the capability and the entitlement would both
 * let the actor in; DocumentsControllerTest separately proves the routes
 * themselves re-check everything regardless of what the sidebar showed.
 */
class DocumentsNavigationTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    private const KEY = 'payments_contracts';

    public function test_the_feature_key_is_registered_as_entitlement_gated(): void
    {
        // Omitting this silently hides the entry for every account forever
        // (the lesson Contract 16 §18.E already recorded).
        $this->assertContains(self::KEY, CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES);
    }

    public function test_every_plan_tier_sees_the_entry(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer] = $this->tenant($tier, 'Tier ' . $tier->value . ' Studio', 'Tier ' . $tier->value . ' WS');
            $this->authenticateAs($customer);

            $this->assertContains(
                self::KEY,
                $this->menuKeys($this->home()->assertOk()->getContent()),
                'Blueprint §21 places Payments & Contracts in every tier; ' . $tier->value . ' must see it.'
            );
        }
    }

    public function test_the_entry_links_to_the_documents_index_and_reads_as_a_plain_noun(): void
    {
        $tenant = $this->sendableTenant();
        $this->authenticateAs($tenant['customer']);

        $links = $this->menuLinks($this->home()->assertOk()->getContent());

        $this->assertContains(
            route('customer.workspaces.businesses.documents.index', [$tenant['workspace']->uid, $tenant['business']->uid]),
            $links,
            'The entry must link to the documents index.'
        );
        $this->assertStringContainsString('Payments &amp; Contracts', $this->sidebarHtml($this->home()->getContent()));
    }

    public function test_the_entry_is_hidden_without_the_capability(): void
    {
        $tenant = $this->sendableTenant();
        $this->authenticateAs(
            $tenant['customer'],
            array_values(array_diff($this->allCustomerPermissions(), [self::KEY]))
        );

        $this->assertNotContains(self::KEY, $this->menuKeys($this->home()->assertOk()->getContent()));
    }

    public function test_the_entry_is_hidden_when_the_workspace_is_unentitled(): void
    {
        $tenant = $this->sendableTenant();

        app(EntitlementManager::class)->createOrChangeOverride(
            $tenant['workspace'],
            PlatformFeature::PaymentsContracts,
            WorkspaceEntitlementOverrideState::Deny,
            $this->platformAdminId(),
            'Documents nav test: deny the feature.'
        );

        $this->authenticateAs($tenant['customer']);

        $this->assertNotContains(self::KEY, $this->menuKeys($this->home()->assertOk()->getContent()));
    }

    public function test_the_entry_is_active_on_the_documents_screen(): void
    {
        $tenant = $this->sendableTenant();
        $this->authenticateAs($tenant['customer']);

        $url = route('customer.workspaces.businesses.documents.index', [$tenant['workspace']->uid, $tenant['business']->uid]);

        $this->assertContains(
            self::KEY,
            $this->activeMenuKeys($this->get($url)->assertOk()->getContent()),
            "The nav entry must be active on {$url}."
        );
    }

    /**
     * Showing the entry never authorizes anything: an actor who is REFUSED
     * the routes must not have been given a working link to them.
     */
    public function test_an_actor_refused_the_routes_is_never_shown_a_link_to_them(): void
    {
        $tenant = $this->sendableTenant();
        $this->authenticateAs(
            $tenant['customer'],
            array_values(array_diff($this->allCustomerPermissions(), [self::KEY]))
        );

        $url = route('customer.workspaces.businesses.documents.index', [$tenant['workspace']->uid, $tenant['business']->uid]);
        $this->get($url)->assertStatus(401);

        $this->assertNotContains($url, $this->menuLinks($this->home()->assertOk()->getContent()));
    }
}
