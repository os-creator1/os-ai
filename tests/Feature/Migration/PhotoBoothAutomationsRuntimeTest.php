<?php

namespace Tests\Feature\Migration;

use App\Enums\Forms\FormDeploymentSource;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Runtime\AutomationSmsDispatcher;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\Runtime\WorkflowWakeService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Library\Forms\FormManager;
use App\Library\Migration\Ghl\GhlImportPlanner;
use App\Library\Migration\Ghl\GhlImportWriter;
use App\Library\Migration\Lumina\LuminaAuraProvisioner;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Photo Booth pack's workflows RUN: published for real, driven by the real advancer and wake sweep, with only the
 * provider edge replaced by a recorder. Time is frozen in America/Denver. Synthetic contacts only.
 */
class PhotoBoothAutomationsRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private int $ownerId;

    /** @var list<array{contact: int, message: string, at: string}> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([\App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment::class]);
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'America/Denver')); // a Tuesday morning, local

        User::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        $ctx = app(LuminaAuraProvisioner::class)->provision('owner@example.test', 'a-long-test-password-1', [])['context'];
        $this->business = $ctx->business;
        $this->ownerId = (int) $ctx->user->id;

        app(GhlImportWriter::class)->import((new GhlImportPlanner())->plan([
            'location' => ['id' => 'L'],
            'pipelines' => [['id' => 'P1', 'name' => 'Inquiries', 'stages' => [['id' => 'S1', 'name' => '🎉 New Lead', 'position' => 0]]]],
            'contacts' => [
                ['id' => 'C1', 'firstNameRaw' => 'Ann', 'lastNameRaw' => 'Lee', 'email' => 'ann@example.test', 'phone' => '(720) 555-0101', 'dateAdded' => '2026-01-02T00:00:00.000Z'],
                ['id' => 'C2', 'firstNameRaw' => 'Stop', 'phone' => '7205550102', 'dnd' => true, 'dateAdded' => '2026-01-03T00:00:00.000Z'],
            ],
            'opportunities' => [
                ['id' => 'O1', 'contactId' => 'C1', 'pipelineId' => 'P1', 'pipelineStageId' => 'S1', 'name' => 'Ann', 'status' => 'open', 'createdAt' => '2026-10-30T10:00:00.000Z'],
                ['id' => 'O2', 'contactId' => 'C2', 'pipelineId' => 'P1', 'pipelineStageId' => 'S1', 'name' => 'Stop', 'status' => 'open', 'createdAt' => '2026-10-30T10:00:00.000Z'],
            ],
        ]), $this->business, $ctx->location, '+17205028306', false);

        // The provider edge only: record what WOULD be sent. Everything before it (guards, windows, merge) is real.
        $test = $this;
        $this->app->bind(AutomationSmsDispatcher::class, fn ($app) => new class($app->make(\App\Repositories\Contracts\CampaignRepository::class), $app->make(\App\Library\Messaging\BusinessMessagingIdentityResolver::class), $app->make(\App\Library\Automation\Workflow\Runtime\AutomationSendContext::class), $app->make(\App\Library\Messaging\BusinessSmsSendingPath::class), $test) extends AutomationSmsDispatcher {
            public function __construct($a, $b, $c, $d, private $test)
            {
                parent::__construct($a, $b, $c, $d);
            }

            public function send(AutomationWorkflowNode $node, AutomationEnrollment $enrollment, Business $business, Contacts $contact, string $message): NodeExecutionOutcome
            {
                $this->test->sent[] = ['contact' => (int) $contact->id, 'message' => $message, 'at' => Carbon::now('America/Denver')->format('Y-m-d H:i')];

                return NodeExecutionOutcome::succeeded('recorded');
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contact(string $name): Contacts
    {
        $id = DB::table('external_import_records')->where('business_id', $this->business->id)->where('source_type', 'contact')->where('source_id', $name)->value('local_id');

        return Contacts::query()->findOrFail($id);
    }

    private function enroll(string $workflowName, Contacts $contact): AutomationEnrollment
    {
        $workflow = AutomationWorkflow::query()->where('business_id', $this->business->id)->where('name', $workflowName)->firstOrFail();
        if ($workflow->published_version_id === null) {
            app(WorkflowPublisher::class)->publish($workflow, $this->ownerId);
        }

        return app(\App\Library\Automation\Workflow\Contracts\EnrollmentService::class)->enroll($workflow->fresh(), $contact, 'test:'.uniqid(), 0, null);
    }

    /** Drive a journey exactly as the queue + minute sweep would, jumping the clock to each wake time. */
    private function drive(AutomationEnrollment $e, ?callable $between = null): AutomationEnrollment
    {
        for ($i = 0; $i < 40; $i++) {
            $e = $e->fresh();
            if ($e->isTerminal()) {
                return $e;
            }
            if ($e->isExecutableNow()) {
                app(WorkflowAdvancer::class)->advance($e);

                continue;
            }
            if ($e->isWaiting()) {
                if ($between !== null) {
                    $between($e);
                }
                Carbon::setTestNow(Carbon::parse((string) $e->resume_at, 'UTC')->addSecond());
                app(WorkflowWakeService::class)->wakeDue();
            }
        }

        return $e->fresh();
    }

    private function tasks(): array
    {
        return DB::table('contact_tasks')->where('business_id', $this->business->id)->orderBy('id')->get()->all();
    }

    public function test_new_inquiry_tags_creates_the_call_task_and_texts_once_in_the_window(): void
    {
        $ann = $this->contact('C1');
        $done = $this->drive($this->enroll('New Inquiry Received', $ann));

        $this->assertSame('completed', $done->status->value);
        $this->assertTrue(DB::table('contact_tags')->where('contact_id', $ann->id)->whereIn('tag_id', DB::table('tags')->where('normalized_name', 'inquiry')->pluck('id'))->exists(), 'Inquiry tag added.');

        $this->assertCount(1, $this->sent, 'Exactly one text, not repeated.');
        $this->assertStringContainsString('Hi Ann, this is Lumina Aura Events', $this->sent[0]['message'], 'Correct business identity and merge fields.');
        $this->assertStringNotContainsString('{{', $this->sent[0]['message']);
        $this->assertSame('2026-11-03 10:05', $this->sent[0]['at'], 'Sent after the 5-minute delay, inside the window.');

        $tasks = $this->tasks();
        $this->assertCount(2, $tasks, 'First call task now, a second one after a day with no reply.');
        $this->assertSame('Call Ann about their new event inquiry', $tasks[0]->title);
        $this->assertSame('open', $tasks[0]->status);
        $this->assertSame((int) $this->business->id, (int) $tasks[0]->business_id);
        $this->assertSame((int) $ann->id, (int) $tasks[0]->contact_id);
    }

    public function test_a_reply_stops_the_follow_ups_and_the_second_task(): void
    {
        $ann = $this->contact('C1');
        $e = $this->enroll('New Inquiry Received', $ann);

        $replied = false;
        $done = $this->drive($e, function (AutomationEnrollment $waiting) use ($ann, &$replied) {
            if (! $replied) {
                $replied = true;
                $box = DB::table('chat_boxes')->insertGetId(['uid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'from' => '17205028306', 'to' => $ann->phone, 'created_at' => now(), 'updated_at' => now(), 'reply_by_customer' => 1]);
                DB::table('chat_box_messages')->insert(['box_id' => $box, 'message' => 'Yes please!', 'sms_type' => 'plain', 'direction' => 'incoming', 'send_by' => 'to', 'created_at' => now()->addMinute(), 'updated_at' => now()->addMinute()]);
            }
        });

        $this->assertSame('completed', $done->status->value);
        $this->assertCount(0, $this->sent, 'A reply during the 5-minute delay cancels the text.');
        $this->assertCount(1, $this->tasks(), 'Only the immediate call task; the "no reply" task is cancelled.');
    }

    public function test_an_opted_out_contact_is_never_messaged_by_any_workflow(): void
    {
        $stop = $this->contact('C2');
        $this->assertSame('unsubscribe', $stop->status);

        foreach (['New Inquiry Received', 'Missed Call Follow-Up', 'Inquiry Follow-Up', 'Proposal Follow-Up', 'Inactive Lead Nurture', 'Review Request'] as $name) {
            $this->drive($this->enroll($name, $stop));
        }

        $this->assertSame([], $this->sent, 'No text to an opted-out contact, from any pack workflow.');
    }

    public function test_quiet_hours_hold_a_message_until_the_next_morning_in_the_business_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 22:30', 'America/Denver'));
        $ann = $this->contact('C1');
        $done = $this->drive($this->enroll('Missed Call Follow-Up', $ann));

        $this->assertNotEmpty($this->sent);
        $this->assertSame('2026-11-04 08:00', substr($this->sent[0]['at'], 0, 16), 'Held overnight and sent at 08:00 local, never at 22:30.');
        foreach ($this->sent as $s) {
            $hour = (int) substr($s['at'], 11, 2);
            $this->assertTrue($hour >= 8 && $hour < 21, "Sent at {$s['at']}, inside quiet hours.");
        }
        $this->assertSame('completed', $done->status->value);
    }

    public function test_a_closed_deal_stops_follow_ups(): void
    {
        $ann = $this->contact('C1');
        DB::table('crm_opportunities')->where('contact_id', $ann->id)->update(['status' => 'won']);

        $this->drive($this->enroll('Proposal Follow-Up', $ann));
        $this->drive($this->enroll('Inactive Lead Nurture', $ann));

        $this->assertSame([], $this->sent, 'A booked (won) or lost deal ends the follow-up journeys.');
        $this->assertSame([], $this->tasks());
    }

    public function test_review_and_questionnaire_links_are_real_merge_fields_and_never_promised_when_missing(): void
    {
        $ann = $this->contact('C1');

        // Without a review link or questionnaire form: nothing is sent that promises one.
        $this->drive($this->enroll('Review Request', $ann));
        $this->drive($this->enroll('Booking Confirmed', $ann));
        $this->assertSame([], $this->sent);

        // Set both up.
        DB::table('seo_location_review_links')->insert(['uid' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $this->business->id, 'business_location_id' => $this->business->primaryLocation()->value('id'), 'review_url' => 'https://g.page/r/example/review', 'created_at' => now(), 'updated_at' => now()]);
        $form = Form::query()->where('business_id', $this->business->id)->where('name', 'Event questionnaire')->firstOrFail();
        app(FormManager::class)->activate($this->business, $form);
        $deployment = app(FormManager::class)->setDeployment($this->business, $form, $this->business->primaryLocation()->firstOrFail(), true, FormDeploymentSource::DirectLink);

        $this->drive($this->enroll('Booking Confirmed', $ann));
        $this->drive($this->enroll('Review Request', $this->contact('C1')));
        $messages = array_column($this->sent, 'message');

        $this->assertCount(2, $messages);
        $this->assertStringContainsString(route('public.forms.show', [$deployment->uid]), $messages[0]);
        $this->assertStringContainsString('https://g.page/r/example/review', $messages[1]);
    }

    public function test_moving_a_deal_into_the_trigger_stage_enrolls_the_published_workflow_once(): void
    {
        $ann = $this->contact('C1');
        $workflow = AutomationWorkflow::query()->where('business_id', $this->business->id)->where('name', 'Proposal Follow-Up')->firstOrFail();
        app(WorkflowPublisher::class)->publish($workflow, $this->ownerId);

        $opportunity = \App\Models\CrmOpportunity::query()->where('business_id', $this->business->id)->where('contact_id', $ann->id)->firstOrFail();
        $stage = \App\Models\CrmPipelineStage::query()->where('business_id', $this->business->id)->where('name', 'Proposal Sent')->firstOrFail();
        $service = app(\App\Library\Crm\CrmOpportunityService::class);

        $service->moveToStage($opportunity, $stage, $this->ownerId);
        $this->assertSame(1, AutomationEnrollment::query()->where('workflow_id', $workflow->id)->where('contact_id', $ann->id)->count());

        // Moving it away and back creates a fresh occurrence, but the SAME move replayed does not double-enroll.
        $service->moveToStage($opportunity->fresh(), $stage, $this->ownerId);
        $this->assertSame(1, AutomationEnrollment::query()->where('workflow_id', $workflow->id)->where('contact_id', $ann->id)->count());
    }

    public function test_running_the_same_journey_twice_is_retry_safe(): void
    {
        $ann = $this->contact('C1');
        $e = $this->enroll('New Inquiry Received', $ann);
        $this->drive($e);
        $before = [count($this->sent), count($this->tasks()), DB::table('automation_step_runs')->count()];

        app(WorkflowAdvancer::class)->advance($e->fresh());
        $this->drive($e->fresh());

        $this->assertSame($before, [count($this->sent), count($this->tasks()), DB::table('automation_step_runs')->count()], 'Replaying a finished journey sends, creates and logs nothing more.');
        $this->assertGreaterThan(5, DB::table('automation_step_runs')->where('enrollment_id', $e->id)->count(), 'Every step is logged.');
    }
}
