<?php

namespace App\Library\Migration\Ghl;

use Illuminate\Support\Carbon;

/**
 * Pure, side-effect-free transformation of a GoHighLevel snapshot into a
 * MotionGrove import plan. It touches no database and sends nothing, so it IS
 * the dry-run: the same plan is what a writer later applies, keyed by the
 * source GHL ids (idempotent retry) and carrying provenance on every record.
 *
 * Decisions encoded here (each is surfaced as an issue, never silently made):
 *  - Phones are normalised to E.164 (US default). Duplicate phones are NOT
 *    merged; the earliest contact is canonical and the rest are flagged.
 *  - A contact with GHL `dnd` set is treated as SUPPRESSED on every channel,
 *    even when per-channel statuses say "inactive" (the conservative reading).
 *  - Historical messages are always `historical => true`, `resend => false`,
 *    and `triggers_automation => false`.
 *  - Money is converted to minor units; a missing currency is flagged, not guessed.
 */
final class GhlImportPlanner
{
    /** GHL -> pack stage-name aliases (after emoji/punctuation normalisation). */
    private const STAGE_ALIASES = [
        'didnt pickup' => "Didn't Pick Up",
        'didnt pick up' => "Didn't Pick Up",
        'cold 7 days' => 'Cold (7+ days)',
        'cold 7 days)' => 'Cold (7+ days)',
    ];

    private const MESSAGE_KINDS = [
        'TYPE_SMS' => 'sms', 'TYPE_EMAIL' => 'email', 'TYPE_CALL' => 'call',
        'TYPE_INTERNAL_COMMENT' => 'note', 'TYPE_SMS_REACTION' => 'sms_reaction',
        'TYPE_ACTIVITY_OPPORTUNITY' => 'activity', 'TYPE_ACTIVITY_CONTACT' => 'activity',
    ];

    /**
     * @param  array<string, mixed>  $snapshot  dataset => rows (see GhlSnapshotExporter)
     * @return array<string, mixed>
     */
    public function plan(array $snapshot, string $defaultCountry = 'US'): array
    {
        $issues = [];
        $fieldNames = $this->index($snapshot['customFields'] ?? [], 'name');
        $contacts = $this->contacts($snapshot['contacts'] ?? [], $fieldNames, $defaultCountry, $issues);
        $pipelines = $this->pipelines($snapshot['pipelines'] ?? []);
        $opps = $this->opportunities($snapshot['opportunities'] ?? [], $pipelines, $contacts, $issues);
        $messages = $this->messages($snapshot['messages'] ?? [], $snapshot['conversations'] ?? [], $contacts, $issues);

        $conversations = $this->conversations($snapshot['conversations'] ?? [], $messages);

        return [
            'source' => ['system' => 'gohighlevel', 'location_id' => $snapshot['location']['id'] ?? null],
            'contacts' => array_values($contacts),
            'pipelines' => $pipelines,
            'opportunities' => $opps,
            'messages' => $messages,
            'conversations' => $conversations,
            'tags' => array_values(array_unique(array_merge([], ...array_values(array_map(fn ($c) => $c['tags'], $contacts))))),
            'issues' => $issues,
            'counts' => [
                'contacts' => count($contacts),
                'contacts_with_phone' => count(array_filter($contacts, fn ($c) => $c['phone_e164'] !== null)),
                'contacts_suppressed' => count(array_filter($contacts, fn ($c) => $c['consent']['sms'] === 'suppressed')),
                'opportunities' => count($opps),
                'conversations' => count($conversations),
                'messages_total' => count($messages),
                'messages_by_kind' => array_count_values(array_column($messages, 'kind')),
                'issues' => count($issues),
            ],
        ];
    }

    public static function normalizePhone(?string $raw, string $country = 'US'): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with((string) $raw, '+')) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
        }
        if ($country === 'US') {
            if (strlen($digits) === 10) {
                return '+1'.$digits;
            }
            if (strlen($digits) === 11 && $digits[0] === '1') {
                return '+'.$digits;
            }
        }

        return null;
    }

    public static function normalizeStageName(string $name): string
    {
        $plain = strtolower(preg_replace('/[^\p{L}\p{N} ]+/u', '', str_replace(['-', '>', '+', "'", '’'], [' ', ' ', ' ', '', ''], $name)));
        $plain = trim(preg_replace('/\s+/', ' ', $plain));

        return $plain;
    }

    /** @param list<array<string,mixed>> $rows @return array<string, array<string,mixed>> */
    private function index(array $rows, string $_label): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['id']] = $r;
        }

        return $out;
    }

    private function contacts(array $rows, array $fields, string $country, array &$issues): array
    {
        $out = [];
        $byPhone = [];
        usort($rows, fn ($a, $b) => strcmp((string) ($a['dateAdded'] ?? ''), (string) ($b['dateAdded'] ?? '')));

        foreach ($rows as $r) {
            $phone = self::normalizePhone($r['phone'] ?? null, $country);
            if (! empty($r['phone']) && $phone === null) {
                $issues[] = ['severity' => 'warning', 'code' => 'phone_invalid', 'source_id' => $r['id']];
            }
            if ($phone === null) {
                $issues[] = ['severity' => 'info', 'code' => 'contact_no_phone', 'source_id' => $r['id']];
            }
            $email = isset($r['email']) && $r['email'] !== '' ? strtolower(trim($r['email'])) : null;
            $dnd = (bool) ($r['dnd'] ?? false);
            if ($dnd) {
                $issues[] = ['severity' => 'review', 'code' => 'dnd_ambiguous_treated_as_suppressed', 'source_id' => $r['id'],
                    'detail' => 'dnd=true but per-channel statuses may read inactive; imported as suppressed until a human confirms.'];
            }

            $custom = [];
            foreach ($r['customFields'] ?? [] as $cf) {
                $name = $fields[$cf['id']]['name'] ?? null;
                if ($name === null) {
                    $issues[] = ['severity' => 'warning', 'code' => 'custom_field_unknown', 'source_id' => $r['id'], 'detail' => $cf['id']];
                    continue;
                }
                $custom[$name] = $cf['value'] ?? null;
            }

            $key = $phone;
            $duplicateOf = null;
            if ($key !== null) {
                if (isset($byPhone[$key])) {
                    $duplicateOf = $byPhone[$key];
                    $issues[] = ['severity' => 'review', 'code' => 'duplicate_phone', 'source_id' => $r['id'], 'detail' => "canonical={$duplicateOf}"];
                } else {
                    $byPhone[$key] = $r['id'];
                }
            }

            $out[$r['id']] = [
                'source_id' => $r['id'],
                'first_name' => $r['firstNameRaw'] ?? $r['firstName'] ?? null,
                'last_name' => $r['lastNameRaw'] ?? $r['lastName'] ?? null,
                'email' => $email,
                'phone_e164' => $phone,
                'tags' => array_values(array_map('strval', $r['tags'] ?? [])),
                'custom_fields' => $custom,
                'lead_source' => $r['source'] ?? null,
                'created_at' => $this->iso($r['dateAdded'] ?? null),
                'consent' => [
                    'sms' => $dnd ? 'suppressed' : 'unknown', 'email' => $dnd ? 'suppressed' : 'unknown', 'call' => $dnd ? 'suppressed' : 'unknown',
                    'basis' => $dnd ? 'ghl_dnd' : 'none_recorded',
                ],
                'duplicate_of' => $duplicateOf,
                'provenance' => ['system' => 'gohighlevel', 'id' => $r['id']],
            ];
        }

        return $out;
    }

    private function pipelines(array $rows): array
    {
        $out = [];
        foreach ($rows as $p) {
            $stages = [];
            foreach ($p['stages'] as $s) {
                $norm = self::normalizeStageName($s['name']);
                $stages[$s['id']] = ['source_id' => $s['id'], 'source_name' => $s['name'], 'name' => self::STAGE_ALIASES[$norm] ?? trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $s['name'])), 'position' => $s['position'] ?? 0];
            }
            $out[$p['id']] = ['source_id' => $p['id'], 'name' => $p['name'], 'stages' => $stages];
        }

        return $out;
    }

    private function opportunities(array $rows, array $pipelines, array $contacts, array &$issues): array
    {
        $out = [];
        foreach ($rows as $o) {
            $stage = $pipelines[$o['pipelineId']]['stages'][$o['pipelineStageId']] ?? null;
            if ($stage === null) {
                $issues[] = ['severity' => 'error', 'code' => 'opportunity_stage_unmapped', 'source_id' => $o['id']];
            }
            if (! isset($contacts[$o['contactId']])) {
                $issues[] = ['severity' => 'error', 'code' => 'opportunity_contact_missing', 'source_id' => $o['id']];
            }
            if (empty($o['currency'])) {
                $issues[] = ['severity' => 'info', 'code' => 'currency_missing_assumed_usd', 'source_id' => $o['id']];
            }
            $out[] = [
                'source_id' => $o['id'],
                'contact_source_id' => $o['contactId'],
                'pipeline' => $pipelines[$o['pipelineId']]['name'] ?? null,
                'stage' => $stage['name'] ?? null,
                'status' => $o['status'],
                'title' => $o['name'],
                'value_minor' => (int) round(((float) ($o['monetaryValue'] ?? 0)) * 100),
                'currency' => $o['currency'] ?? 'USD',
                'assigned_to_source_id' => $o['assignedTo'] ?? null,
                'created_at' => $this->iso($o['createdAt'] ?? null),
                'stage_changed_at' => $this->iso($o['lastStageChangeAt'] ?? null),
                'status_changed_at' => $this->iso($o['lastStatusChangeAt'] ?? null),
                'lead_source' => $o['source'] ?? null,
                'provenance' => ['system' => 'gohighlevel', 'id' => $o['id']],
                'triggers_automation' => false,
            ];
        }

        return $out;
    }

    private function messages(array $byConversation, array $conversations, array $contacts, array &$issues): array
    {
        $convContact = [];
        foreach ($conversations as $c) {
            $convContact[$c['id']] = $c['contactId'] ?? null;
        }
        $out = [];
        foreach ($byConversation as $convId => $rows) {
            foreach ($rows as $m) {
                $kind = self::MESSAGE_KINDS[$m['messageType'] ?? ''] ?? null;
                if ($kind === null) {
                    $issues[] = ['severity' => 'warning', 'code' => 'message_type_unmapped', 'source_id' => $m['id'], 'detail' => $m['messageType'] ?? ''];
                    continue;
                }
                $contactId = $m['contactId'] ?? $convContact[$convId] ?? null;
                if (! isset($contacts[$contactId])) {
                    $issues[] = ['severity' => 'error', 'code' => 'message_contact_missing', 'source_id' => $m['id']];
                    continue;
                }
                $out[] = [
                    'source_id' => $m['id'],
                    'conversation_source_id' => $convId,
                    'contact_source_id' => $contactId,
                    'kind' => $kind,
                    'direction' => ($m['direction'] ?? 'outbound') === 'inbound' ? 'inbound' : 'outbound',
                    'body' => $m['body'] ?? null,
                    'subject' => $m['meta']['email']['subject'] ?? null,
                    'from' => $m['from'] ?? null,
                    'to' => $m['to'] ?? null,
                    'occurred_at' => $this->iso($m['dateAdded'] ?? null),
                    'source_status' => $m['status'] ?? null,
                    'call_seconds' => $m['meta']['call']['duration'] ?? null,
                    'origin' => $m['source'] ?? null,
                    'provenance' => ['system' => 'gohighlevel', 'id' => $m['id']],
                    'historical' => true,
                    'resend' => false,
                    'triggers_automation' => false,
                ];
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $a['occurred_at'], (string) $b['occurred_at']));

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function conversations(array $rows, array $messages): array
    {
        $counts = array_count_values(array_column($messages, 'conversation_source_id'));
        $out = [];
        foreach ($rows as $c) {
            $out[] = [
                'source_id' => $c['id'],
                'contact_source_id' => $c['contactId'] ?? null,
                'message_count' => $counts[$c['id']] ?? 0,
                'provenance' => ['system' => 'gohighlevel', 'id' => $c['id']],
            ];
        }

        return $out;
    }

    private function iso(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (is_numeric($v) ? Carbon::createFromTimestampMsUTC((int) $v) : Carbon::parse($v)->utc())->toIso8601String();
    }
}
