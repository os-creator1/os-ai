<?php

namespace Tests\Unit\Migration;

use App\Library\Migration\Ghl\GhlImportPlanner;
use PHPUnit\Framework\TestCase;

/** Synthetic fixtures only — no real customer data. */
class GhlImportPlannerTest extends TestCase
{
    private function snapshot(): array
    {
        return [
            'location' => ['id' => 'LOC1'],
            'customFields' => [['id' => 'cf1', 'name' => 'Guest Count']],
            'pipelines' => [[
                'id' => 'P1', 'name' => 'Inquiries',
                'stages' => [
                    ['id' => 'S1', 'name' => '🎉 New Lead', 'position' => 0],
                    ['id' => 'S2', 'name' => "👀 Didn't Pick-up", 'position' => 1],
                    ['id' => 'S3', 'name' => '🥶 Cold (>7 days)', 'position' => 2],
                ],
            ]],
            'contacts' => [
                ['id' => 'C1', 'firstNameRaw' => 'Ann', 'lastNameRaw' => 'Lee', 'email' => ' ANN@Example.com ', 'phone' => '(720) 555-0101', 'dnd' => false, 'tags' => ['cold'], 'dateAdded' => '2026-01-02T00:00:00.000Z', 'customFields' => [['id' => 'cf1', 'value' => '50'], ['id' => 'zzz', 'value' => 'x']]],
                ['id' => 'C2', 'firstNameRaw' => 'Dup', 'phone' => '+17205550101', 'dateAdded' => '2026-02-02T00:00:00.000Z', 'dnd' => false],
                ['id' => 'C3', 'firstNameRaw' => 'Stop', 'phone' => '7205550103', 'dnd' => true, 'dateAdded' => '2026-03-02T00:00:00.000Z'],
                ['id' => 'C4', 'firstNameRaw' => 'NoPhone', 'dnd' => false, 'dateAdded' => '2026-04-02T00:00:00.000Z'],
            ],
            'opportunities' => [
                ['id' => 'O1', 'contactId' => 'C1', 'pipelineId' => 'P1', 'pipelineStageId' => 'S3', 'name' => 'Ann', 'status' => 'open', 'monetaryValue' => 500.5, 'createdAt' => '2026-05-01T10:00:00.000Z'],
                ['id' => 'O2', 'contactId' => 'GONE', 'pipelineId' => 'P1', 'pipelineStageId' => 'NOPE', 'name' => 'x', 'status' => 'lost'],
            ],
            'conversations' => [['id' => 'V1', 'contactId' => 'C1']],
            'messages' => ['V1' => [
                ['id' => 'M2', 'messageType' => 'TYPE_SMS', 'direction' => 'inbound', 'body' => 'hi', 'dateAdded' => '2026-05-02T10:00:00.000Z', 'contactId' => 'C1'],
                ['id' => 'M1', 'messageType' => 'TYPE_SMS', 'direction' => 'outbound', 'body' => 'hello', 'dateAdded' => '2026-05-01T10:00:00.000Z', 'status' => 'failed', 'source' => 'workflow'],
                ['id' => 'M3', 'messageType' => 'TYPE_WEIRD', 'dateAdded' => '2026-05-03T10:00:00.000Z'],
            ]],
        ];
    }

    public function test_phone_normalisation(): void
    {
        $this->assertSame('+17205550101', GhlImportPlanner::normalizePhone('(720) 555-0101'));
        $this->assertSame('+17205550101', GhlImportPlanner::normalizePhone('1-720-555-0101'));
        $this->assertSame('+447700900123', GhlImportPlanner::normalizePhone('+44 7700 900123'));
        $this->assertNull(GhlImportPlanner::normalizePhone('12345'));
        $this->assertNull(GhlImportPlanner::normalizePhone(null));
    }

    public function test_contacts_dedupe_consent_and_provenance(): void
    {
        $plan = (new GhlImportPlanner())->plan($this->snapshot());
        $byId = array_column($plan['contacts'], null, 'source_id');

        $this->assertSame('ann@example.com', $byId['C1']['email']);
        $this->assertSame(['Guest Count' => '50'], $byId['C1']['custom_fields']);
        $this->assertSame('C1', $byId['C2']['duplicate_of'], 'Earliest contact is canonical; duplicates are flagged, never auto-merged.');
        $this->assertNull($byId['C1']['duplicate_of']);
        $this->assertSame('suppressed', $byId['C3']['consent']['sms']);
        $this->assertSame('unknown', $byId['C1']['consent']['sms'], 'Absence of DND is not recorded consent.');
        $this->assertNull($byId['C4']['phone_e164']);
        $this->assertSame(['system' => 'gohighlevel', 'id' => 'C1'], $byId['C1']['provenance']);
        $this->assertSame(4, $plan['counts']['contacts']);
        $this->assertSame(3, $plan['counts']['contacts_with_phone']);
        $codes = array_column($plan['issues'], 'code');
        $this->assertContains('duplicate_phone', $codes);
        $this->assertContains('dnd_ambiguous_treated_as_suppressed', $codes);
        $this->assertContains('custom_field_unknown', $codes);
    }

    public function test_opportunity_stage_mapping_money_and_errors(): void
    {
        $plan = (new GhlImportPlanner())->plan($this->snapshot());
        $o = array_column($plan['opportunities'], null, 'source_id');

        $this->assertSame('Cold (7+ days)', $o['O1']['stage']);
        $this->assertSame(50050, $o['O1']['value_minor']);
        $this->assertFalse($o['O1']['triggers_automation']);
        $this->assertSame("Didn't Pick Up", $plan['pipelines']['P1']['stages']['S2']['name']);
        $this->assertSame('New Lead', $plan['pipelines']['P1']['stages']['S1']['name']);
        $codes = array_column($plan['issues'], 'code');
        $this->assertContains('opportunity_stage_unmapped', $codes);
        $this->assertContains('opportunity_contact_missing', $codes);
        $this->assertContains('currency_missing_assumed_usd', $codes);
    }

    public function test_messages_are_historical_ordered_and_never_resent(): void
    {
        $plan = (new GhlImportPlanner())->plan($this->snapshot());

        $this->assertSame(['M1', 'M2'], array_column($plan['messages'], 'source_id'), 'Chronological by original timestamp.');
        foreach ($plan['messages'] as $m) {
            $this->assertTrue($m['historical']);
            $this->assertFalse($m['resend']);
            $this->assertFalse($m['triggers_automation']);
            $this->assertSame('gohighlevel', $m['provenance']['system']);
        }
        $this->assertSame('outbound', $plan['messages'][0]['direction']);
        $this->assertSame('inbound', $plan['messages'][1]['direction']);
        $this->assertSame('2026-05-01T10:00:00+00:00', $plan['messages'][0]['occurred_at']);
        $this->assertContains('message_type_unmapped', array_column($plan['issues'], 'code'));
    }

    public function test_plan_is_deterministic_so_retries_are_idempotent(): void
    {
        $a = (new GhlImportPlanner())->plan($this->snapshot());
        $b = (new GhlImportPlanner())->plan($this->snapshot());
        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_the_stand_in_key_for_a_contact_without_a_phone_can_never_be_dialled(): void
    {
        foreach (['ykywMn0gkp6nF7Xmb3er', '17205550101ab', '99999999999999999999', 'QAn3kv5mqzIr872jmFQl'] as $id) {
            $key = \App\Library\Migration\Ghl\GhlImportWriter::noPhoneKey($id);

            $this->assertMatchesRegularExpression('/^np-[a-z]{16}$/', $key, 'Letters only: no digit survives any normaliser.');
            $this->assertLessThanOrEqual(20, strlen($key), 'Fits chat_boxes.to.');
            $this->assertSame('', preg_replace('/\D+/', '', $key));
            $this->assertSame($key, \App\Library\Migration\Ghl\GhlImportWriter::noPhoneKey($id), 'Stable, so a retry maps to the same contact.');
        }
    }
}
