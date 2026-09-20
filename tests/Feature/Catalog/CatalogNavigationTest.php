<?php

namespace Tests\Feature\Catalog;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Navigation\CustomerMenuBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 16 §12.E — the Packages & Products nav entry.
 *
 * Visibility is presentation, NEVER authorization: these tests prove the entry
 * appears exactly when the first two gates would let the actor in, and the
 * authorization matrix separately proves the routes themselves re-check
 * everything regardless of what the sidebar showed.
 */
class CatalogNavigationTest extends TestCase
{
    use CreatesCatalogHttpFixtures;
    use RefreshDatabase;

    private const KEY = 'packages_products';

    public function test_the_feature_key_is_registered_as_entitlement_gated(): void
    {
        // Omitting this silently hides the entry for every account forever
        // (the lesson Contract 15 already documents).
        $this->assertContains(self::KEY, CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES);
    }

    public function test_every_plan_tier_sees_the_entry_once_available(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$owner] = $this->catalogTenant($tier, 'Tier ' . $tier->value . ' Studio');
            $this->authenticateAs($owner);

            $this->assertContains(
                self::KEY,
                $this->menuKeys($this->home()->assertOk()->getContent()),
                'Blueprint §21 places Packages & Products in every tier; ' . $tier->value . ' must see it.'
            );
        }
    }

    public function test_the_entry_links_to_the_catalog_and_reads_as_a_plain_noun(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateAs($owner);

        $links = $this->menuLinks($this->home()->assertOk()->getContent());

        $this->assertContains(
            $this->catalogRoute('index', $workspace, $business),
            $links,
            'The entry must link to the catalog index.'
        );
        $this->assertStringContainsString('Packages &amp; Products', $this->sidebarHtml($this->home()->getContent()));
    }

    public function test_the_entry_is_hidden_without_the_capability(): void
    {
        [$owner] = $this->catalogTenant();
        $this->authenticateWithoutCatalogCapability($owner);

        $this->assertNotContains(self::KEY, $this->menuKeys($this->home()->assertOk()->getContent()));
    }

    public function test_the_entry_is_hidden_when_the_workspace_is_unentitled(): void
    {
        [$owner, , $workspace] = $this->catalogTenant();
        $this->denyCatalogEntitlement($workspace);
        $this->authenticateAs($owner);

        $this->assertNotContains(self::KEY, $this->menuKeys($this->home()->assertOk()->getContent()));
    }

    public function test_a_staff_member_sees_it_only_with_the_capability(): void
    {
        [, , $workspace] = $this->catalogTenant();
        $staff = $this->staffWithFullReach($workspace);

        $this->authenticateAs($staff);
        $this->assertContains(self::KEY, $this->menuKeys($this->home()->assertOk()->getContent()));

        $this->authenticateWithoutCatalogCapability($staff);
        $this->assertNotContains(self::KEY, $this->menuKeys($this->home()->assertOk()->getContent()));
    }

    /** The entry lights up on every catalog screen, including Location ones. */
    public function test_the_entry_is_active_on_every_catalog_screen(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business);
        $this->authenticateAs($owner);

        foreach ([
            $this->catalogRoute('index', $workspace, $business),
            $this->catalogRoute('create', $workspace, $business),
            $this->catalogRoute('edit', $workspace, $business, [$item->uid]),
            $this->catalogRoute('locations.index', $workspace, $business),
            $this->catalogRoute('locations.show', $workspace, $business, [$location->uid]),
        ] as $url) {
            $this->assertContains(
                self::KEY,
                $this->activeMenuKeys($this->get($url)->assertOk()->getContent()),
                "The nav entry must be active on {$url}."
            );
        }
    }

    /**
     * Showing the entry never authorizes anything: an actor who is REFUSED the
     * routes must not have been given a working link to them.
     */
    public function test_an_actor_refused_the_routes_is_never_shown_a_link_to_them(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $this->authenticateWithoutCatalogCapability($owner);

        $this->get($this->catalogRoute('index', $workspace, $business))->assertUnauthorized();

        $this->assertNotContains(
            $this->catalogRoute('index', $workspace, $business),
            $this->menuLinks($this->home()->assertOk()->getContent())
        );
    }
}
