<?php

namespace Tests\Feature\Automations;

use App\Library\Automation\Workflow\Runtime\LeadFollowUpStopper;
use Tests\TestCase;

class LeadFollowUpStopperTest extends TestCase
{
    public function test_empty_contact_list_touches_nothing(): void
    {
        $this->assertSame(0, (new LeadFollowUpStopper())->stop(1, [], LeadFollowUpStopper::REPLIED));
    }

    public function test_only_lead_acquisition_triggers_are_stopped(): void
    {
        $values = array_map(fn ($t) => $t->value, LeadFollowUpStopper::FOLLOW_UP_TRIGGERS);

        $this->assertNotContains('appointment_scheduled', $values);
        $this->assertNotContains('message_received', $values);
        $this->assertContains('form_submitted', $values);
    }
}
