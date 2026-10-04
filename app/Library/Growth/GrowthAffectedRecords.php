<?php

declare(strict_types=1);

namespace App\Library\Growth;

use Illuminate\Support\Facades\DB;

/**
 * "Affected records" for the Opportunity detail page: the named records behind
 * a finding's bounded uid list (Growth Center §44, "View affected entities").
 *
 * The evidence stores only uids, never names — so a stored Opportunity holds
 * no contact data — and the names are looked up here, at view time, ONE query
 * per page, scoped to the Business AND to the Locations the actor may see.
 * A uid that no longer resolves (a deleted deal) is simply absent; a uid for a
 * Location the actor cannot reach never resolves.
 *
 * Only domains that have a meaningful, linkable record are resolved; any other
 * rule returns an empty list (the evidence figures already say everything).
 */
final class GrowthAffectedRecords
{
    /**
     * @param  array<int, string>  $uids
     * @return array<int, array{label: string, detail: string|null, url: string|null}>
     */
    public function resolve(string $domain, array $uids, int $businessId, GrowthViewer $viewer, string $workspaceUid, string $businessUid): array
    {
        $uids = array_slice(array_values(array_filter($uids, 'is_string')), 0, 10);

        if ($uids === []) {
            return [];
        }

        return match ($domain) {
            'crm' => $this->deals($uids, $businessId, $viewer, $workspaceUid, $businessUid),
            'documents' => $this->documents($uids, $businessId, $viewer, $workspaceUid, $businessUid),
            'conversations' => $this->conversations($uids, $businessId, $viewer, $workspaceUid, $businessUid),
            'booking' => $this->bookingTypes($uids, $businessId, $viewer, $workspaceUid, $businessUid),
            default => [],
        };
    }

    private function scoped($query, GrowthViewer $viewer, string $locationColumn)
    {
        if (! $viewer->fullAccess) {
            $query->where(function ($q) use ($viewer, $locationColumn): void {
                $q->whereNull($locationColumn);

                if ($viewer->accessibleLocationIds !== []) {
                    $q->orWhereIn($locationColumn, $viewer->accessibleLocationIds);
                }
            });
        }

        return $query;
    }

    private function deals(array $uids, int $businessId, GrowthViewer $viewer, string $w, string $b): array
    {
        $rows = $this->scoped(
            DB::table('crm_opportunities')->where('business_id', $businessId)->whereIn('uid', $uids),
            $viewer,
            'location_id',
        )->get(['uid', 'title', 'value_minor', 'currency_code', 'created_at']);

        return $rows->map(fn ($r) => [
            'label' => (string) $r->title,
            'detail' => GrowthMoney::format($r->value_minor === null ? null : (int) $r->value_minor, $r->currency_code),
            'url' => route('customer.workspaces.businesses.crm.opportunities.show', [$w, $b, $r->uid]),
        ])->all();
    }

    private function documents(array $uids, int $businessId, GrowthViewer $viewer, string $w, string $b): array
    {
        $rows = $this->scoped(
            DB::table('business_documents')->where('business_id', $businessId)->whereIn('uid', $uids),
            $viewer,
            'business_location_id',
        )->get(['uid', 'title', 'status', 'currency_code']);

        return $rows->map(fn ($r) => [
            'label' => (string) $r->title,
            'detail' => ucfirst((string) $r->status),
            'url' => route('customer.workspaces.businesses.documents.show', [$w, $b, $r->uid]),
        ])->all();
    }

    private function conversations(array $uids, int $businessId, GrowthViewer $viewer, string $w, string $b): array
    {
        $rows = $this->scoped(
            DB::table('chat_boxes')->where('business_id', $businessId)->whereIn('uid', $uids),
            $viewer,
            'location_id',
        )->get(['uid', 'to']);

        return $rows->map(fn ($r) => [
            'label' => 'Conversation with ' . $r->to,
            'detail' => null,
            'url' => route('customer.workspaces.businesses.conversations.index', [$w, $b]),
        ])->all();
    }

    private function bookingTypes(array $uids, int $businessId, GrowthViewer $viewer, string $w, string $b): array
    {
        $query = DB::table('booking_types as t')
            ->join('business_locations as l', 'l.id', '=', 't.business_location_id')
            ->where('l.business_id', $businessId)
            ->whereIn('t.uid', $uids);

        if (! $viewer->fullAccess) {
            $query->whereIn('t.business_location_id', $viewer->accessibleLocationIds === [] ? [0] : $viewer->accessibleLocationIds);
        }

        return $query->get(['t.uid', 't.name'])->map(fn ($r) => [
            'label' => (string) $r->name,
            'detail' => null,
            'url' => route('customer.workspaces.businesses.booking-types.edit', [$w, $b, $r->uid]),
        ])->all();
    }
}
