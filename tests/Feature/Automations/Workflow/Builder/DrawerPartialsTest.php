<?php

namespace Tests\Feature\Automations\Workflow\Builder;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\WorkflowDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Automations V2 (contract §10, §11, §13.1, task requirement, V2-D) — the
 * node-specific drawer partials the builder page renders as hidden
 * `<template>` elements. Each assertion here is a shape proof against
 * NodeTypeRegistry's own validated config keys (app/Library/Automation/Workflow/NodeTypeRegistry.php)
 * — the drawer can only ever persist what the registry can validate.
 */
class DrawerPartialsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
    }

    private function renderBuilder(array $writableFields = []): string
    {
        [$customer] = $this->tenant();
        $this->authenticateAs($customer);

        $definition = (new WorkflowDraftService())->starterDefinition(WorkflowTriggerType::ContactCreated);

        Route::middleware('web')->get('/__test/wf-builder-drawer', function () use ($definition, $writableFields) {
            return view('customer.Automations.Workflows.builder', [
                'workspaceUid' => 'ws-uid',
                'businessUid' => 'biz-uid',
                'basePath' => '/workspaces/ws-uid/businesses/biz-uid/automations/workflows',
                'workflow' => (object) ['uid' => 'wf-1', 'name' => 'Welcome flow', 'status' => 'draft'],
                'draft' => ['definition' => $definition, 'revision' => 1, 'errors' => []],
                'contactGroups' => [(object) ['id' => 1, 'name' => 'Leads']],
                'dateFields' => [(object) ['id' => 5, 'label' => 'Birthday', 'contact_group_id' => 1]],
                'writableFields' => $writableFields,
            ]);
        });

        return $this->get('/__test/wf-builder-drawer')->assertOk()->getContent();
    }

    private function templateBody(string $html, string $id): string
    {
        $pattern = '/<template id="' . preg_quote($id, '/') . '">(.*?)<\/template>/s';
        $this->assertMatchesRegularExpression($pattern, $html, "Missing drawer template #{$id}.");
        preg_match($pattern, $html, $matches);

        return $matches[1];
    }

    /** T7 — Send SMS persists body only: no sender/channel field anywhere in its form. */
    public function test_send_sms_drawer_exposes_only_the_body_field(): void
    {
        $body = $this->templateBody($this->renderBuilder(), 'wf-node-form-send_sms');

        $this->assertStringContainsString('data-field="body"', $body);
        foreach (['sender', 'channel', 'sending_server', 'from_number'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $body, "Send SMS must never persist a {$forbidden} — v2 resolves the sending path at execution time (§10.1).");
        }
    }

    /** T8 — Update contact field offers only the same-Business writable-field catalog it was given. */
    public function test_update_contact_field_drawer_uses_only_the_supplied_writable_field_catalog(): void
    {
        $html = $this->renderBuilder([(object) ['id' => 9, 'label' => 'Notes', 'contact_group_id' => 1, 'type' => 'text']]);
        $body = $this->templateBody($html, 'wf-node-form-update_contact_field');

        $this->assertStringContainsString('data-field="field_id"', $body);
        $this->assertStringContainsString('data-field="value"', $body);
        // The select itself is populated by JS from catalogs.writableFields
        // (drawer.js populateUpdateContactField) — the template carries no
        // options of its own, so it cannot hardcode a field belonging to
        // another Business or a phone field.
        $this->assertStringNotContainsString('<option', $body, 'Field options must come only from the injected catalog, never be hardcoded in the template.');
    }

    /** T9 — Internal notification persists exactly its canonical `message` field. */
    public function test_internal_notification_drawer_exposes_only_the_message_field(): void
    {
        $body = $this->templateBody($this->renderBuilder(), 'wf-node-form-internal_notification');

        $this->assertStringContainsString('data-field="message"', $body);
        $this->assertStringNotContainsString('data-field="recipient', $body, 'Recipients are the Business owner and active members with access — never a customer-chosen field (§10).');
    }

    /** T10 — Wait offers only the two contract-supported modes. */
    public function test_wait_drawer_offers_only_duration_and_until_datetime(): void
    {
        $body = $this->templateBody($this->renderBuilder(), 'wf-node-form-wait');

        $this->assertStringContainsString('value="duration"', $body);
        $this->assertStringContainsString('value="until_datetime"', $body);
        $this->assertStringNotContainsString('until_business_hours', $body, '§12: "Non-goal" for initial v2.');
    }

    /** T24 — If/Else offers only the §11 launch subject set, nothing invented. */
    public function test_if_else_drawer_offers_only_the_launch_condition_subjects(): void
    {
        // The subject list lives in the condition-ROW template
        // (wf-if-else-condition-row), cloned once per condition by
        // drawer.js — the outer wf-node-form-if_else template only holds
        // the match selector and the row container.
        $body = $this->templateBody($this->renderBuilder(), 'wf-if-else-condition-row');

        foreach (['contact.first_name', 'contact.last_name', 'contact.email', 'contact.company', 'contact.subscribed', 'contact.in_group'] as $subject) {
            $this->assertStringContainsString('value="' . $subject . '"', $body);
        }

        // Not launched: needs V2-F's inbound producer.
        $this->assertStringNotContainsString('replied_since_enrollment', $body);

        // Never invented — no Lead/Booking/Form/Payment/Tag/Pipeline domain.
        foreach (['lead.', 'booking.', 'form.', 'payment.', 'tag.', 'pipeline.', 'opportunity.'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $body, "If/Else must never offer an unsupported subject domain ({$forbidden}).");
        }
    }

    /** T13/T-WF-3 shape proof — End is configuration-free. */
    public function test_end_drawer_has_no_configurable_field(): void
    {
        $body = $this->templateBody($this->renderBuilder(), 'wf-node-form-end');

        $this->assertStringNotContainsString('data-field=', $body, 'NodeTypeRegistry::validateEnd() rejects any config at all.');
    }

    /** Trigger vocabulary is exactly the launch set — no message_received yet, no Forms/Calendar/Payment trigger. */
    public function test_trigger_drawer_offers_only_ingestable_trigger_types(): void
    {
        $body = $this->templateBody($this->renderBuilder(), 'wf-node-form-trigger');

        $this->assertStringContainsString('value="contact_created"', $body);
        $this->assertStringContainsString('value="contact_date_reached"', $body);
        $this->assertStringContainsString('value="manual_enrollment"', $body);
        $this->assertStringNotContainsString('value="message_received"', $body, 'Withheld until V2-F ships a real producer (WorkflowTriggerType::isIngestableInThisSlice()).');
        foreach (['form_submitted', 'appointment', 'payment', 'tag_added'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $body);
        }
    }
}
