<?php

namespace Tests\Feature\V1Acceptance\CrmLeads;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V1Acceptance\CrmLeads\Concerns\CrmLeadsFixtures;
use Tests\TestCase;

/**
 * V1 Final Acceptance 02, journey F/G/H — Location authority over Contacts and
 * CRM Opportunities, walked as the owner and as a Selected-location staff
 * member, through every reach a person has: the Contacts directory, a profile
 * URL, the CRM board, the "Add opportunity" picker, Global Search, a deal URL
 * and the Contact bulk actions and export.
 *
 * One Business, two Locations. Location A's staff member holds a grant for A
 * only. Every record at B (and every B-only fact) must be absent for them from
 * every surface — never filtered client-side, never reachable by guessing the
 * uid — while the owner reaches both.
 */
class LocationAuthorityJourneyTest extends TestCase
{
    use RefreshDatabase;
    use CrmLeadsFixtures;

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $a;

    private BusinessLocation $b;

    private Contacts $ana;

    private Contacts $bruno;

    private Contacts $wide;

    private CrmOpportunity $dealA;

    private CrmOpportunity $dealB;

    private Customer $staffA;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->business, $this->workspace] = $this->crmTenant();
        $this->a = $this->location($this->business, 'Location A');
        $this->b = $this->location($this->business, 'Location B');

        $this->ana = $this->personAt($this->business, $this->a, 'Anaïs', 'Alpha', '14155550101');
        $this->bruno = $this->personAt($this->business, $this->b, 'Bruno', 'Bravo', '14155550202');
        $this->wide = $this->personAt($this->business, null, 'Wanda', 'Wide', '14155550303');

        $this->dealA = $this->dealAt($this->business, $this->a, $this->ana, 'Deal Alpha');
        $this->dealB = $this->dealAt($this->business, $this->b, $this->bruno, 'Deal Bravo');

        $this->staffA = $this->staffAt($this->workspace, $this->a);
    }

    private function asStaff(): void
    {
        $this->authenticateAs($this->staffA, self::STAFF_PERMISSIONS);
    }

    private function searchResults(string $q): array
    {
        $url = route('customer.workspaces.businesses.search', [$this->workspace->uid, $this->business->uid]);

        // Grouped by domain (contacts / opportunities / conversations / documents):
        // flattened to the items a person would see, each carrying its own domain.
        $grouped = $this->getJson($url . '?q=' . urlencode($q))->assertOk()->json('results');

        return array_merge(...array_values($grouped));
    }

    private function batchUrl(): string
    {
        return route('customer.workspaces.businesses.contact.batch_action', [$this->workspace->uid, $this->business->uid, $this->ana->contactGroup->uid]);
    }

    // ------------------------------------------------------------------ owner

    public function test_the_owner_reaches_every_location_through_every_surface(): void
    {
        $this->authenticateAs($this->owner);

        $this->get($this->peopleUrl($this->workspace, $this->business))
            ->assertOk()->assertSee('Anaïs')->assertSee('Bruno')->assertSee('Wanda');
        $this->get($this->peopleUrl($this->workspace, $this->business, $this->bruno->uid))->assertOk()->assertSee('Bruno');

        $board = $this->get($this->crmRoute('board', $this->workspace, $this->business))->assertOk();
        $board->assertSee('Deal Alpha')->assertSee('Deal Bravo');

        $this->get($this->crmRoute('opportunities.show', $this->workspace, $this->business, [$this->dealB->uid]))->assertOk();
        $this->assertCount(3, $this->getJson($this->crmRoute('contacts.search', $this->workspace, $this->business))->json('results'));
        $this->assertCount(1, $this->searchResults('Bruno'));
    }

    // ----------------------------------------------------- Selected-scope staff

    public function test_staff_see_their_location_and_the_business_wide_contacts_only_in_the_directory(): void
    {
        $this->asStaff();

        $this->get($this->peopleUrl($this->workspace, $this->business))
            ->assertOk()->assertSee('Anaïs')->assertSee('Wanda')
            ->assertDontSee('Bruno')->assertDontSee('14155550202')->assertDontSee('Bravo');

        // The search box narrows within reach; it never widens it (the page echoes
        // what was typed, so the proof is the absence of the person's row).
        foreach (['Bruno', '0202'] as $needle) {
            $this->get($this->peopleUrl($this->workspace, $this->business, null, ['q' => $needle]))
                ->assertOk()->assertSee('No contacts match')->assertDontSee('14155550202')->assertDontSee($this->bruno->uid);
        }
    }

    public function test_a_foreign_location_profile_is_a_404_by_direct_url_and_the_own_one_opens(): void
    {
        $this->asStaff();

        $this->get($this->peopleUrl($this->workspace, $this->business, $this->ana->uid))->assertOk()->assertSee('Anaïs');
        $this->get($this->peopleUrl($this->workspace, $this->business, $this->wide->uid))->assertOk();
        $this->get($this->peopleUrl($this->workspace, $this->business, $this->bruno->uid))->assertNotFound();
    }

    public function test_the_crm_board_lists_only_reachable_deals_and_counts_only_them(): void
    {
        $this->asStaff();

        $board = $this->get($this->crmRoute('board', $this->workspace, $this->business))->assertOk();
        $board->assertSee('Deal Alpha')->assertDontSee('Deal Bravo')->assertDontSee('Bruno');

        // Board search cannot reach a hidden deal by its title or its contact.
        foreach (['Bravo', 'Bruno', '0202'] as $needle) {
            $this->get($this->crmRoute('board', $this->workspace, $this->business, ['q' => $needle]))
                ->assertOk()->assertDontSee('Deal Bravo');
        }

        $html = $board->getContent();
        $this->assertStringNotContainsString($this->dealB->uid, $html);
    }

    public function test_the_add_opportunity_picker_offers_only_reachable_contacts(): void
    {
        $this->asStaff();

        $results = $this->getJson($this->crmRoute('contacts.search', $this->workspace, $this->business))->assertOk()->json('results');
        $ids = array_column($results, 'id');
        sort($ids);
        $expected = [$this->ana->uid, $this->wide->uid];
        sort($expected);
        $this->assertSame($expected, $ids);

        $this->assertSame([], $this->getJson($this->crmRoute('contacts.search', $this->workspace, $this->business, ['q' => '0202']))->json('results'));
        $this->assertSame([], $this->getJson($this->crmRoute('contacts.search', $this->workspace, $this->business, ['q' => 'Bruno']))->json('results'));
    }

    public function test_staff_cannot_open_a_deal_for_a_contact_they_cannot_reach(): void
    {
        $this->asStaff();
        $pipeline = $this->dealA->pipeline;

        // Pre-filling the form with a foreign contact reveals nothing.
        $this->get($this->crmRoute('opportunities.create', $this->workspace, $this->business, ['pipeline' => $pipeline->uid, 'contact' => $this->bruno->uid]))
            ->assertOk()->assertDontSee('Bruno')->assertDontSee('14155550202');

        // Posting it creates nothing.
        $this->post($this->crmRoute('opportunities.store', $this->workspace, $this->business), [
            'title' => 'Sneaky deal',
            'contact' => $this->bruno->uid,
            'pipeline' => $pipeline->uid,
        ])->assertSessionHasErrors('contact');

        $this->assertSame(0, CrmOpportunity::query()->where('title', 'Sneaky deal')->count());
    }

    public function test_a_deal_created_for_a_located_contact_carries_that_contacts_location(): void
    {
        $this->authenticateAs($this->owner);
        $pipeline = $this->dealA->pipeline;

        $this->post($this->crmRoute('opportunities.store', $this->workspace, $this->business), [
            'title' => 'Bruno remodel',
            'contact' => $this->bruno->uid,
            'pipeline' => $pipeline->uid,
        ])->assertRedirect();

        // Two Active Locations: the single-Active-Location rule has no answer,
        // but the Contact's own Location does — and it is authoritative.
        $deal = CrmOpportunity::query()->where('title', 'Bruno remodel')->sole();
        $this->assertSame((int) $this->b->id, (int) $deal->location_id);

        // So Location A's staff cannot open it by its uid.
        $this->asStaff();
        $this->get($this->crmRoute('opportunities.show', $this->workspace, $this->business, [$deal->uid]))->assertNotFound();
        $this->assertStringNotContainsString('Bruno remodel', $this->get($this->crmRoute('board', $this->workspace, $this->business))->getContent());
    }

    public function test_a_business_wide_contact_still_yields_a_business_wide_deal_when_the_location_is_ambiguous(): void
    {
        $this->authenticateAs($this->owner);

        $this->post($this->crmRoute('opportunities.store', $this->workspace, $this->business), [
            'title' => 'Wanda remodel',
            'contact' => $this->wide->uid,
            'pipeline' => $this->dealA->pipeline->uid,
        ])->assertRedirect();

        // Never guessed: two Active Locations, a Contact with none.
        $this->assertNull(CrmOpportunity::query()->where('title', 'Wanda remodel')->sole()->location_id);
    }

    public function test_staff_cannot_open_or_change_a_foreign_deal_by_its_uid(): void
    {
        $this->asStaff();
        $stage = $this->stageKeyed($this->dealB->pipeline, 'qualified');

        $this->get($this->crmRoute('opportunities.show', $this->workspace, $this->business, [$this->dealB->uid]))->assertNotFound();
        $this->postJson($this->crmRoute('opportunities.move', $this->workspace, $this->business, [$this->dealB->uid]), ['stage' => $stage->uid])->assertNotFound();
        $this->post($this->crmRoute('opportunities.update', $this->workspace, $this->business, [$this->dealB->uid]), ['title' => 'Hijacked'])->assertNotFound();
        $this->post($this->crmRoute('opportunities.won', $this->workspace, $this->business, [$this->dealB->uid]))->assertNotFound();
        $this->post($this->crmRoute('opportunities.lost', $this->workspace, $this->business, [$this->dealB->uid]))->assertNotFound();
        $this->post($this->crmRoute('opportunities.contact-status', $this->workspace, $this->business, [$this->dealB->uid]), ['contact_status' => 'contacted'])->assertNotFound();

        $fresh = $this->dealB->fresh();
        $this->assertSame('Deal Bravo', $fresh->title);
        $this->assertSame($this->dealB->stage_id, $fresh->stage_id);
        $this->assertSame('open', $fresh->status->value);

        // Their own deal moves normally.
        $this->postJson($this->crmRoute('opportunities.move', $this->workspace, $this->business, [$this->dealA->uid]), ['stage' => $this->stageKeyed($this->dealA->pipeline, 'qualified')->uid])
            ->assertOk()->assertJson(['moved' => true]);
    }

    public function test_global_search_returns_only_reachable_contacts_and_deals(): void
    {
        $this->asStaff();

        $this->assertSame([], $this->searchResults('Bruno'));
        $this->assertSame([], $this->searchResults('Bravo'));
        $this->assertSame([], $this->searchResults('0202'));
        $this->assertSame(['contacts', 'opportunities'], array_values(array_unique(array_column($this->searchResults('Alpha'), 'domain'))));
        $this->assertNotEmpty($this->searchResults('Wanda'));
    }

    public function test_a_hidden_flood_of_foreign_location_matches_never_starves_the_reachable_results(): void
    {
        // Global Search reads a bounded window of candidates and filters it by
        // Location: enough newer foreign-location matches must not push the one
        // reachable match out of the window.
        $mine = $this->personAt($this->business, $this->a, "Mine", "Zulu", "14155559999");
        $this->dealAt($this->business, $this->a, $mine, "Zulu mine");
        // The reachable pair is the OLDEST row; every newer match is foreign.
        for ($i = 0; $i < 30; $i++) {
            $person = $this->personAt($this->business, $this->b, 'Flood' . $i, 'Zulu', '1415556' . str_pad((string) $i, 4, '0', STR_PAD_LEFT));
            $this->dealAt($this->business, $this->b, $person, 'Zulu flood ' . $i);
        }
        $this->asStaff();
        $domains = array_column($this->searchResults('Zulu'), 'domain');

        $this->assertContains('opportunities', $domains);
        $this->assertContains('contacts', $domains);
    }

    // ------------------------------------------------------------------ bulk

    public function test_bulk_actions_never_touch_a_contact_at_a_location_the_actor_cannot_reach(): void
    {
        $this->asStaff();
        $ids = [$this->ana->uid, $this->bruno->uid, $this->wide->uid];

        $this->postJson($this->batchUrl(), ['action' => 'unsubscribe', 'ids' => $ids])->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame('unsubscribe', $this->ana->fresh()->status);
        $this->assertSame('unsubscribe', $this->wide->fresh()->status);
        $this->assertSame('subscribe', $this->bruno->fresh()->status, 'a foreign-location contact is skipped, not changed');

        $this->postJson($this->batchUrl(), ['action' => 'subscribe', 'ids' => $ids])->assertOk();
        $this->assertSame('subscribe', $this->ana->fresh()->status);

        $this->postJson($this->batchUrl(), ['action' => 'destroy', 'ids' => $ids])->assertOk();
        $this->assertNull(Contacts::find($this->ana->id));
        $this->assertNull(Contacts::find($this->wide->id));
        $this->assertNotNull(Contacts::find($this->bruno->id), 'a foreign-location contact survives a bulk delete');
    }

    public function test_bulk_move_and_copy_skip_foreign_location_contacts(): void
    {
        $this->asStaff();
        $target = \App\Models\ContactGroups::create([
            'customer_id' => $this->business->customer_id,
            'business_id' => $this->business->id,
            'name' => 'Second list',
            'status' => true,
        ]);
        $ids = [$this->ana->uid, $this->bruno->uid];

        $this->postJson($this->batchUrl(), ['action' => 'copy', 'ids' => $ids, 'target_group' => $target->uid])->assertOk();
        $this->assertSame([(string) $this->ana->phone], Contacts::query()->where('group_id', $target->id)->pluck('phone')->map(fn ($p) => (string) $p)->all());

        $this->postJson($this->batchUrl(), ['action' => 'move', 'ids' => $ids, 'target_group' => $target->uid])->assertOk();
        $this->assertSame((int) $this->ana->contactGroup->id, (int) $this->bruno->fresh()->group_id, 'the foreign contact stays in its group');
    }

    public function test_a_stale_or_guessed_id_in_a_bulk_action_fails_safely(): void
    {
        $this->asStaff();

        $this->postJson($this->batchUrl(), ['action' => 'destroy', 'ids' => ['no-such-uid', '999999', $this->ana->uid]])->assertOk();
        $this->assertNull(Contacts::find($this->ana->id));
        $this->assertSame(2, Contacts::query()->whereIn('id', [$this->bruno->id, $this->wide->id])->count());

        // An unknown action is refused, not guessed.
        $this->postJson($this->batchUrl(), ['action' => 'nuke', 'ids' => [$this->bruno->uid]])->assertJson(['status' => 'error']);
    }

    public function test_the_group_export_leaves_out_contacts_at_unreachable_locations(): void
    {
        $this->asStaff();
        $group = $this->ana->contactGroup;

        $response = $this->post(route('customer.workspaces.businesses.contact.export', [$this->workspace->uid, $this->business->uid, $group->uid]), [
            'contact_fields' => ['Phone', 'First Name'],
        ]);

        $response->assertOk();
        $csv = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString($this->ana->uid, $csv);
        $this->assertStringContainsString($this->wide->uid, $csv);
        $this->assertStringNotContainsString($this->bruno->uid, $csv);
        $this->assertStringNotContainsString('14155550202', $csv);
    }

    // ----------------------------------------------------------- staff editing

    public function test_staff_can_edit_a_reachable_contact_and_not_a_foreign_one(): void
    {
        $this->asStaff();
        $group = $this->ana->contactGroup;
        $edit = fn (Contacts $contact) => route('customer.workspaces.businesses.contact.edit', [$this->workspace->uid, $this->business->uid, $group->uid, 'contact_id' => $contact->uid]);
        $update = route('customer.workspaces.businesses.contact.update', [$this->workspace->uid, $this->business->uid, $group->uid]);

        $this->get($edit($this->ana))->assertOk()->assertDontSee(__('locale.contacts.contact_not_found'));

        $this->post($update, ['contact_id' => $this->ana->uid, 'PHONE' => '14155550101', 'FIRST_NAME' => 'Anaïs-Renamed', 'LAST_NAME' => 'Alpha'])
            ->assertSessionHas('status', 'success');
        $this->assertSame('Anaïs-Renamed', $this->customField($this->ana->fresh(), 'FIRST_NAME'));

        $this->post($update, ['contact_id' => $this->bruno->uid, 'PHONE' => '14155550202', 'FIRST_NAME' => 'Hijacked', 'LAST_NAME' => 'Bravo'])
            ->assertSessionHas('status', 'error');
        $this->assertSame('Bruno', $this->customField($this->bruno->fresh(), 'FIRST_NAME'));
    }
}
