<?php

namespace App\Library\Automation;

use App\Enums\Automation\AutomationTriggerType;
use App\Helpers\Helper;
use App\Models\Automation;
use App\Models\Business;
use App\Models\Contacts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * B4 Business Automations — resolves WHICH contacts are due for a given
 * automation (contract §6, §8). One method per trigger type; the caller
 * selects by a `match` on the code-backed enum. This class never claims
 * or sends — it only answers "who is due, and under which idempotency
 * key".
 */
class AutomationTriggerEvaluator
{
    /**
     * Contract §6.A — the bounded "before" offset vocabulary, inherited
     * from the legacy Automation::getDelayBeforeOptions() values.
     */
    public const OFFSET_ALLOWLIST = [
        '0 day', '1 day', '2 days', '3 days', '4 days', '5 days', '6 days',
        '1 week', '2 weeks', '1 month', '2 months',
    ];

    /**
     * Contract §6.A — CONTACT_DATE_REACHED. Timezone is the Business's own
     * (`businesses.timezone`), never the legacy per-automation column or a
     * user timezone. Returns [contact, occurrenceYear] pairs: the
     * occurrence year is that of the OFFSET-ADJUSTED local date (never the
     * calendar year of "now"), so a January send for a December-offset
     * occurrence cannot collide with the next year's key.
     *
     * @return array<int, array{contact: Contacts, occurrence_year: int}>
     */
    public function dueForDateReached(Automation $automation, Business $business, ?CarbonImmutable $now = null): array
    {
        if ($automation->trigger_type !== AutomationTriggerType::ContactDateReached) {
            return [];
        }

        $config = $automation->trigger_config ?? [];
        $groupId = isset($config['contact_group_id']) ? (int) $config['contact_group_id'] : null;
        $fieldId = isset($config['date_field_id']) ? (int) $config['date_field_id'] : null;
        $offset = (string) ($config['offset'] ?? '0 day');
        $sendAt = (string) ($config['send_at'] ?? '');

        if ($groupId === null || $fieldId === null || ! in_array($offset, self::OFFSET_ALLOWLIST, true) || ! preg_match('/^\d{2}:\d{2}$/', $sendAt)) {
            return [];
        }

        $timezone = $business->timezone ?: config('app.timezone', 'UTC');
        $localNow = ($now ?? CarbonImmutable::now())->setTimezone($timezone);

        // Time-of-day gate: nothing is due before the configured send_at
        // in the Business's local day (the five-minute sweep re-evaluates
        // safely because the idempotency key is durable).
        $sendAtToday = $localNow->setTimeFromTimeString($sendAt);

        if ($localNow->lessThan($sendAtToday)) {
            return [];
        }

        $occurrence = $localNow->modify('+' . $offset);
        $occurrenceMonthDay = $occurrence->format('m-d');
        $occurrenceYear = (int) $occurrence->format('Y');

        $contacts = Contacts::query()
            ->select('contacts.*')
            ->where('contacts.business_id', $business->id)
            ->where('contacts.group_id', $groupId)
            ->where('contacts.status', Contacts::STATUS_SUBSCRIBE)
            ->join('contacts_custom_field', 'contacts.id', '=', 'contacts_custom_field.contact_id')
            ->where('contacts_custom_field.field_id', $fieldId)
            ->where(
                DB::raw("DATE_FORMAT(STR_TO_DATE(" . Helper::table('contacts_custom_field.value') . ", '" . config('custom.date_format_sql') . "'), '%m-%d')"),
                '=',
                $occurrenceMonthDay,
            )
            ->get();

        return $contacts->map(fn (Contacts $contact) => ['contact' => $contact, 'occurrence_year' => $occurrenceYear])->all();
    }

    /**
     * Contract §6.B — CONTACT_CREATED. The automations that apply to a
     * freshly committed Business-scoped Contact: active, Business-matched,
     * and (if the definition restricts the audience) group-matched.
     */
    public function automationsForCreatedContact(Contacts $contact): Collection
    {
        if ($contact->business_id === null) {
            return new Collection();
        }

        return Automation::query()
            ->where('status', Automation::STATUS_ACTIVE)
            ->where('trigger_type', AutomationTriggerType::ContactCreated->value)
            ->where('business_id', $contact->business_id)
            ->get()
            ->filter(function (Automation $automation) use ($contact): bool {
                $groupId = $automation->trigger_config['contact_group_id'] ?? null;

                return $groupId === null || (int) $groupId === (int) $contact->group_id;
            })
            ->values();
    }
}
