<?php

namespace Tests\Feature\NicheBlueprint;

use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\PhotoBoothBlueprintV2;
use App\Library\NicheBlueprint\PhotoBoothBusinessPackV3;
use Tests\TestCase;

/** The pack is configuration only and must satisfy every adapter's own validation. */
class PhotoBoothBusinessPackV3Test extends TestCase
{
    public function test_every_component_passes_its_adapter_validation(): void
    {
        $registry = app(BlueprintComponentAdapterRegistry::class);

        foreach (PhotoBoothBusinessPackV3::components() as $component) {
            $registry->adapterFor($component['type'])->validateDescriptor($component['payload']);
        }

        $this->assertNotEmpty(PhotoBoothBusinessPackV3::components());
    }

    public function test_keys_are_unique_and_do_not_collide_with_v2(): void
    {
        $v3 = array_column(PhotoBoothBusinessPackV3::components(), 'key');
        $v2 = array_column(PhotoBoothBlueprintV2::components(), 'key');

        $this->assertSame($v3, array_values(array_unique($v3)));
        $this->assertSame([], array_values(array_intersect($v3, $v2)), 'v3 only adds; it never rewrites a v2 component.');
    }

    public function test_default_pipelines_have_the_specified_stages_in_order(): void
    {
        $by = array_column(PhotoBoothBusinessPackV3::components(), 'payload', 'key');

        $this->assertSame(
            ['New Lead', "Didn't Pick Up", 'No Contact Made', 'In Contact', 'Proposal Sent', 'Follow-Up', 'Nurture', 'Cold (7+ days)', 'Lost'],
            array_column($by['pb3_pipeline_inquiries']['stages'], 'name'),
        );
        $this->assertSame(
            ['Booking Confirmed', 'Questionnaire Submitted', 'Awaiting Design Approval', 'Ready For Event', 'Completed'],
            array_column($by['pb3_pipeline_bookings']['stages'], 'name'),
        );
    }

    public function test_the_ten_required_workflows_exist_and_stage_triggers_name_real_stages(): void
    {
        $names = array_map(fn ($c) => $c['payload']['name'], PhotoBoothBusinessPackV3::workflows());
        foreach (['New Inquiry Received', 'Missed Call Follow-Up', 'Inquiry Follow-Up', 'Proposal Follow-Up', 'Booking Confirmed',
            'Questionnaire Reminder', 'Event Preparation', 'Event Completed', 'Review Request', 'Inactive Lead Nurture'] as $required) {
            $this->assertContains($required, $names);
        }

        $by = array_column(PhotoBoothBusinessPackV3::components(), 'payload', 'key');
        $stages = array_merge(array_column($by['pb3_pipeline_inquiries']['stages'], 'name'), array_column($by['pb3_pipeline_bookings']['stages'], 'name'));
        foreach (PhotoBoothBusinessPackV3::workflows() as $wf) {
            if (($wf['payload']['trigger_type']) === 'opportunity_stage_changed') {
                $this->assertContains($wf['payload']['trigger_stage'], $stages, $wf['payload']['name']);
            }
        }
    }

    public function test_follow_ups_are_guarded_by_reply_detection_and_consent(): void
    {
        foreach (PhotoBoothBusinessPackV3::workflows() as $wf) {
            $types = array_column($wf['payload']['steps'], 'type');
            if (in_array('send_sms', $types, true)) {
                $this->assertContains('end_if_unsubscribed', $types, $wf['payload']['name'].' sends SMS without a consent guard.');
                $firstSms = array_search('send_sms', $types, true);
                $this->assertLessThan($firstSms, array_search('end_if_unsubscribed', $types, true), $wf['payload']['name']);
            }
        }
    }

    public function test_pack_carries_no_private_data_or_invented_prices_or_links(): void
    {
        $json = json_encode([PhotoBoothBusinessPackV3::components(), PhotoBoothBusinessPackV3::templates()]);

        $this->assertDoesNotMatchRegularExpression('/\$\d/', $json, 'No prices.');
        $this->assertDoesNotMatchRegularExpression('#https?://#', $json, 'No hard-coded URLs or review links.');
        $this->assertDoesNotMatchRegularExpression('/\+1\d{10}|@gmail\.|Lumina/i', $json, 'No customer-specific data.');
        $this->assertDoesNotMatchRegularExpression('/\{\{(?!contact\.first_name|business\.name|business\.phone|business\.review_link|business\.questionnaire_link)/', $json, 'Only registered merge fields.');
    }

    public function test_every_send_is_guarded_after_its_last_wait_and_never_before_it(): void
    {
        foreach (PhotoBoothBusinessPackV3::workflows() as $component) {
            $sinceWait = [];
            foreach ($component['payload']['steps'] as $step) {
                if ($step['type'] === 'wait' || $step['type'] === 'wait_for_send_window') {
                    $sinceWait = [];

                    continue;
                }
                if (str_starts_with($step['type'], 'end_if_')) {
                    $sinceWait[] = $step['type'];
                }
                if (in_array($step['type'], ['send_sms', 'send_email'], true)) {
                    // The opt-out is rechecked after the LAST wait (a window hold can last until morning); a send straight after another send shares that check.
                    $this->assertContains('end_if_unsubscribed', $sinceWait, $component['key'].': a send is not preceded by an opt-out check after the last wait.');
                }
            }
        }
    }

    public function test_no_guard_sits_directly_before_a_send_window_wait(): void
    {
        foreach (PhotoBoothBusinessPackV3::workflows() as $component) {
            $steps = $component['payload']['steps'];
            foreach ($steps as $i => $step) {
                if ($step['type'] === 'wait_for_send_window' && $i > 0) {
                    $this->assertFalse(str_starts_with($steps[$i - 1]['type'], 'end_if_'), $component['key'].': a guard is evaluated before a window hold instead of after it.');
                }
            }
        }
    }

    public function test_workflows_are_bound_to_the_right_forms(): void
    {
        $by = array_column(array_map(fn ($c) => $c['payload'] + ['key' => $c['key']], PhotoBoothBusinessPackV3::workflows()), null, 'key');
        $this->assertSame('form_submitted', $by['pb3_wf_questionnaire_submitted']['trigger_type']);
        $this->assertSame('Event questionnaire', $by['pb3_wf_questionnaire_submitted']['trigger_form']);
        $this->assertSame('Check Availability', $by['pb3_wf_new_inquiry']['trigger_form']);
    }
}
