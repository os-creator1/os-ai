<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\LocationAccessDeniedException;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessLocation;
use Illuminate\Support\Facades\Auth;

/**
 * The ONE authorization chain every Business-scoped document action runs, shared
 * by DocumentsController and DocumentEditorController so it cannot drift:
 *
 *   Workspace -> Business -> access -> Active -> `payments_contracts` capability
 *   -> the PaymentsContracts entitlement -> the document inside THIS Business
 *   -> LocationAccessGuard on the document's Location.
 *
 * A foreign Business's document, a missing one and one in a Location the actor
 * cannot reach all answer the same 404. The using class provides
 * `$this->entitlements` (EntitlementManager) and `$this->locations`
 * (LocationAccessGuard).
 */
trait ResolvesBusinessDocuments
{
    use ResolvesBusinessTenancy;

    private function document(string $workspaceUid, string $businessUid, string $documentUid): BusinessDocument
    {
        $business = $this->business($workspaceUid, $businessUid);
        $document = BusinessDocument::where('business_id', $business->id)->where('uid', $documentUid)->first() ?? abort(404);
        $location = BusinessLocation::where('business_id', $business->id)->find($document->business_location_id) ?? abort(404);
        $this->location($location);
        return $document;
    }

    private function business(string $workspaceUid, string $businessUid): Business
    {
        [$workspace, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->authorize('payments_contracts');
        abort_unless($this->entitlementAllows($workspace, $business), 404);
        return $business;
    }

    protected function entitlementAllows(\App\Models\Workspace $workspace, Business $business): bool
    {
        return $this->entitlements->decide($workspace, $business, PlatformFeature::PaymentsContracts->value, (int) Auth::id())->allowed;
    }

    private function location(BusinessLocation $location): void
    {
        try {
            $this->locations->assertUserCanAccessLocation((int) Auth::id(), $location);
        } catch (LocationAccessDeniedException) {
            abort(404);
        }
    }
}
