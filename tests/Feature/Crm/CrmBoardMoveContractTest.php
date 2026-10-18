<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\CrmOpportunityHistoryEvent;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\Exceptions\CrmStageConflictException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The board's instant-drag contract: the move endpoint answers with compact JSON
 * (never HTML), is a compare-and-set on the stage the board last saw, is safe to
 * repeat, and fails closed on anything that is not this Business's.
 */
class CrmBoardMoveContractTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private function move(\App\Models\Workspace $workspace, Business $business, CrmOpportunity $deal, array $payload, string $query = '')
    {
        return $this->postJson($this->crmRoute('opportunities.move', $workspace, $business, [$deal->uid]) . $query, $payload);
    }

    public function test_a_valid_move_answers_with_compact_json_and_canonical_totals_for_both_columns(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $new = $this->stageKeyed($pipeline, 'new_inquiry');
        $qualified = $this->stageKeyed($pipeline, 'qualified');
        $moving = $this->deal($business, $pipeline, title: 'Moving', valueMinor: 120000);
        $this->deal($business, $pipeline, title: 'Staying', valueMinor: 30050);
        $this->authenticateAs($customer);

        $response = $this->move($workspace, $business, $moving, ['stage' => $qualified->uid, 'from_stage' => $new->uid])->assertOk();

        $response->assertExactJson([
            'ok' => true,
            'moved' => true,
            'opportunity_uid' => $moving->uid,
            'stage_uid' => $qualified->uid,
            'stage' => ['uid' => $qualified->uid, 'name' => $qualified->name],
            'source_totals' => ['stage_uid' => $new->uid, 'count' => 1, 'value_minor' => 30050, 'label' => '1 · ' . $business->currency_code . ' 300.50'],
            'target_totals' => ['stage_uid' => $qualified->uid, 'count' => 1, 'value_minor' => 120000, 'label' => '1 · ' . $business->currency_code . ' 1,200'],
        ]);
        $this->assertStringNotContainsString('<', $response->getContent(), 'a move answers with data, not a rendered partial');
        $this->assertSame($qualified->id, (int) $moving->fresh()->stage_id);
    }

    public function test_the_totals_in_the_answer_are_counted_under_the_boards_filters(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $new = $this->stageKeyed($pipeline, 'new_inquiry');
        $qualified = $this->stageKeyed($pipeline, 'qualified');
        $moving = $this->deal($business, $pipeline, title: 'Garden wedding', valueMinor: 1000);
        $this->deal($business, $pipeline, title: 'Garden party', valueMinor: 2000);
        $this->deal($business, $pipeline, title: 'Loft', valueMinor: 4000);
        $this->authenticateAs($customer);

        $answer = $this->move($workspace, $business, $moving, ['stage' => $qualified->uid, 'from_stage' => $new->uid], '?q=Garden')->assertOk()->json();

        $this->assertSame(1, $answer['source_totals']['count'], 'only "Garden party" is left in the source column among the filtered cards');
        $this->assertSame(2000, $answer['source_totals']['value_minor']);
        $this->assertSame(1, $answer['target_totals']['count']);
    }

    public function test_repeating_the_same_request_is_safe_and_records_one_move(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $new = $this->stageKeyed($pipeline, 'new_inquiry');
        $qualified = $this->stageKeyed($pipeline, 'qualified');
        $deal = $this->deal($business, $pipeline);
        $this->authenticateAs($customer);
        $payload = ['stage' => $qualified->uid, 'from_stage' => $new->uid];

        $this->move($workspace, $business, $deal, $payload)->assertOk()->assertJson(['moved' => true]);
        // A network retry whose first attempt DID commit: from_stage is now stale, the target is already reached.
        $this->move($workspace, $business, $deal, $payload)->assertOk()->assertJson(['ok' => true, 'moved' => false, 'stage_uid' => $qualified->uid]);
        $this->move($workspace, $business, $deal, $payload)->assertOk()->assertJson(['moved' => false]);

        $this->assertSame($qualified->id, (int) $deal->fresh()->stage_id);
        $this->assertSame(1, CrmOpportunityHistory::query()->where('opportunity_id', $deal->id)->where('event', CrmOpportunityHistoryEvent::StageChanged->value)->count());
    }

    public function test_a_stale_expected_stage_is_refused_with_the_stage_the_server_has_and_changes_nothing(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $new = $this->stageKeyed($pipeline, 'new_inquiry');
        $qualified = $this->stageKeyed($pipeline, 'qualified');
        $negotiating = $this->stageKeyed($pipeline, 'negotiating');
        $deal = $this->deal($business, $pipeline);
        $this->authenticateAs($customer);

        // Tab one moves it; tab two still believes it is in New inquiry.
        $this->move($workspace, $business, $deal, ['stage' => $qualified->uid, 'from_stage' => $new->uid])->assertOk();
        $history = CrmOpportunityHistory::query()->where('opportunity_id', $deal->id)->count();

        $this->move($workspace, $business, $deal, ['stage' => $negotiating->uid, 'from_stage' => $new->uid])
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'code' => 'stage_conflict', 'opportunity_uid' => $deal->uid, 'stage_uid' => $qualified->uid]);

        $this->assertSame($qualified->id, (int) $deal->fresh()->stage_id);
        $this->assertSame($history, CrmOpportunityHistory::query()->where('opportunity_id', $deal->id)->count());
    }

    public function test_out_of_order_arrival_cannot_corrupt_the_stage(): void
    {
        // A->B was slow, B->C overtook it. Ordered per card by the client this cannot happen,
        // but the server must be safe even if it does: C is reached, then the late A->B is
        // refused (the deal is not in A) and cannot drag the deal back to B.
        [$customer, $business, $workspace] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $a = $this->stageKeyed($pipeline, 'new_inquiry');
        $b = $this->stageKeyed($pipeline, 'qualified');
        $c = $this->stageKeyed($pipeline, 'negotiating');
        $deal = $this->deal($business, $pipeline);
        $this->authenticateAs($customer);

        $this->move($workspace, $business, $deal, ['stage' => $c->uid, 'from_stage' => $b->uid])->assertStatus(409);
        $this->move($workspace, $business, $deal, ['stage' => $c->uid, 'from_stage' => $a->uid])->assertOk();
        $this->move($workspace, $business, $deal, ['stage' => $b->uid, 'from_stage' => $a->uid])->assertStatus(409)->assertJson(['stage_uid' => $c->uid]);

        $this->assertSame($c->id, (int) $deal->fresh()->stage_id);
    }

    public function test_a_move_without_an_expected_stage_still_works_as_the_detail_page_form_always_did(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $deal = $this->deal($business, $pipeline);
        $qualified = $this->stageKeyed($pipeline, 'qualified');
        $this->authenticateAs($customer);

        $this->move($workspace, $business, $deal, ['stage' => $qualified->uid])->assertOk()->assertJson(['ok' => true, 'moved' => true, 'source_totals' => null]);
        $this->post($this->crmRoute('opportunities.move', $workspace, $business, [$deal->uid]), ['stage' => $this->stageKeyed($pipeline, 'negotiating')->uid])->assertRedirect();
    }

    public function test_a_forged_target_or_expected_stage_fails_closed(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant('Harbor Lane Studios', 'Harbor');
        [, $other] = $this->crmTenant('Other Studio', 'Other');
        $pipeline = $this->standardPipeline($business);
        $deal = $this->deal($business, $pipeline);
        $foreign = $this->stageKeyed($this->standardPipeline($other), 'qualified');
        $second = app(\App\Library\Crm\CrmPipelineService::class)->createPipeline($business, 'Weddings');
        $siblingPipelineStage = $this->stageKeyed($second, 'qualified');
        $this->authenticateAs($customer);
        $own = $this->stageKeyed($pipeline, 'qualified');

        $this->move($workspace, $business, $deal, ['stage' => $foreign->uid])->assertNotFound();
        $this->move($workspace, $business, $deal, ['stage' => $own->uid, 'from_stage' => $foreign->uid])->assertNotFound();
        $this->move($workspace, $business, $deal, ['stage' => $siblingPipelineStage->uid])->assertNotFound();
        $this->move($workspace, $business, $deal, ['stage' => 'not-a-stage'])->assertNotFound();

        $this->assertSame($this->stageKeyed($pipeline, 'new_inquiry')->id, (int) $deal->fresh()->stage_id);
    }

    public function test_another_businesss_opportunity_is_not_found_even_with_a_valid_stage_of_the_callers_business(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant('Harbor Lane Studios', 'Harbor');
        [, $other] = $this->crmTenant('Other Studio', 'Other');
        $mineStage = $this->stageKeyed($this->standardPipeline($business), 'qualified');
        $theirs = $this->deal($other, $this->standardPipeline($other));
        $this->authenticateAs($customer);

        $this->move($workspace, $business, $theirs, ['stage' => $mineStage->uid])->assertNotFound();
        $this->assertNotSame($mineStage->id, (int) $theirs->fresh()->stage_id);
    }

    public function test_a_closed_opportunity_is_refused_with_a_422_and_a_message(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $deal = $this->deal($business, $pipeline);
        app(CrmOpportunityService::class)->markWon($deal);
        $this->authenticateAs($customer);

        $this->move($workspace, $business, $deal, ['stage' => $this->stageKeyed($pipeline, 'qualified')->uid])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Reopen this opportunity before moving it.']);
    }

    public function test_the_location_acl_still_applies_to_a_board_move(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        $pipeline = $this->standardPipeline($business);
        $deal = $this->deal($business, $pipeline);
        $deal->forceFill(['location_id' => $location->id])->save();
        $qualified = $this->stageKeyed($pipeline, 'qualified');

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        $this->authenticateAs($staff, ['view_contact', 'update_contact']);

        $this->move($workspace, $business, $deal, ['stage' => $qualified->uid])->assertNotFound();
        $this->assertNotSame($qualified->id, (int) $deal->fresh()->stage_id);
    }

    public function test_the_service_compare_and_set_is_checked_against_the_locked_row(): void
    {
        [, $business] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $a = $this->stageKeyed($pipeline, 'new_inquiry');
        $b = $this->stageKeyed($pipeline, 'qualified');
        $c = $this->stageKeyed($pipeline, 'negotiating');
        $service = app(CrmOpportunityService::class);
        $deal = $this->deal($business, $pipeline);

        $this->assertTrue($service->moveToStage($deal, $b, null, $a));
        $this->assertFalse($service->moveToStage($deal, $b, null, $a), 'already there: a repeat, not a conflict');

        try {
            $service->moveToStage(CrmOpportunity::query()->find($deal->id), $c, null, $a);
            $this->fail('expected a conflict');
        } catch (CrmStageConflictException $conflict) {
            $this->assertSame($b->id, $conflict->currentStageId);
        }

        $this->assertTrue($service->moveToStage($deal, $c), 'no expectation = unconditional, as before');
    }

    public function test_the_board_renders_what_the_browser_needs_to_update_in_place_and_never_reloads(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $deal = $this->deal($business, $pipeline, title: 'Kitchen', valueMinor: 250000);
        $this->deal($business, $pipeline, title: 'Garage', valueMinor: 50000);
        $this->authenticateAs($customer);

        $html = $this->get($this->crmRoute('board', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-value-minor="250000"', $html);
        $this->assertMatchesRegularExpression('/data-column="' . preg_quote($this->stageKeyed($pipeline, 'new_inquiry')->uid, '/') . '"(?:\s+data-tone="\d+")?\s+data-count="2" data-value-minor="300000"/', $html);
        $this->assertSame(1, substr_count($html, 'data-uid="' . $deal->uid . '"'), 'exactly one card per opportunity');
        $this->assertStringContainsString('js/crm/board-moves.js', $html);
        $this->assertStringContainsString('js/crm/board.js', $html);

        foreach (['location.reload', 'refreshBoard', 'AsyncRegion.load', 'window.location.assign'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html . file_get_contents(public_path('js/crm/board.js')), "a move must never trigger: {$forbidden}");
        }
    }
}
