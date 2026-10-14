<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * PR #460 review, P1: `find_contact` put a first name and surname initial into ToolResult::data, which is what the provider is sent.
 * Names, like phone numbers, belong only in the customer-visible facts/links/display. The model gets a count and opaque handles.
 */
class AssistantContactNamePrivacyTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    public function test_find_contact_sends_the_provider_a_count_and_handles_never_a_name_while_the_customer_still_sees_it(): void
    {
        $this->bootAssistant();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Name Venue', 'Name Account');
        $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $group = $this->contactGroup($business);
        $contactId = (int) DB::table('contacts')->insertGetId([
            'uid' => $uid = (string) Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id,
            'location_id' => $location->id, 'group_id' => $group->id, 'phone' => '15557770000', 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['FIRST_NAME' => 'Zephyrine', 'LAST_NAME' => 'Quillfeather'] as $tag => $value) {
            $fieldId = (int) DB::table('contact_group_fields')->insertGetId([
                'uid' => (string) Str::uuid(), 'contact_group_id' => $group->id, 'label' => $tag, 'type' => 'text', 'tag' => $tag,
                'visible' => 1, 'required' => 0, 'is_phone' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('contacts_custom_field')->insert(['contact_id' => $contactId, 'field_id' => $fieldId, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->authenticateAs($customer);

        // Looked up by NAME and by PHONE: either way the provider must not learn the name.
        foreach (['Zephyrine', '15557770000'] as $query) {
            $this->modelSays(['action' => 'call_tools', 'calls' => [['tool' => 'find_contact', 'args' => ['query' => $query]]]]);
            $this->modelSays(['action' => 'answer', 'message' => 'I found them.']);
            $r = $this->say($workspace, $business, 'Look that customer up for me please', ['new' => true])->assertOk();

            $reply = $this->lastReply($r);
            $this->assertStringContainsString('Zephyrine', json_encode($reply['payload']), 'The customer still sees the name.');
            $this->assertStringContainsString('15557770000', json_encode($reply['payload']['facts']));
        }

        // What the provider received as TOOL RESULTS (the model's own call arguments are, naturally, whatever it chose to look up).
        $toolResults = '';
        for ($i = 0; $i < $this->aiCalls(); $i++) {
            foreach ($this->fake->requests()[$i]->messages as $m) {
                if (str_starts_with((string) $m['content'], 'TOOL RESULTS')) {
                    $toolResults .= "\n" . $m['content'];
                }
            }
        }

        $this->assertStringContainsString('"match_count":1', $toolResults);
        $this->assertStringContainsString('"handle":"h_', $toolResults, 'Opaque handles are still sent.');
        foreach (['Zephyrine', 'Quillfeather', '15557770000', '"label"'] as $secret) {
            $this->assertStringNotContainsString($secret, $toolResults, "'{$secret}' must not reach the provider in a tool result.");
        }
    }
}
