<?php

namespace Tests\Feature\Documents\Editor;

use App\Library\Catalog\CatalogItemManager;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Contract 17B §3 — the canonical commerce chain behind the editor: lines, the
 * payment-plan compiler, catalog selection/creation and the frozen send.
 */
class DocumentEditorCommerceTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;
    use CreatesDocumentsTestData;
    use CreatesCatalogHttpFixtures;
    use EditorTestHelpers;

    /** @return array<int, array<string, mixed>> */
    private function schedule(BusinessDocument $document): array
    {
        return $this->draftVersion($document)->paymentScheduleItems()->orderBy('sequence')->get()
            ->map(fn ($i) => ['kind' => $i->kind->value ?? $i->kind, 'amount' => (int) $i->amount_minor, 'due_at' => $i->due_at?->toDateTimeString()])->all();
    }

    private function plan(array $tenant, BusinessDocument $document, array $plan, int $expectStatus = 200)
    {
        return $this->putJson($this->ed('plan', $tenant, $document), $plan + ['expected_lock_version' => $this->lock($document)])->assertStatus($expectStatus);
    }

    public function test_a_full_payment_plan_compiles_to_one_schedule_item(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant);

        $response = $this->plan($tenant, $document, ['structure' => 'full'])
            ->assertJsonPath('plan.structure', 'full')->assertJsonPath('plan_invalid', false)
            ->assertJsonPath('schedule.0.amount_minor', 100000)->assertJsonPath('schedule.0.due_label', 'Due after signing');
        $this->assertSame($this->lock($document), $response->json('lock_version'));
        $this->assertSame([['kind' => 'full', 'amount' => 100000, 'due_at' => null]], $this->schedule($document));
        $this->assertSame('full', $this->draftVersion($document)->content['payment_plan']['structure']);
    }

    public function test_a_due_date_is_the_end_of_that_day_in_the_business_timezone(): void
    {
        $tenant = $this->editorTenant();
        DB::table('businesses')->where('id', $tenant['business']->id)->update(['timezone' => 'America/New_York']);
        $document = $this->documentWithLine($tenant);

        $this->plan($tenant, $document, ['structure' => 'full', 'full_due' => 'date', 'full_due_date' => '2027-03-15'])
            ->assertJsonPath('schedule.0.due_date', '2027-03-15')->assertJsonPath('schedule.0.due_label', 'Due 15 March 2027');

        $expected = Carbon::parse('2027-03-15 23:59:59', 'America/New_York')->setTimezone(config('app.timezone'))->toDateTimeString();
        $this->assertSame($expected, $this->schedule($document)[0]['due_at']);
    }

    public function test_a_deposit_plan_splits_the_total_and_the_balance_is_always_total_minus_deposit(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant); // 1,000.00

        $this->plan($tenant, $document, ['structure' => 'deposit', 'deposit' => '250.00'])
            ->assertJsonPath('plan.deposit_minor', 25000)->assertJsonPath('plan.deposit_input', '250.00')
            ->assertJsonPath('plan.balance_minor', 75000)->assertJsonPath('schedule.1.amount_formatted', 'USD 750.00');
        $this->assertSame([
            ['kind' => 'deposit', 'amount' => 25000, 'due_at' => null],
            ['kind' => 'balance', 'amount' => 75000, 'due_at' => null],
        ], $this->schedule($document));

        $this->plan($tenant, $document, [
            'structure' => 'deposit', 'deposit' => '300', 'full_due' => 'date', 'full_due_date' => '2027-01-10',
            'balance_due' => 'date', 'balance_due_date' => '2027-02-10',
        ])->assertJsonPath('schedule.0.due_date', '2027-01-10')->assertJsonPath('schedule.1.due_date', '2027-02-10')
            ->assertJsonPath('schedule.1.amount_minor', 70000);
    }

    public function test_invalid_plans_are_rejected_with_field_errors_and_leave_the_schedule_alone(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant); // 1,000.00
        $this->plan($tenant, $document, ['structure' => 'full']);
        $before = $this->schedule($document);
        $lock = $this->lock($document);

        foreach ([
            [['structure' => 'deposit', 'deposit' => '0'], 'deposit'],
            [['structure' => 'deposit', 'deposit' => '0.00'], 'deposit'],
            [['structure' => 'deposit'], 'deposit'],
            [['structure' => 'deposit', 'deposit' => '1000.00'], 'deposit'],
            [['structure' => 'deposit', 'deposit' => '1500.00'], 'deposit'],
            [['structure' => 'deposit', 'deposit' => 'abc'], 'deposit'],
            [['structure' => 'deposit', 'deposit' => '10.001'], 'deposit'],
            [['structure' => 'deposit', 'deposit' => '-5'], 'deposit'],
            [['structure' => 'full', 'full_due' => 'date', 'full_due_date' => '2027-02-30'], 'full_due_date'],
            [['structure' => 'full', 'full_due' => 'date'], 'full_due_date'],
            [['structure' => 'full', 'full_due' => 'someday'], 'full_due'],
            [['structure' => 'monthly'], 'structure'],
        ] as [$payload, $field]) {
            $this->plan($tenant, $document, $payload, 422)->assertJsonPath('status', 'invalid')->assertJsonStructure(['errors' => [$field]]);
        }

        $this->assertSame($before, $this->schedule($document));
        $this->assertSame($lock, $this->lock($document));
    }

    public function test_a_past_due_date_is_allowed(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant);

        $this->plan($tenant, $document, ['structure' => 'full', 'full_due' => 'date', 'full_due_date' => '2001-01-01'])
            ->assertJsonPath('schedule.0.due_date', '2001-01-01');
    }

    public function test_a_plan_before_any_product_is_stored_but_a_deposit_needs_a_total(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->blankDocument($tenant);

        $this->plan($tenant, $document, ['structure' => 'deposit', 'deposit' => '50.00'], 422)->assertJsonStructure(['errors' => ['deposit']]);
        $this->plan($tenant, $document, ['structure' => 'full'])->assertJsonPath('schedule', []);

        // The first product then produces the schedule on its own.
        $this->postJson($this->ed('lines.custom', $tenant, $document), ['name' => 'Work', 'price' => '80.00', 'expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('totals.total_minor', 8000);
        $this->assertSame([['kind' => 'full', 'amount' => 8000, 'due_at' => null]], $this->schedule($document));
    }

    public function test_the_plan_survives_line_changes_and_the_schedule_is_reapplied(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant); // 1,000.00
        $this->plan($tenant, $document, ['structure' => 'deposit', 'deposit' => '250.00']);

        // Add a line: total 1,500.00, balance follows.
        $added = $this->postJson($this->ed('lines.custom', $tenant, $document), ['name' => 'Extra', 'price' => '500.00', 'quantity' => 1, 'expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('totals.total_minor', 150000)->assertJsonPath('plan_invalid', false);
        $this->assertSame([['kind' => 'deposit', 'amount' => 25000, 'due_at' => null], ['kind' => 'balance', 'amount' => 125000, 'due_at' => null]], $this->schedule($document));
        $extraUid = $added->json('lines.1.uid');

        // Quantity change.
        $this->patchJson($this->ed('lines.quantity', $tenant, $document, [$extraUid]), ['quantity' => 2, 'expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('totals.total_minor', 200000)->assertJsonPath('lines.1.line_total_minor', 100000);
        $this->assertSame(175000, $this->schedule($document)[1]['amount']);

        // Reorder keeps the plan.
        $uids = array_reverse([$added->json('lines.0.uid'), $extraUid]);
        $this->putJson($this->ed('lines.order', $tenant, $document), ['line_uids' => $uids, 'expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('lines.0.uid', $uids[0]);

        // Remove it again.
        $this->deleteJson($this->ed('lines.destroy', $tenant, $document, [$extraUid]), ['expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('totals.total_minor', 100000);
        $this->assertSame([['kind' => 'deposit', 'amount' => 25000, 'due_at' => null], ['kind' => 'balance', 'amount' => 75000, 'due_at' => null]], $this->schedule($document));
    }

    public function test_when_the_total_drops_below_the_deposit_the_schedule_is_cleared_and_flagged(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant, 40000); // 400.00
        $second = $this->postJson($this->ed('lines.custom', $tenant, $document), ['name' => 'Second', 'price' => '600.00', 'expected_lock_version' => $this->lock($document)])->assertOk();
        $this->plan($tenant, $document, ['structure' => 'deposit', 'deposit' => '900.00'])->assertJsonPath('plan_invalid', false);

        $response = $this->deleteJson($this->ed('lines.destroy', $tenant, $document, [$second->json('lines.1.uid')]), ['expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('plan_invalid', true)->assertJsonPath('schedule', [])->assertJsonPath('totals.total_minor', 40000);
        $this->assertNotEmpty($response->json('plan_error'));
        $this->assertSame([], $this->schedule($document), 'no inconsistent schedule is left behind');
        $this->assertSame(90000, $this->draftVersion($document)->content['payment_plan']['deposit_minor'], 'the intent is kept');

        // A document without a schedule cannot be sent.
        try {
            app(DocumentManager::class)->send($document->refresh());
            $this->fail('A flagged plan must block send.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('schedule', strtolower($e->errors()['document'][0]));
        }

        // When the total rises again the same intent applies cleanly.
        $this->postJson($this->ed('lines.custom', $tenant, $document), ['name' => 'Third', 'price' => '600.00', 'expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('plan_invalid', false)->assertJsonPath('schedule.0.amount_minor', 90000)->assertJsonPath('schedule.1.amount_minor', 10000);
    }

    public function test_a_hand_written_schedule_supersedes_the_stored_plan(): void
    {
        $tenant = $this->editorTenant();
        $manager = app(DocumentManager::class);
        $document = $this->documentWithLine($tenant);
        $this->plan($tenant, $document, ['structure' => 'deposit', 'deposit' => '250.00']);

        $manager->setSchedule($document, [['kind' => 'full', 'amount_minor' => 100000, 'currency_code' => 'USD']]);
        $this->assertArrayNotHasKey('payment_plan', $this->draftVersion($document)->content);
        $manager->addCustomLine($document, 'More', null, 1, 100);
        $this->assertSame([], $this->schedule($document), 'a line change still clears a hand-written schedule, exactly as before');
    }

    public function test_a_catalog_item_can_be_created_in_the_editor_and_then_selected(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->documentWithLine($tenant, 100);
        $other = $this->sendableTenant('Theirs');
        $this->authenticateAs($tenant['customer']);
        $this->catalogItem($other['business'], 'Foreign Hidden Package');
        $archived = $this->catalogItem($tenant['business'], 'Archived Package');
        app(CatalogItemManager::class)->archive($tenant['business'], $archived);
        $quote = $this->catalogItem($tenant['business'], 'Quote Only Package', ['price_minor' => null, 'currency_code' => null]);

        $created = $this->postJson($this->ed('catalog.store', $tenant, $document), ['type' => 'product', 'name' => 'Studio session', 'description' => 'Two hours', 'price' => '250.00'])
            ->assertOk()->assertJsonPath('item.name', 'Studio session')->assertJsonPath('item.price_minor', 25000)
            ->assertJsonPath('item.price_formatted', 'USD 250.00')->assertJsonPath('item.currency_code', 'USD')->assertJsonPath('item.quote_only', false);

        $search = $this->getJson($this->ed('catalog.search', $tenant, $document))->assertOk();
        $names = array_column($search->json('items'), 'name');
        $this->assertContains('Studio session', $names);
        $this->assertContains('Quote Only Package', $names);
        $this->assertNotContains('Archived Package', $names);
        $this->assertNotContains('Foreign Hidden Package', $names);
        $this->assertSame(['Studio session'], array_column($this->getJson($this->ed('catalog.search', $tenant, $document) . '?q=studio')->json('items'), 'name'));

        $this->postJson($this->ed('lines.catalog', $tenant, $document), ['catalog_item_uid' => $created->json('item.uid'), 'quantity' => 2, 'expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('lines.1.name', 'Studio session')->assertJsonPath('lines.1.line_total_minor', 50000)->assertJsonPath('totals.total_minor', 50100);

        // A quote-only item needs the document to state its price.
        $this->postJson($this->ed('lines.catalog', $tenant, $document), ['catalog_item_uid' => $quote->uid, 'expected_lock_version' => $this->lock($document)])->assertStatus(422);
        $this->postJson($this->ed('lines.catalog', $tenant, $document), ['catalog_item_uid' => $quote->uid, 'price' => '75.00', 'expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('totals.total_minor', 57600);

        // Invalid creation is a 422 with nothing created.
        $count = DB::table('catalog_items')->where('business_id', $tenant['business']->id)->count();
        $this->postJson($this->ed('catalog.store', $tenant, $document), ['type' => 'product', 'name' => 'Bad', 'price' => '12.345'])->assertStatus(422);
        $this->assertSame($count, DB::table('catalog_items')->where('business_id', $tenant['business']->id)->count());
    }

    public function test_frozen_pricing_survives_a_later_catalog_price_edit_after_send(): void
    {
        $tenant = $this->editorTenant();
        $manager = app(DocumentManager::class);
        $document = $this->blankDocument($tenant);
        $item = $this->catalogItem($tenant['business'], 'Garden Package', ['price_minor' => 100000]);
        $manager->saveBlocks($document, $this->standardBlocks());

        $this->postJson($this->ed('lines.catalog', $tenant, $document), ['catalog_item_uid' => $item->uid, 'quantity' => 1, 'expected_lock_version' => $this->lock($document)])->assertOk();
        $this->plan($tenant, $document, ['structure' => 'deposit', 'deposit' => '250.00']);
        [$document] = $this->sendAndCaptureToken($document->refresh());

        app(CatalogItemManager::class)->update($tenant['business'], $item, ['price_minor' => 250000]);

        $issued = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $this->assertSame('issued', $issued->state->value);
        $line = $issued->lineItems()->firstOrFail();
        $this->assertSame(100000, (int) $line->unit_price_minor);
        $this->assertSame(100000, (int) $issued->total_minor);
        $this->assertSame([25000, 75000], $issued->paymentScheduleItems()->orderBy('sequence')->pluck('amount_minor')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('deposit', $issued->content['payment_plan']['structure'], 'the intent is part of the hashed content');
        $this->assertSame(64, strlen($issued->content_hash));
        $this->assertArrayHasKey('parties', $issued->content);
    }

    public function test_the_signature_still_binds_the_displayed_version(): void
    {
        $tenant = $this->editorTenant();
        $manager = app(DocumentManager::class);
        $document = $this->documentWithLine($tenant);
        $manager->setPaymentPlan($document, ['structure' => 'full']);
        [$document] = $this->sendAndCaptureToken($document);
        $v1 = BusinessDocumentVersion::findOrFail($document->current_version_id);

        // The owner revises through the editor and re-sends.
        $manager->revise($document, $tenant['customer']->user);
        $this->putJson($this->ed('blocks', $tenant, $document), ['blocks' => $this->standardBlocks(), 'expected_lock_version' => $this->lock($document)])->assertOk();
        [$document] = $this->sendAndCaptureToken($document->refresh());
        $v2 = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $this->assertNotSame($v1->uid, $v2->uid);

        $evidence = ['signer_name' => 'Pat Rivera', 'signer_email' => 'client@example.test', 'typed_name' => 'Pat Rivera', 'ip_address' => '203.0.113.9', 'user_agent' => 'phpunit'];
        try {
            $manager->sign($document, $evidence + ['displayed_version_uid' => (string) $v1->uid]);
            $this->fail('A signature on the version the signer did not see must be refused.');
        } catch (ValidationException) {
            $this->assertSame(0, $document->signature()->count());
        }

        $signature = $manager->sign($document, $evidence + ['displayed_version_uid' => (string) $v2->uid]);
        $this->assertSame((int) $v2->id, (int) $signature->business_document_version_id);
        $this->assertSame($v2->content_hash, $signature->signed_content_hash);
    }
}
