<?php

namespace Tests\Feature\Migration;

use App\Library\Migration\Ghl\GhlImportPlanner;
use App\Library\Migration\Ghl\GhlImportWriter;
use App\Library\Migration\Lumina\LuminaAuraProvisioner;
use App\Library\Timeline\ContactActivityTimeline;
use App\Models\ChatBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Synthetic data only. Real provisioner + real pack + real writer + real timeline. */
class LuminaImportTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(): array
    {
        $msg = fn (string $id, string $type, string $dir, string $at, array $extra = []) => $extra + [
            'id' => $id, 'messageType' => $type, 'direction' => $dir, 'dateAdded' => $at, 'body' => "body {$id}", 'contactId' => 'C1',
        ];

        return [
            'location' => ['id' => 'LOC1'],
            'customFields' => [['id' => 'cf1', 'name' => 'Event Type'], ['id' => 'cf2', 'name' => 'Event Date']],
            'pipelines' => [[
                'id' => 'P1', 'name' => 'Inquiries',
                'stages' => [['id' => 'S1', 'name' => '🎉 New Lead', 'position' => 0], ['id' => 'S2', 'name' => '🥶 Cold (>7 days)', 'position' => 1]],
            ]],
            'contacts' => [
                ['id' => 'C1', 'firstNameRaw' => 'Ann', 'lastNameRaw' => 'Lee', 'email' => 'ann@example.test', 'phone' => '(720) 555-0101', 'dnd' => false, 'tags' => ['cold'], 'dateAdded' => '2026-01-02T00:00:00.000Z',
                    'customFields' => [['id' => 'cf1', 'value' => ['Wedding', 'Party']], ['id' => 'cf2', 'value' => 1794009600000]]],
                ['id' => 'C2', 'firstNameRaw' => 'Stop', 'phone' => '7205550102', 'dnd' => true, 'dateAdded' => '2026-02-02T00:00:00.000Z'],
                ['id' => 'C3', 'firstNameRaw' => 'Mail', 'email' => 'mail@example.test', 'dnd' => false, 'dateAdded' => '2026-03-02T00:00:00.000Z'],
            ],
            'opportunities' => [
                ['id' => 'O1', 'contactId' => 'C1', 'pipelineId' => 'P1', 'pipelineStageId' => 'S2', 'name' => 'Ann', 'status' => 'open', 'monetaryValue' => 250, 'createdAt' => '2026-05-01T10:00:00.000Z', 'lastStageChangeAt' => '2026-05-09T10:00:00.000Z', 'source' => 'Check Availability Form'],
                ['id' => 'O2', 'contactId' => 'C2', 'pipelineId' => 'P1', 'pipelineStageId' => 'S1', 'name' => 'Stop', 'status' => 'lost', 'createdAt' => '2026-05-02T10:00:00.000Z'],
            ],
            'conversations' => [['id' => 'V1', 'contactId' => 'C1'], ['id' => 'V3', 'contactId' => 'C3']],
            'messages' => [
                'V1' => [
                    $msg('M1', 'TYPE_SMS', 'outbound', '2026-05-01T10:00:00.000Z', ['status' => 'delivered']),
                    $msg('M2', 'TYPE_SMS', 'inbound', '2026-05-01T10:05:00.000Z'),
                    $msg('M3', 'TYPE_SMS', 'outbound', '2026-05-02T10:00:00.000Z', ['status' => 'failed', 'source' => 'workflow']),
                    $msg('M4', 'TYPE_EMAIL', 'outbound', '2026-05-03T10:00:00.000Z', ['body' => '<p>Hello <b>Ann</b></p>', 'meta' => ['email' => ['subject' => 'Your quote']]]),
                    $msg('M5', 'TYPE_CALL', 'inbound', '2026-05-04T10:00:00.000Z', ['status' => 'completed', 'meta' => ['call' => ['duration' => 31]]]),
                    $msg('M6', 'TYPE_ACTIVITY_OPPORTUNITY', 'outbound', '2026-05-05T10:00:00.000Z'),
                ],
                'V3' => [$msg('M7', 'TYPE_EMAIL', 'inbound', '2026-05-06T10:00:00.000Z', ['contactId' => 'C3', 'meta' => ['email' => ['subject' => 'Hi']]])],
            ],
        ];
    }

    private function provision(): array
    {
        User::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa'.uniqid().'@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);

        $result = app(LuminaAuraProvisioner::class)->provision('owner@example.test', 'a-long-test-password-1', [
            ['first' => 'Own', 'last' => 'Er', 'email' => 'owner@example.test'],
            ['first' => 'Sta', 'last' => 'Ff', 'email' => 'staff@example.test'],
        ]);

        return [$result['context']->business, $result['context']->location, $result];
    }

    public function test_account_is_provisioned_safely_with_the_pack_as_drafts(): void
    {
        [$business, $location, $result] = $this->provision();

        $this->assertSame('America/Denver', $business->timezone);
        $this->assertSame(LuminaAuraProvisioner::PHONE, $business->phone);
        $this->assertSame([], $result['violations'], 'Safety audit: no number, sender, token, domain or published workflow.');
        $this->assertSame(1, $result['report']['staff_members']);
        $this->assertSame(0, DB::table('automation_workflows')->where('business_id', $business->id)->whereNotNull('published_version_id')->count());
        $this->assertSame(11, DB::table('automation_workflows')->where('business_id', $business->id)->count());
    }

    public function test_dry_run_writes_nothing_then_import_is_exact_idempotent_and_inert(): void
    {
        [$business, $location] = $this->provision();
        $plan = (new GhlImportPlanner())->plan($this->snapshot());
        $writer = app(GhlImportWriter::class);

        Queue::fake();
        Mail::fake();
        Http::fake();
        Bus::fake();

        $dry = $writer->import($plan, $business, $location, '+17205028306', true);
        $this->assertSame(0, DB::table('contacts')->where('business_id', $business->id)->count());
        $this->assertSame(3, $dry['counts']['contacts_created']);
        $this->assertSame(2, $dry['counts']['opportunities_created'], 'The dry run resolves planned contacts instead of reporting deals as unmapped.');
        $this->assertArrayNotHasKey('opportunities_unmapped', $dry['counts']);

        $applied = $writer->import($plan, $business, $location, '+17205028306', false);
        $this->assertSame($dry['counts'], $applied['counts'], 'What the dry run predicted is what was written.');

        $this->assertSame(3, DB::table('contacts')->where('business_id', $business->id)->count());
        $this->assertSame(1, DB::table('contacts')->where('business_id', $business->id)->where('status', 'unsubscribe')->count(), 'DND carries over as an opt-out.');
        $this->assertSame(1, DB::table('contacts')->where('business_id', $business->id)->where('phone', 'like', 'np-%')->count());
        $this->assertSame(2, DB::table('crm_opportunities')->where('business_id', $business->id)->count());
        $this->assertSame(2, ChatBox::query()->where('business_id', $business->id)->count());
        $this->assertSame(2, DB::table('chat_box_messages')->count(), 'Delivered SMS only are native bubbles (M1, M2).');
        $this->assertSame(5, DB::table('imported_timeline_items')->where('business_id', $business->id)->count());
        $this->assertSame(3 + 2 + 2 + 7, DB::table('external_import_records')->where('business_id', $business->id)->where('source_system', 'gohighlevel')->count());

        $opp = DB::table('crm_opportunities')->where('business_id', $business->id)->where('value_minor', 25000)->first();
        $this->assertSame('2026-05-01 10:00:00', $opp->created_at);
        $this->assertSame('2026-05-09 10:00:00', $opp->stage_entered_at);
        $this->assertSame('form', $opp->source);
        $this->assertSame('Cold (7+ days)', DB::table('crm_pipeline_stages')->where('id', $opp->stage_id)->value('name'));
        $this->assertSame('2026-05-01 10:05:00', DB::table('chat_box_messages')->where('direction', 'incoming')->value('created_at'));
        $this->assertSame(2, DB::table('custom_field_values')->where('business_id', $business->id)->count());

        $this->assertSame(0, DB::table('chat_box_messages')->whereNotNull('send_status')->orWhereNotNull('business_messaging_operation_id')->orWhereNotNull('automation_step_run_id')->count());
        $this->assertSame(0, DB::table('automation_enrollments')->count());
        $this->assertSame(0, DB::table('business_messaging_operations')->count());
        $this->assertSame(0, DB::table('reports')->count());
        $this->assertSame(0, DB::table('chat_boxes')->where('notification', '>', 0)->count(), 'History is never unread.');
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Http::assertNothingSent();

        $again = $writer->import($plan, $business, $location, '+17205028306', false);
        $this->assertSame(['contacts_skipped_existing' => 3, 'conversations_skipped_existing' => 2, 'custom_fields_existing' => 2, 'opportunities_skipped_existing' => 2, 'tags_existing' => 1], $again['counts']);
        $this->assertSame(3, DB::table('contacts')->where('business_id', $business->id)->count());
    }

    public function test_history_shows_in_conversations_with_provenance_and_never_leaks_across_tenants(): void
    {
        [$business, $location] = $this->provision();
        $plan = (new GhlImportPlanner())->plan($this->snapshot());
        app(GhlImportWriter::class)->import($plan, $business, $location, '+17205028306', false);

        $box = ChatBox::query()->where('business_id', $business->id)->where('to', '17205550101')->firstOrFail();
        $contact = \App\Models\Contacts::query()->where('business_id', $business->id)->where('phone', '17205550101')->first();
        $page = app(ContactActivityTimeline::class)->forConversation($business, $box, $contact);

        $messages = array_values(array_filter($page->items, fn ($i) => $i->isMessage()));
        $bodies = array_map(fn ($i) => $i->body, $messages);
        $this->assertContains('body M1', $bodies);
        $this->assertContains("Your quote\n\nHello Ann", $bodies, 'Email shows as an imported email message with HTML stripped.');
        $channels = array_unique(array_map(fn ($i) => $i->channel, $messages));
        sort($channels);
        $this->assertSame(['email', 'sms'], $channels);

        $failed = collect($page->items)->first(fn ($i) => str_contains((string) $i->detail, 'failed'));
        $this->assertNotNull($failed, 'A GHL-failed SMS is kept and labelled, not shown as delivered.');
        $this->assertFalse($failed->retryable);
        $this->assertNull($failed->retrySendUid);

        $this->assertTrue(collect($page->items)->contains(fn ($i) => $i->title === 'Inbound call — completed (31s)'));
        $times = array_map(fn ($i) => $i->at->getTimestamp(), $page->items);
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'Chronological by ORIGINAL time.');

        $other = app(LuminaAuraProvisioner::class)->provision('other-owner@example.test', 'another-long-test-password-2', [])['context']->business;
        $leak = app(ContactActivityTimeline::class)->forConversation($other, $box, null);
        $this->assertSame([], array_values(array_filter($leak->items, fn ($i) => str_starts_with($i->key, 'imported:') || str_starts_with($i->key, 'conversation_message:'))));
        $this->assertSame(0, DB::table('external_import_records')->where('business_id', $other->id)->where('source_system', 'gohighlevel')->count());
    }
}
