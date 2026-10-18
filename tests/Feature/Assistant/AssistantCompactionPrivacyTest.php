<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\AiCompletionRequest;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Models\AssistantConversation;
use App\Models\BusinessLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * PR #460 review (round 3), P1: a deterministic, server-rendered reply (the `unanswered_conversations` list: customer names, phone
 * numbers, message snippets) is withheld from the provider in normal history. Compaction used to send the stored body of the very
 * same reply to the provider, and the summary could keep it. Both now use ONE provider-facing sanitizer.
 *
 * Everything goes through the real fast path, the real AssistantModel and AiGateway; only the provider is a fake, and every
 * payload it receives is inspected.
 */
class AssistantCompactionPrivacyTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    private const NAME = 'Zephyrine Quillfeather';

    private const SNIPPET = 'ZZSNIPPET-7731 can we move my fitting to Friday';

    private const PERMITTED = 'ZZPERMITTED-4410 how much is the wedding package';

    private string $phone = '';

    /** @var array<int, AiCompletionRequest> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
        config(['assistant.compaction_trigger_chars' => 50, 'assistant.history_window_messages' => 2]);

        // A provider that answers a compaction request by ECHOING its transcript as the "summary": whatever reaches the provider
        // is exactly what the stored summary can contain, so a leak into the summary can only come from the transcript sent.
        $inner = $this->fake;
        $this->app->instance(AiCompletionClient::class, new class($inner, $this->seen) implements AiCompletionClient {
            public function __construct(private $inner, private array &$seen)
            {
            }

            public function complete(AiCompletionRequest $request): AiCompletionResult
            {
                $this->seen[] = $request;
                $messages = $request->messages;

                if (str_contains((string) ($messages[0]['content'] ?? ''), 'Summarise this conversation')) {
                    $this->inner->complete($request); // keep the fake's own request log complete too

                    return AiCompletionResult::success('SUMMARY OF: ' . mb_substr((string) end($messages)['content'], 0, 800), 'gpt-4o-mini', 300, 40);
                }

                return $this->inner->complete($request);
            }
        });
    }

    private function everythingSent(): string
    {
        return implode("\n", array_map(fn (AiCompletionRequest $r): string => implode("\n", array_column($r->messages, 'content')), $this->seen));
    }

    public function test_a_deterministic_reply_never_reaches_the_provider_or_the_summary_through_compaction_while_ordinary_context_does(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Compact Venue', 'Compact Account');
        $this->conversationWith($business, [['incoming', CarbonImmutable::now()->subMinutes(40)->format('Y-m-d H:i:s')]]);
        DB::table('chat_box_messages')->update(['message' => self::SNIPPET]);
        $this->phone = (string) DB::table('chat_boxes')->value('to');
        $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $group = $this->contactGroup($business);
        $contactId = (int) DB::table('contacts')->insertGetId([
            'uid' => (string) Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id,
            'location_id' => $location->id, 'group_id' => $group->id, 'phone' => $this->phone, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // The name a waiting customer is shown under lives in the contact's FIRST_NAME / LAST_NAME custom-field values.
        foreach (['FIRST_NAME' => 'Zephyrine', 'LAST_NAME' => 'Quillfeather'] as $tag => $value) {
            $fieldId = (int) DB::table('contact_group_fields')->insertGetId([
                'uid' => (string) Str::uuid(), 'contact_group_id' => $group->id, 'label' => $tag, 'type' => 'text', 'tag' => $tag,
                'visible' => 1, 'required' => 0, 'is_phone' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('contacts_custom_field')->insert(['contact_id' => $contactId, 'field_id' => $fieldId, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->authenticateAs($customer);

        // 1. The deterministic list (no model call): the CUSTOMER sees names, number and snippet.
        $first = $this->say($workspace, $business, "Show customers who haven't received a reply")->assertOk();
        $shown = json_encode($this->lastReply($first));
        $this->assertStringContainsString('Zephyrine', $shown, 'The customer sees the real detail.');
        $this->assertStringContainsString('ZZSNIPPET-7731', $shown);
        $uid = $first->json('uid');
        $this->assertSame(0, $this->aiCalls());

        // 2. Ordinary, permitted conversation, then enough turns to push the list out of the recent window into compaction.
        $this->modelSays(['action' => 'answer', 'message' => 'The wedding package is a fixed price.']);
        $this->say($workspace, $business, self::PERMITTED, ['conversation' => $uid])->assertOk();
        $this->modelSays(['action' => 'answer', 'message' => 'Anything else?']);
        $this->say($workspace, $business, 'Thanks, that is all for now', ['conversation' => $uid])->assertOk();
        $this->modelSays(['action' => 'answer', 'message' => 'Done.']);
        $this->say($workspace, $business, 'One last thing please', ['conversation' => $uid])->assertOk();

        $compactions = array_values(array_filter($this->seen, fn (AiCompletionRequest $r): bool => str_contains((string) $r->messages[0]['content'], 'Summarise this conversation')));
        $this->assertNotEmpty($compactions, 'Compaction really ran, through the gateway.');

        // 3. NOTHING sensitive reached the provider, in any request — compaction or otherwise.
        $sent = $this->everythingSent();
        foreach (['Zephyrine', 'Quillfeather', 'ZZSNIPPET-7731', 'fitting to Friday', $this->phone] as $secret) {
            $this->assertStringNotContainsString($secret, $sent, "'{$secret}' must never be sent to the provider.");
        }

        // 4. ...so it cannot be in the generated summary either.
        $summary = (string) AssistantConversation::query()->where('uid', $uid)->value('summary');
        $this->assertNotSame('', $summary);
        foreach (['Zephyrine', 'Quillfeather', 'ZZSNIPPET-7731', $this->phone] as $secret) {
            $this->assertStringNotContainsString($secret, $summary);
        }

        // 5. Ordinary context still compacts: the permitted question is in what was sent and in the summary.
        // The oldest messages are folded by the first compaction and later ones by later compactions, so inspect ALL of them.
        $transcripts = implode("\n", array_map(function (AiCompletionRequest $r): string {
            $messages = $r->messages;

            return (string) end($messages)['content'];
        }, $compactions));
        $this->assertStringContainsString('ZZPERMITTED-4410', $transcripts, 'Ordinary context is still compacted.');
        $this->assertStringContainsString('The server showed the customer a list of records', $transcripts, 'The same placeholder normal history uses.');
        $this->assertStringContainsString('ZZPERMITTED-4410', $summary);

        // 6. The customer's own conversation still has the full original reply.
        $again = $this->getJson($this->assistantUrl($workspace, $business, 'show'))->assertOk();
        $this->assertStringContainsString('Zephyrine', json_encode($again->json('messages')));
        $this->assertStringContainsString('ZZSNIPPET-7731', json_encode($again->json('messages')));
    }
}
