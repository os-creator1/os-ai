<?php

namespace Tests\Feature\Documents\Acceptance;

use App\Events\DocumentSent;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPayment;
use App\Models\BusinessDocumentVersion;
use App\Models\ContactGroupFields;
use App\Models\ContactsCustomField;
use App\Models\DocumentTemplate;
use App\Notifications\Documents\DocumentBalanceRequestNotification;
use App\Notifications\Documents\DocumentIssuedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\Feature\Documents\PlatformTemplates\PlatformTemplateTestHelpers;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;
use Tests\TestCase;

/**
 * Contract 17B FINAL ACCEPTANCE — the whole Proposal / Contract editor journey,
 * driven through the REAL HTTP endpoints (template library, new-proposal store,
 * editor JSON API, send, the public secure link, sign, pay start, the payment
 * webhook finalizer, save-as-template). Only the outside world is faked: the
 * Stripe Connect gateway, the mail notification channel, and the Business SMS
 * seam (CampaignRepository::quickSend). DocumentManager is the production one.
 */
class ProposalEditorJourneyTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments, CreatesAutomationFixtures {
        SendsDocuments::platformAdminId insteadof CreatesAutomationFixtures;
        SendsDocuments::ensureRequiredAppConfigRowsExist insteadof CreatesAutomationFixtures;
    }
    use CreatesDocumentsTestData;
    use CreatesCatalogHttpFixtures;
    use CreatesPayableDocuments;
    use PlatformTemplateTestHelpers;
    use BuildsActionWorkflows;

    private const CONTACT_FIRST = 'Marisol';
    private const CONTACT_LAST = 'Quenby';
    private const CONTACT_EMAIL = 'marisol.quenby@example.test';
    private const PACKAGE = 'Zephyr Deluxe Package';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->ensureRequiredAppConfigRowsExist();
        $this->owner();
        $this->bindFakeGateway();
    }

    // ---- fixtures -------------------------------------------------------------------------

    /** @return array{0: array<string, mixed>, 1: DocumentTemplate} a Photo Booth tenant + the recommended platform template */
    private function photoBoothWorld(): array
    {
        $blueprint = $this->seedPhotoBoothBlueprint();
        $platform = $this->livePlatformTemplate('Photo Booth Proposal');
        $this->assignAndPublish($platform, $blueprint);

        $tenant = $this->photoBoothTenant('Snapbooth Events');
        DB::table('businesses')->where('id', $tenant['business']->id)->update(['timezone' => 'America/New_York']);
        $tenant['business'] = $tenant['business']->fresh();
        $this->setContactIdentity($tenant, self::CONTACT_FIRST, self::CONTACT_LAST, self::CONTACT_EMAIL);
        $tenant['contact'] = $tenant['contact']->fresh();
        $this->chargeReadyConnection($tenant['business']);

        return [$tenant, $platform];
    }

    /** A Contact's name/email live in its group's custom fields (FIRST_NAME / LAST_NAME / EMAIL). */
    private function setContactIdentity(array $tenant, string $first, string $last, string $email): void
    {
        foreach (['FIRST_NAME' => $first, 'LAST_NAME' => $last, 'EMAIL' => $email] as $tag => $value) {
            $field = ContactGroupFields::firstOrCreate(
                ['contact_group_id' => $tenant['contact']->group_id, 'tag' => $tag],
                ['label' => ucfirst(strtolower(str_replace('_', ' ', $tag))), 'type' => $tag === 'EMAIL' ? 'email' : 'text'],
            );
            ContactsCustomField::updateOrCreate(['field_id' => $field->id, 'contact_id' => $tenant['contact']->id], ['value' => $value]);
        }
    }

    private function appointment(array $tenant, Carbon $start): void
    {
        $bookingType = DB::table('booking_types')->insertGetId([
            'uid' => (string) Str::uuid(), 'public_booking_uuid' => (string) Str::uuid(),
            'business_location_id' => $tenant['location']->id, 'name' => 'Event walkthrough', 'duration_minutes' => 30,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('appointments')->insert([
            'uid' => (string) Str::uuid(), 'business_location_id' => $tenant['location']->id, 'booking_type_id' => $bookingType,
            'staff_user_id' => $tenant['customer']->user->id, 'contact_id' => $tenant['contact']->id,
            'status' => 'scheduled', 'start_at' => $start, 'end_at' => $start->copy()->addMinutes(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Start a proposal from the recommended platform template through the real new-proposal endpoint. */
    private function startFromTemplate(array $tenant, DocumentTemplate $platform, string $title = 'Quenby wedding booth'): BusinessDocument
    {
        $this->docsStore($tenant, ['template_uid' => $platform->uid, 'title' => $title])->assertRedirect();

        return BusinessDocument::where('title', $title)->firstOrFail();
    }

    private function endOfDay(string $date): string
    {
        return Carbon::parse($date . ' 23:59:59', 'America/New_York')->setTimezone(config('app.timezone'))->toDateTimeString();
    }

    /** @return array<int, array{kind: string, amount: int, due_at: ?string}> */
    private function schedule(BusinessDocument $document, ?BusinessDocumentVersion $version = null): array
    {
        $version ??= $this->draftVersion($document);

        return $version->paymentScheduleItems()->orderBy('sequence')->get()
            ->map(fn ($i) => ['kind' => $i->kind->value ?? $i->kind, 'amount' => (int) $i->amount_minor, 'due_at' => $i->due_at?->toDateTimeString()])->all();
    }

    private function plan(array $tenant, BusinessDocument $document, array $plan, int $status = 200)
    {
        return $this->putJson($this->ed('plan', $tenant, $document), $plan + ['expected_lock_version' => $this->lock($document)])->assertStatus($status);
    }

    private function addPackage(array $tenant, BusinessDocument $document, int $priceMinor = 123456): \App\Models\CatalogItem
    {
        $item = $this->catalogItem($tenant['business'], self::PACKAGE, ['price_minor' => $priceMinor]);

        // The picker lists it; selecting it adds a catalog-backed line.
        $names = array_column($this->getJson($this->ed('catalog.search', $tenant, $document))->assertOk()->json('items'), 'name');
        $this->assertContains(self::PACKAGE, $names);
        $this->postJson($this->ed('lines.catalog', $tenant, $document), ['catalog_item_uid' => $item->uid, 'quantity' => 1, 'expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('totals.total_minor', $priceMinor)->assertJsonPath('lines.0.name', self::PACKAGE);

        return $item;
    }

    /** The signed-in Business owner (the editor side). */
    private function asBusinessOwner(array $tenant): void
    {
        $this->authenticateAs($tenant['customer']);
    }

    /** The anonymous recipient: no session, no user (a fresh rate-limit bucket keyed by IP). */
    private function asRecipient(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function token(object $job): string
    {
        return (string) (new ReflectionProperty($job, 'plaintextToken'))->getValue($job);
    }

    /** The public HTML with the per-request CSRF token removed, so two renders can be compared. */
    private function stable(string $html): string
    {
        return (string) preg_replace('#name="_token" value="[^"]*"#', 'name="_token" value="X"', $html);
    }

    private function sign($document, string $token, string $html)
    {
        $this->assertSame(1, preg_match('#name="displayed_version_uid" value="([^"]+)"#', $html, $m), 'the page carries the displayed version uid');

        return $this->post($this->signUrl($document, $token), [
            'displayed_version_uid' => $m[1], 'signer_name' => 'Marisol Quenby', 'signer_email' => self::CONTACT_EMAIL, 'typed_name' => 'Marisol Quenby',
        ]);
    }

    // ---- journey 1: deposit ------------------------------------------------------------------

    public function test_journey_one_deposit_proposal_from_a_recommended_niche_template_through_to_a_paid_deposit_and_a_saved_template(): void
    {
        Notification::fake();
        Event::fake([DocumentSent::class]);
        [$tenant, $platform] = $this->photoBoothWorld();
        $platformBefore = $this->templateHash($platform);
        $manager = app(DocumentManager::class);

        // 1-2. The Business sees the template as Recommended and starts a proposal from it for the Contact.
        $this->assertSame([$platform->uid], $this->recommendedUids($tenant['business']));
        $library = $this->get($this->tpl('index', $tenant))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="recommended-card"', $library);
        $this->assertStringContainsString('Photo Booth Proposal', $library);

        $this->appointment($tenant, Carbon::parse('2030-06-15 18:00:00', 'UTC')); // 14:00 on the 15th in New York
        $document = $this->startFromTemplate($tenant, $platform);
        $this->assertSame('draft', $document->status->value);
        $this->assertSame($tenant['contact']->id, $document->contact_id);
        $blockTypes = array_column($this->draftVersion($document)->content['blocks'], 'type');
        $this->assertContains('product_list', $blockTypes);
        $this->assertContains('signature', $blockTypes);
        $this->assertSame(0, $this->draftVersion($document)->lineItems()->count(), 'the template carries no product');
        $this->assertSame($platformBefore, $this->templateHash($platform), 'using a platform template never mutates it');

        // The editor shell and the live preview resolve the Contact.
        $boot = $this->pageBootstrap($this->get($this->ed('edit', $tenant, $document))->assertOk()->getContent());
        $this->assertSame('document', $boot['mode']);
        $this->assertSame(self::CONTACT_FIRST . ' ' . self::CONTACT_LAST, $boot['contact']['name']);
        $this->get($this->ed('preview', $tenant, $document))->assertOk()->assertSee('Proposal for ' . self::CONTACT_FIRST);

        // 3. Product: an existing Business catalog package.
        $item = $this->addPackage($tenant, $document);

        // 4. Deposit: invalid amounts are rejected and leave the schedule alone; a valid one splits the total.
        $lock = $this->lock($document);
        foreach (['0', '0.00', '-5', '1234.56', '1234.57', '5000', 'abc', ''] as $bad) {
            $this->plan($tenant, $document, ['structure' => 'deposit', 'deposit' => $bad], 422)->assertJsonPath('status', 'invalid')->assertJsonStructure(['errors' => ['deposit']]);
        }
        $this->assertSame([], $this->schedule($document));
        $this->assertSame($lock, $this->lock($document), 'a rejected plan does not bump the lock');

        $this->plan($tenant, $document, ['structure' => 'deposit', 'deposit' => '345.67'])
            ->assertJsonPath('plan.deposit_minor', 34567)->assertJsonPath('plan.balance_minor', 123456 - 34567)->assertJsonPath('plan_invalid', false);
        $this->assertSame([
            ['kind' => 'deposit', 'amount' => 34567, 'due_at' => null],
            ['kind' => 'balance', 'amount' => 88889, 'due_at' => null],
        ], $this->schedule($document));

        // 5. contact.dates prefills from the appointment; a manual date replaces it in the compiled schedule.
        $dates = $this->getJson($this->ed('contact.dates', $tenant, $document))->assertOk()->json('dates');
        $this->assertSame('appointment', $dates[0]['source']);
        $this->assertSame('2030-06-15', $dates[0]['date']);
        $this->plan($tenant, $document, ['structure' => 'deposit', 'deposit' => '345.67', 'balance_due' => 'date', 'balance_due_date' => $dates[0]['date']])
            ->assertJsonPath('schedule.1.due_date', '2030-06-15');
        $this->assertSame($this->endOfDay('2030-06-15'), $this->schedule($document)[1]['due_at']);

        $this->plan($tenant, $document, ['structure' => 'deposit', 'deposit' => '345.67', 'balance_due' => 'date', 'balance_due_date' => '2030-06-01'])
            ->assertJsonPath('schedule.1.due_date', '2030-06-01')->assertJsonPath('schedule.1.due_label', 'Due 1 June 2030');
        $this->assertSame($this->endOfDay('2030-06-01'), $this->schedule($document)[1]['due_at'], 'the manual date wins, end of day in the Business timezone');
        $this->assertNull($this->schedule($document)[0]['due_at'], 'the deposit is due after signing');

        // 6. Edit / style with the lock_version; a stale tab is a 409 and writes nothing.
        $blocks = $this->draftVersion($document)->content['blocks'];
        $styled = array_map(function (array $block) {
            if ($block['type'] === 'heading') {
                $block['data']['align'] = 'center';
                $block['data']['runs'] = [['t' => 'Your photo booth for ', 'b' => true], ['merge' => 'contact.first_name'], ['t' => '!', 'b' => true]];
            }

            return $block;
        }, $blocks);
        array_splice($styled, 1, 0, [['id' => 'j-note', 'type' => 'text', 'data' => ['align' => 'left', 'runs' => [['t' => 'Unlimited prints, ', 'i' => true], ['t' => 'props and an attendant.']]]]]);

        $stale = $this->lock($document);
        $saved = $this->putJson($this->ed('blocks', $tenant, $document), ['blocks' => $styled, 'title' => 'Quenby wedding booth', 'expected_lock_version' => $stale])
            ->assertOk()->assertJsonPath('lock_version', $stale + 1);
        $this->assertSame($stale + 1, $this->lock($document));
        $this->putJson($this->ed('blocks', $tenant, $document), ['blocks' => [], 'expected_lock_version' => $stale])
            ->assertStatus(409)->assertJsonPath('status', 'conflict')->assertJsonPath('lock_version', $stale + 1);
        $this->assertCount(count($styled), $this->draftVersion($document)->content['blocks'], 'the stale tab wrote nothing');
        $this->assertSame('center', $this->draftVersion($document)->content['blocks'][0]['data']['align']);
        $this->assertSame($saved->json('lock_version'), $this->lock($document));

        // 7. Send by email AND text: ONE transition, ONE token, ONE DocumentSent, both channels.
        $this->sendableChannel($tenant['business']);
        $core = $this->captureSendCore(1);
        $response = $this->postJson($this->ed('send', $tenant, $document), [
            'channels' => ['email', 'sms'], 'recipient_email' => self::CONTACT_EMAIL, 'recipient_phone' => '+14155550177',
            'message' => 'Your proposal is ready.', 'expected_lock_version' => $this->lock($document),
        ])->assertOk()->assertJsonPath('document_status', 'sent')->assertJsonPath('delivery.email.status', 'queued')->assertJsonPath('delivery.sms.status', 'queued');
        $this->assertArrayHasKey('lock_version', $response->json());

        $document = $document->refresh();
        $this->assertSame('sent', $document->status->value);
        $this->assertNotNull($document->access_token_hash);
        Event::assertDispatchedTimes(DocumentSent::class, 1);
        $this->assertSame(1, $core->count(), 'one text through the Business SMS seam');
        $this->assertSame((int) $tenant['business']->id, (int) $core->lastPayload()['business_id']);
        $this->assertNotNull($document->link_delivered_at);
        $this->assertNotNull($document->sms_link_delivered_at);
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
        $tokens = [];
        Notification::assertSentOnDemand(DocumentIssuedNotification::class, function ($notification) use (&$tokens) {
            $tokens[] = $this->token($notification);

            return true;
        });
        $this->assertSame(1, preg_match('#/documents/' . $document->uid . '/([A-Za-z0-9]{64})$#', $core->lastPayload()['message'], $m));
        $this->assertSame($tokens[0], $m[1], 'the email and the text carry the SAME single token');
        $this->assertTrue(Hash::check($tokens[0], (string) $document->access_token_hash));
        $token = $tokens[0];
        $issued = BusinessDocumentVersion::findOrFail($document->current_version_id);
        $hashAtSend = $issued->content_hash;
        $this->assertSame(1, $document->versions()->where('state', 'issued')->count());

        // Negative: a sent document refuses every editor write.
        $this->putJson($this->ed('blocks', $tenant, $document), ['blocks' => $styled, 'expected_lock_version' => 1])->assertStatus(422)->assertJsonPath('status', 'invalid');
        $this->putJson($this->ed('plan', $tenant, $document), ['structure' => 'full', 'expected_lock_version' => 1])->assertStatus(422);
        $this->postJson($this->ed('lines.custom', $tenant, $document), ['name' => 'Sneaky', 'price' => '1.00', 'expected_lock_version' => 1])->assertStatus(422);
        // A repeated send is the idempotent replay (17A): no second token, no second delivery, no second event.
        $this->postJson($this->ed('send', $tenant, $document), ['channels' => ['email', 'sms'], 'expected_lock_version' => 1])->assertOk();
        $this->assertSame((string) $document->access_token_hash, (string) $document->refresh()->access_token_hash);
        $this->assertSame(1, $core->count());
        Notification::assertSentOnDemandTimes(DocumentIssuedNotification::class, 1);
        Event::assertDispatchedTimes(DocumentSent::class, 1);
        $this->assertSame($hashAtSend, BusinessDocumentVersion::findOrFail($document->current_version_id)->content_hash);

        // 8. The recipient opens the secure link (anonymous: the public routes and the owner's editor
        // calls must not share one rate-limit bucket, which they would for a signed-in user).
        $this->asRecipient();
        $page = $this->get($this->publicUrl($document, $token))->assertOk();
        $html = $page->getContent();
        $page->assertSee('Snapbooth Events')
            ->assertSee('<h1 class="doc-align-center"><b>Your photo booth for </b>' . self::CONTACT_FIRST . '<b>!</b></h1>', false)
            ->assertSee('Unlimited prints, ')
            ->assertSee(self::PACKAGE)
            ->assertSee('1,234.56');
        $this->assertStringContainsString('data-role="signature-slot"', $html);
        $this->assertStringContainsString('data-role="sign-form"', $html);
        $this->assertStringContainsString('data-role="lines"', $html);
        $this->assertStringContainsString('data-role="payment-section"', $html);
        // The page owns its background: dark text on a UA-dark canvas was unreadable around the paper.
        $this->assertStringContainsString('color-scheme:light', $html);
        $this->assertStringContainsString('background:#eceae5', $html);
        $this->assertStringContainsString('data-kind="deposit"', $html);
        $this->assertStringContainsString('data-kind="balance"', $html);
        $this->assertStringContainsString('345.67', $html);
        $this->assertStringContainsString('888.89', $html);
        $this->assertStringContainsString('Due 1 June 2030', $html);

        // 9. Tamper: later catalog, contact and Business edits never touch the issued render.
        app(CatalogItemManager::class)->update($tenant['business'], $item, ['price_minor' => 999999, 'name' => 'Renamed Package']);
        $this->setContactIdentity($tenant, 'Zorbala', 'Newname', 'new@example.test');
        DB::table('businesses')->where('id', $tenant['business']->id)->update(['name' => 'Renamed Business LLC']);
        $after = $this->get($this->publicUrl($document, $token))->assertOk()->getContent();
        $this->assertSame($this->stable($html), $this->stable($after), 'the issued render is byte-stable');
        $this->assertStringNotContainsString('Renamed', $after);
        $this->assertStringNotContainsString('Zorbala', $after);
        $this->assertStringNotContainsString('9,999.99', $after);
        $this->assertSame($hashAtSend, BusinessDocumentVersion::findOrFail($document->current_version_id)->content_hash);
        $this->assertSame(123456, (int) BusinessDocumentVersion::findOrFail($document->current_version_id)->total_minor);

        // The signature binds the displayed version uid + content hash.
        $signed = $this->sign($document, $token, $after);
        $signed->assertOk();
        $signed->assertSee('Signed successfully')->assertSee('Continue to payment')->assertSee('345.67 USD is due now');
        $document = $document->refresh();
        $this->assertSame('signed', $document->status->value);
        $signature = $document->signature()->sole();
        $this->assertSame((int) $issued->id, (int) $signature->business_document_version_id);
        $this->assertSame($hashAtSend, $signature->signed_content_hash);
        $this->assertSame((string) $issued->uid, \Tests\Support\Documents\ShownVersion::uid($document));

        // Negative: a signed document refuses editor writes, too (the owner is back in the editor).
        $this->asBusinessOwner($tenant);
        $this->putJson($this->ed('blocks', $tenant, $document), ['blocks' => $styled, 'expected_lock_version' => 1])->assertStatus(422);
        $this->asRecipient();

        // 10. The signed page leads on to payment; the secure page holds the payment section.
        $signedPage = $this->get($this->publicUrl($document, $token))->assertOk();
        $signedPage->assertSee('Due now: 345.67 USD')->assertSee('id="pay"', false)->assertSee('data-role="pay-button"', false);

        // 11. Payment starts on the DEPOSIT through the fake Stripe gateway; the webhook finalizes it.
        $this->postJson($this->payUrl($document, $token))->assertOk();
        $payment = BusinessDocumentPayment::where('business_document_id', $document->id)->sole();
        $this->assertSame(34567, (int) $payment->amount_minor);
        $this->assertNotEmpty($payment->provider_payment_intent_id);

        [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', (string) $payment->provider_payment_intent_id, 'acct_ready001', 34567, 'USD', (string) $payment->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();

        // 12. The balance stays pending with its FROZEN due date; the Contact changing again moves nothing.
        $document = $document->refresh();
        $this->assertSame('signed', $document->status->value, 'a paid deposit alone does not complete the document');
        $frozen = $this->schedule($document, $issued->refresh());
        $this->assertSame([34567, 88889], array_column($frozen, 'amount'));
        $this->assertSame($this->endOfDay('2030-06-01'), $frozen[1]['due_at']);
        $this->assertSame('pending', $issued->paymentScheduleItems()->where('kind', 'balance')->sole()->status->value);
        $this->assertSame('paid', $issued->paymentScheduleItems()->where('kind', 'deposit')->sole()->status->value);

        $this->setContactIdentity($tenant, 'Third', 'Rename', 'third@example.test');
        DB::table('contacts')->where('id', $tenant['contact']->id)->update(['phone' => '14155559999']);
        DB::table('appointments')->update(['start_at' => Carbon::parse('2031-01-01 15:00:00', 'UTC')]);
        $this->assertSame($frozen, $this->schedule($document, $issued->refresh()));
        $this->get($this->publicUrl($document, $token))->assertOk()
            ->assertSee('Due 1 June 2030')->assertSee('Due now: 888.89 USD')->assertDontSee('Third');

        // 13. Save as template from the SENT/SIGNED document: layout only.
        $this->asBusinessOwner($tenant);
        $saved = $this->postJson($this->ed('save-template', $tenant, $document), ['name' => 'My booth layout', 'template_type' => 'proposal', 'description' => 'Wedding layout'])
            ->assertOk()->assertJsonPath('template.name', 'My booth layout');
        $template = DocumentTemplate::where('uid', $saved->json('template.uid'))->firstOrFail();
        $this->assertSame((int) $tenant['business']->id, (int) $template->business_id);

        $row = (array) DB::table('document_templates')->where('id', $template->id)->first();
        unset($row['uid']); // a random uuid could contain a short digit run by chance
        $stored = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $forbidden = [
            // contact + recipient (both the original and the later-edited identities)
            self::CONTACT_FIRST, self::CONTACT_LAST, self::CONTACT_EMAIL, 'Zorbala', 'Third', 'new@example.test', '4155550177', '14155559999',
            (string) $tenant['contact']->uid, 'Pat Rivera',
            // package / lines / catalog
            self::PACKAGE, 'Renamed Package', (string) $item->uid, ...BusinessDocumentVersion::findOrFail($issued->id)->lineItems()->pluck('uid')->all(),
            // identities of the document and its versions
            (string) $document->uid, (string) $issued->uid, $token, $hashAtSend, (string) $signature->uid,
            // prices, deposit, balance
            '123456', '1234.56', '1,234.56', '34567', '345.67', '88889', '888.89', '999999', 'deposit_minor', 'payment_plan', 'unit_price', 'line_items',
            // dates
            '2030-06-15', '2030-06-01', '1 June 2030', '2031-01-01',
            // send / signature state
            'access_token', 'sent_at', 'signed_at', 'signer', 'typed_name', 'recipient', 'parties',
        ];
        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString((string) $needle, $stored, "the saved template must not carry [{$needle}]");
        }

        // ...yet it keeps the layout, the merge tokens, the signature position and a generic product placeholder.
        $kept = $template->blocks;
        $this->assertSame(array_column($styled, 'type'), array_column($kept, 'type'));
        $this->assertSame(array_column($styled, 'id'), array_column($kept, 'id'));
        $this->assertEquals([['t' => 'Your photo booth for ', 'b' => true], ['merge' => 'contact.first_name'], ['t' => '!', 'b' => true]], $kept[0]['data']['runs']);
        $this->assertSame('center', $kept[0]['data']['align']);
        $keptTypes = array_column($kept, 'type');
        $this->assertSame(1, count(array_keys($keptTypes, 'signature')));
        $this->assertGreaterThan(array_search('product_list', $keptTypes, true), array_search('signature', $keptTypes, true));
        $product = $kept[array_search('product_list', $keptTypes, true)];
        $this->assertEmpty(array_diff(array_keys($product['data']), ['show_description', 'show_quantity']), 'the product block is presentation flags only');
        $this->assertSame('Client signature', $kept[array_search('signature', $keptTypes, true)]['data']['label']);

        // The original platform template is byte-identical after the whole journey.
        $this->assertSame($platformBefore, $this->templateHash($platform));

        // The new template starts a fresh, clean proposal for the (changed) Contact.
        $second = $this->docsStore($tenant, ['template_uid' => $template->uid, 'title' => 'Second proposal'])->assertRedirect();
        $draft = BusinessDocument::where('title', 'Second proposal')->firstOrFail();
        $this->assertSame(0, $this->draftVersion($draft)->lineItems()->count());
        $this->assertArrayNotHasKey('payment_plan', $this->draftVersion($draft)->content);
    }

    // ---- journey 2: full payment --------------------------------------------------------------

    public function test_journey_two_full_payment_signs_then_continues_to_payment_for_the_full_amount(): void
    {
        Notification::fake();
        [$tenant, $platform] = $this->photoBoothWorld();
        $document = $this->startFromTemplate($tenant, $platform, 'Full payment booth');
        $this->addPackage($tenant, $document, 250000);

        $this->plan($tenant, $document, ['structure' => 'full'])->assertJsonPath('schedule.0.amount_minor', 250000)->assertJsonPath('schedule.0.due_label', 'Due after signing');

        $this->postJson($this->ed('send', $tenant, $document), ['channels' => ['email'], 'recipient_email' => self::CONTACT_EMAIL, 'expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('document_status', 'sent');
        $document = $document->refresh();
        $token = null;
        Notification::assertSentOnDemand(DocumentIssuedNotification::class, function ($n) use (&$token) {
            $token = $this->token($n);

            return true;
        });

        $this->asRecipient();
        $html = $this->get($this->publicUrl($document, $token))->assertOk()
            ->assertSee('Proposal for ' . self::CONTACT_FIRST)->assertSee('2,500.00')->getContent();
        $this->assertStringNotContainsString('data-kind="balance"', $html);

        $this->sign($document, $token, $html)->assertOk()
            ->assertSee('Signed successfully')->assertSee('Continue to payment')->assertSee('2,500.00 USD is due now');

        $this->postJson($this->payUrl($document, $token))->assertOk();
        $this->assertSame(250000, (int) BusinessDocumentPayment::where('business_document_id', $document->id)->sole()->amount_minor);
        $items = BusinessDocumentVersion::findOrFail($document->refresh()->current_version_id)->paymentScheduleItems()->get();
        $this->assertCount(1, $items);
        $this->assertSame('full', $items[0]->kind->value);
    }

    // ---- journey 3: balance due later ---------------------------------------------------------

    public function test_journey_three_balance_due_later_is_requested_automatically_with_a_fresh_link_and_then_paid(): void
    {
        Notification::fake();
        config(['documents.enabled' => true]);
        [$tenant, $platform] = $this->photoBoothWorld();
        $document = $this->startFromTemplate($tenant, $platform, 'Balance later booth');
        $this->addPackage($tenant, $document, 100000);
        $this->plan($tenant, $document, ['structure' => 'deposit', 'deposit' => '300.00', 'balance_due' => 'date', 'balance_due_date' => '2030-06-01'])
            ->assertJsonPath('schedule.1.due_date', '2030-06-01');

        $this->postJson($this->ed('send', $tenant, $document), ['channels' => ['email'], 'recipient_email' => self::CONTACT_EMAIL, 'expected_lock_version' => $this->lock($document)])
            ->assertOk()->assertJsonPath('document_status', 'sent');
        $document = $document->refresh();
        $firstToken = null;
        Notification::assertSentOnDemand(DocumentIssuedNotification::class, function ($n) use (&$firstToken) {
            $firstToken = $this->token($n);

            return true;
        });
        $issued = BusinessDocumentVersion::findOrFail($document->current_version_id);

        // Sign -> Continue to payment -> the deposit is paid through the fake Stripe gateway + webhook.
        $this->asRecipient();
        $html = $this->get($this->publicUrl($document, $firstToken))->assertOk()->getContent();
        $this->sign($document, $firstToken, $html)->assertOk()
            ->assertSee('Continue to payment')->assertSee('300.00 USD is due now');
        $this->postJson($this->payUrl($document, $firstToken))->assertOk();
        $deposit = BusinessDocumentPayment::where('business_document_id', $document->id)->sole();
        $this->assertSame(30000, (int) $deposit->amount_minor);
        [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', (string) $deposit->provider_payment_intent_id, 'acct_ready001', 30000, 'USD', (string) $deposit->local_idempotency_key);
        $this->postWebhook($body, $headers)->assertOk();

        $document = $document->refresh();
        $this->assertSame('signed', $document->status->value, 'the deposit alone does not complete the document');
        $frozen = $this->schedule($document, $issued->refresh());
        $this->assertSame($this->endOfDay('2030-06-01'), $frozen[1]['due_at']);
        $this->assertSame('pending', $issued->paymentScheduleItems()->where('kind', 'balance')->sole()->status->value);

        try {
            // Before the due date nothing is sent.
            Carbon::setTestNow(Carbon::parse('2030-05-31 12:00:00', 'UTC'));
            $this->artisan('documents:dispatch-balance-requests')->expectsOutput('Dispatched 0 balance payment request(s).')->assertExitCode(0);
            Notification::assertSentOnDemandTimes(DocumentBalanceRequestNotification::class, 0);

            // One second before the due DAY begins in New York (00:00 on 1 June = 04:00 UTC): still nothing.
            Carbon::setTestNow(Carbon::parse('2030-06-01 03:59:59', 'UTC'));
            $this->artisan('documents:dispatch-balance-requests')->expectsOutput('Dispatched 0 balance payment request(s).')->assertExitCode(0);

            // The due date arrives (first moment of 1 June in New York): the sweep sends ONE request.
            Carbon::setTestNow(Carbon::parse('2030-06-01 04:00:00', 'UTC'));
            $this->artisan('documents:dispatch-balance-requests')->expectsOutput('Dispatched 1 balance payment request(s).')->assertExitCode(0);
            $this->artisan('documents:dispatch-balance-requests')->expectsOutput('Dispatched 0 balance payment request(s).')->assertExitCode(0);
            Notification::assertSentOnDemandTimes(DocumentBalanceRequestNotification::class, 1);
            $freshToken = null;
            Notification::assertSentOnDemand(DocumentBalanceRequestNotification::class, function ($n, $channels, $notifiable) use (&$freshToken) {
                $freshToken = $this->token($n);

                return $notifiable->routes['mail'] === self::CONTACT_EMAIL;
            });
            $this->assertNotSame($firstToken, $freshToken);

            // The recipient follows the fresh link: the balance is payable; the first link is gone.
            $this->asRecipient();
            $this->get($this->publicUrl($document, $firstToken))->assertNotFound();
            $this->get($this->publicUrl($document, $freshToken))->assertOk()
                ->assertSee('Due 1 June 2030')->assertSee('Due now: 700.00 USD')->assertSee('id="pay"', false)->assertSee('data-role="pay-button"', false);

            // Paid through the fake gateway; the document is fully paid.
            $this->postJson($this->payUrl($document, $freshToken))->assertOk();
            $balance = BusinessDocumentPayment::where('business_document_id', $document->id)->orderByDesc('id')->firstOrFail();
            $this->assertSame(70000, (int) $balance->amount_minor);
            [$body, $headers] = $this->webhookPayload('payment_intent.succeeded', (string) $balance->provider_payment_intent_id, 'acct_ready001', 70000, 'USD', (string) $balance->local_idempotency_key);
            $this->postWebhook($body, $headers)->assertOk();
            $this->assertSame('paid', $document->refresh()->status->value);
            $this->assertSame(['paid', 'paid'], $issued->paymentScheduleItems()->orderBy('sequence')->get()->map(fn ($i) => $i->status->value)->all());

            // No further request, ever.
            Carbon::setTestNow(Carbon::parse('2030-06-03 12:00:00', 'UTC'));
            $this->artisan('documents:dispatch-balance-requests')->expectsOutput('Dispatched 0 balance payment request(s).')->assertExitCode(0);
            Notification::assertSentOnDemandTimes(DocumentBalanceRequestNotification::class, 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ---- negatives -----------------------------------------------------------------------------

    public function test_an_invoice_is_not_editable_in_the_proposal_builder(): void
    {
        $tenant = $this->editorTenant();
        $this->post(route('customer.workspaces.businesses.documents.store', [$tenant['workspace']->uid, $tenant['business']->uid]), [
            'kind' => 'invoice', 'title' => 'Invoice 1', 'contact_uid' => $tenant['contact']->uid, 'location_uid' => $tenant['location']->uid, 'via' => 'editor',
        ])->assertRedirect();
        $invoice = BusinessDocument::where('title', 'Invoice 1')->firstOrFail();
        $this->assertSame('invoice', $invoice->kind->value);
        $before = $this->draftVersion($invoice)->content;

        $this->get($this->ed('edit', $tenant, $invoice))->assertNotFound();
        $this->get($this->ed('preview', $tenant, $invoice))->assertNotFound();
        $this->putJson($this->ed('blocks', $tenant, $invoice), ['blocks' => $this->standardBlocks(), 'expected_lock_version' => $this->lock($invoice)])->assertNotFound();
        $this->postJson($this->ed('upgrade', $tenant, $invoice), ['expected_lock_version' => $this->lock($invoice)])->assertNotFound();
        $this->postJson($this->ed('save-template', $tenant, $invoice), ['name' => 'X', 'template_type' => 'proposal'])->assertNotFound();
        $this->assertEquals($before, $this->draftVersion($invoice)->content, 'the invoice was not turned into a block document');
    }
}
