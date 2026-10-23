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
        $this->assertDoesNotMatchRegularExpression('/\{\{(?!contact\.first_name|business\.name|business\.phone)/', $json, 'Only registered merge fields.');
    }
}
