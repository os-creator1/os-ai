<?php

namespace App\Library\Documents;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessLocation;
use App\Models\PlatformDatabaseNotification;
use App\Models\User;
use App\Notifications\Documents\DocumentActivityCenterNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Implementation Contract 17 §12.G, Blueprint §24 — the Activity Center READ
 * side for document/payment events. THE READ IS AUTHORITATIVE: a row exists
 * in `platform_database_notifications` for every Business-reachable user
 * SurfaceDocumentActivityInActivityCenter judged a candidate at write time,
 * but nothing here is shown to this actor without being re-derived fresh,
 * right now, exactly like every other authenticated Contract 17 surface
 * (§6.1):
 *
 *   1. current Business/Workspace tenancy reach (WorkspaceManager, the same
 *      authority every Business route uses);
 *   2. the `payments_contracts` capability (Gate, session-backed for the
 *      current actor — never a stored decision);
 *   3. the PaymentsContracts entitlement (EntitlementManager::decide());
 *   4. the referenced document still exists in that exact Business;
 *   5. LocationAccessGuard for its exact business_location_id, re-read fresh
 *      (Addendum §4: knowing an id never bypasses this).
 *
 * Any failure at any step — including one that only became true after the
 * notification was written, such as a revoked Location grant — omits the
 * row entirely: no title, no count contribution, no existence hint. This
 * mirrors AutomationActivitySource's own "return nothing rather than guess"
 * discipline on the read side of a different surface.
 *
 * Per-call memoization only (no cross-request cache): most of a customer's
 * unread window shares one Business, so repeating the same five checks per
 * row is avoided without inventing a persistent authorization cache.
 */
class DocumentActivityCenterReader
{
    /** How many unread rows to scan before giving up on filling $limit. Bounded, never unbounded. */
    private const SCAN_WINDOW = 50;

    public function __construct(
        private readonly WorkspaceManager $workspaces,
        private readonly LocationAccessGuard $locations,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /**
     * Every authorized-right-now unread row within the bounded scan window
     * (never the full table). One read serves both the badge count and the
     * dropdown's list — the caller takes as many as it wants to display and
     * counts the rest — mirroring the shared-read optimization the legacy
     * `notifications` query above it already applies.
     *
     * @return Collection<int, DocumentActivityCenterItem>
     */
    public function unreadFor(User $user): Collection
    {
        // The app registers a morph map (AppServiceProvider), so the stored
        // notifiable_type is the alias ('user'), not the raw class name —
        // getMorphClass() resolves that the same way Notifiable itself does.
        $rows = PlatformDatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->where('type', DocumentActivityCenterNotification::class)
            ->whereNull('read_at')
            ->latest()
            ->limit(self::SCAN_WINDOW)
            ->get();

        $businessCache = [];
        $entitlementCache = [];
        $locationCache = [];
        $items = [];

        foreach ($rows as $row) {
            $item = $this->authorize($user, $row, $businessCache, $entitlementCache, $locationCache);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return collect($items);
    }

    /**
     * @param  array<int, Business|false>  $businessCache
     * @param  array<string, bool>  $entitlementCache
     * @param  array<int, bool>  $locationCache
     */
    private function authorize(
        User $user,
        PlatformDatabaseNotification $row,
        array &$businessCache,
        array &$entitlementCache,
        array &$locationCache,
    ): ?DocumentActivityCenterItem {
        $data = is_array($row->data) ? $row->data : [];

        $businessId = (int) ($data['business_id'] ?? 0);
        $locationId = (int) ($data['business_location_id'] ?? 0);
        $documentId = (int) ($data['business_document_id'] ?? 0);
        $message = (string) ($data['message'] ?? '');

        if ($businessId <= 0 || $locationId <= 0 || $documentId <= 0) {
            return null;
        }

        // 1. Business/Workspace tenancy reach — re-derived, cached per Business.
        if (! array_key_exists($businessId, $businessCache)) {
            $businessCache[$businessId] = Business::find($businessId) ?? false;
        }

        $business = $businessCache[$businessId];

        if ($business === false || $business->workspace_id === null) {
            return null;
        }

        if (! $this->workspaces->userCanAccessBusiness((int) $user->id, $business)) {
            return null;
        }

        // 2. The single module capability.
        if (! Gate::forUser($user)->allows('payments_contracts')) {
            return null;
        }

        // 3. The PaymentsContracts entitlement.
        if (! array_key_exists($businessId, $entitlementCache)) {
            $workspace = $business->workspace;

            $entitlementCache[$businessId] = $workspace !== null
                && $this->entitlements->decide($workspace, $business, PlatformFeature::PaymentsContracts->value, (int) $user->id)->allowed;
        }

        if (! $entitlementCache[$businessId]) {
            return null;
        }

        // 4. The document must still exist in this exact Business.
        $document = BusinessDocument::where('id', $documentId)->where('business_id', $businessId)->first();

        if ($document === null) {
            return null;
        }

        // 5. LocationAccessGuard, re-read fresh — never trusted from the
        // write-time state, so a Location grant revoked since notification
        // creation refuses here exactly as any other read would.
        if (! array_key_exists($locationId, $locationCache)) {
            $location = BusinessLocation::where('id', $locationId)->where('business_id', $businessId)->first();

            $locationCache[$locationId] = $location !== null
                && $this->locations->userCanAccessLocation((int) $user->id, $location);
        }

        if (! $locationCache[$locationId]) {
            return null;
        }

        $workspace = $business->workspace;

        if ($workspace === null) {
            return null;
        }

        return new DocumentActivityCenterItem(
            notificationId: (string) $row->id,
            message: $message,
            url: route('customer.workspaces.businesses.documents.show', [$workspace->uid, $business->uid, $document->uid]),
            createdAt: $row->created_at,
        );
    }
}
