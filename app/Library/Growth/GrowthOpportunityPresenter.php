<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Growth\GrowthCategory;
use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Library\Opportunity\OpportunityTypeRegistry;
use App\Models\BusinessLocation;
use App\Models\Opportunity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Turns a persisted Growth Opportunity into the owner-facing card (Growth
 * Center §11, §44). Every word that is not a number comes from the rule's
 * fixed copy; every number comes from the stored closed evidence. There is no
 * generated text here, so the page is fully useful with AI switched off.
 *
 * Rendering never queries per Opportunity: the Locations used in labels are
 * loaded once for the page (`locationNames`).
 */
final class GrowthOpportunityPresenter
{
    /**
     * @param  array<int, string>  $locationNames  Location id => name, loaded once
     * @return array<string, mixed>
     */
    public function present(Opportunity $o, array $locationNames, string $workspaceUid, string $businessUid, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rule = GrowthRuleRegistry::find($o->type);
        $definition = $rule?->definition();
        $evidence = $o->evidence[0]['observed_value'] ?? [];
        $evidence = is_array($evidence) ? $evidence : [];
        $meta = OpportunityTypeRegistry::get($o->worker_key->value, $o->type)['growth'] ?? [];
        $locationName = $o->location_id !== null ? ($locationNames[(int) $o->location_id] ?? null) : null;

        return [
            'uid' => $o->uid,
            'type' => $o->type,
            'category' => $definition?->category->value,
            'category_label' => $definition?->category->label() ?? 'Growth',
            'source_module' => $definition?->sourceModule,
            'title' => $o->title,
            // A resolved item's headline is history, not a live claim: "3 leads have had no reply" must not read as true today.
            'headline' => ($this->state($o) === GrowthOpportunityReader::STATE_RESOLVED ? 'Earlier: ' : '') . ($rule?->headline($evidence) ?? $o->title),
            'summary' => $o->summary,
            'why' => $meta['why'] ?? null,
            'expected' => $meta['expected'] ?? null,
            'impact' => $this->impactLevel((int) $o->impact),
            'confidence' => $this->confidenceLabel((float) $o->confidence),
            'priority_score' => (int) $o->priority_score,
            'state' => $this->state($o),
            'state_label' => $this->stateLabel($this->state($o)),
            'location_id' => $o->location_id,
            'location_name' => $locationName,
            'age_label' => $this->ageLabel($o->first_detected_at, $now),
            'first_detected_at' => $o->first_detected_at,
            'last_confirmed_at' => $o->last_confirmed_at,
            'resolved_at' => $o->stale_at ?? $o->completed_at,
            'snoozed_until' => $o->snoozed_until,
            'occurrence_number' => (int) $o->occurrence_number,
            'evidence' => $this->evidenceView($evidence),
            'evidence_summary' => $o->evidence[0]['summary'] ?? null,
            'evidence_retrieved_at' => isset($o->evidence[0]['retrieved_at']) ? CarbonImmutable::parse($o->evidence[0]['retrieved_at']) : null,
            'action_label' => $meta['action_label'] ?? 'Open',
            'detail_url' => route('customer.workspaces.businesses.growth.opportunities.show', [$workspaceUid, $businessUid, $o->uid]),
            'action_url' => isset($meta['target']) ? GrowthNavigation::url($meta['target'], $workspaceUid, $businessUid) : null,
            'safety_class' => $meta['safety_class'] ?? 'read_only',
            'rule_key' => $o->type,
        ];
    }

    /** High / Medium / Low — the engine's 0-5 impact rank in owner words. */
    public function impactLevel(int $impact): string
    {
        return match (true) {
            $impact >= 4 => 'High',
            $impact === 3 => 'Medium',
            default => 'Low',
        };
    }

    /** No pseudo-precision: three honest labels, never "92.7%". */
    public function confidenceLabel(float $confidence): string
    {
        return match (true) {
            $confidence >= 0.95 => 'High confidence',
            $confidence >= 0.75 => 'Moderate confidence',
            default => 'Limited data',
        };
    }

    /** The owner-facing state (see GrowthOpportunityReader::applyState). */
    public function state(Opportunity $o): string
    {
        $status = $o->status;

        if ($status === OpportunityStatus::Completed) {
            return GrowthOpportunityReader::STATE_RESOLVED;
        }

        if ($status === OpportunityStatus::Dismissed) {
            return GrowthOpportunityReader::STATE_DISMISSED;
        }

        if ($o->freshness === OpportunityFreshness::Stale) {
            return GrowthOpportunityReader::STATE_RESOLVED;
        }

        return match ($status) {
            OpportunityStatus::Snoozed => GrowthOpportunityReader::STATE_SNOOZED,
            OpportunityStatus::InProgress => GrowthOpportunityReader::STATE_IN_PROGRESS,
            default => GrowthOpportunityReader::STATE_OPEN,
        };
    }

    public function stateLabel(string $state): string
    {
        return match ($state) {
            GrowthOpportunityReader::STATE_RESOLVED => 'Resolved',
            GrowthOpportunityReader::STATE_DISMISSED => 'Dismissed',
            GrowthOpportunityReader::STATE_SNOOZED => 'Snoozed',
            GrowthOpportunityReader::STATE_IN_PROGRESS => 'In progress',
            default => 'Open',
        };
    }

    /**
     * The closed evidence, in display form: count, optional money, thresholds.
     * Nothing outside the known keys is passed through.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private function evidenceView(array $e): array
    {
        $view = [];

        foreach (['count', 'oldest_hours', 'threshold_hours', 'threshold_days', 'window_days', 'critical', 'warning', 'open_minutes', 'weekly_minutes', 'booked_minutes', 'workflows', 'directories'] as $key) {
            if (isset($e[$key]) && is_numeric($e[$key])) {
                $view[$key] = (int) $e[$key];
            }
        }

        $money = GrowthMoney::format($e['value_minor'] ?? null, $e['currency'] ?? null);

        if ($money !== null) {
            $view['value'] = $money;
        }

        if (isset($e['phrases']) && is_array($e['phrases'])) {
            $view['phrases'] = array_values(array_map('strval', $e['phrases']));
        }

        $view['uids'] = isset($e['uids']) && is_array($e['uids']) ? array_values(array_map('strval', $e['uids'])) : [];

        return $view;
    }

    private function ageLabel(?CarbonInterface $detected, CarbonInterface $now): string
    {
        if ($detected === null) {
            return '';
        }

        $days = (int) floor($detected->diffInDays($now));

        return match (true) {
            $days <= 0 => 'Detected today',
            $days === 1 => 'Open for 1 day',
            default => 'Open for ' . $days . ' days',
        };
    }

    /** @return array<int, string> Location id => name, one query for the whole page */
    public static function locationNames(int $businessId): array
    {
        return BusinessLocation::query()->where('business_id', $businessId)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }
}
