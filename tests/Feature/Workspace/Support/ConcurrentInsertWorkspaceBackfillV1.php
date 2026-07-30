<?php

namespace Tests\Feature\Workspace\Support;

use App\Library\Workspace\Migration\WorkspaceBackfillV1;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test-only subclass simulating a Business created concurrently with a
 * backfill run (RFC-003 §10.4's "Deployment note") — inserted with a null
 * workspace_id under a customer_id the forward-only cursor has already
 * passed, so it is never picked up within the same run and the final
 * global null-count check correctly fails loudly instead of silently
 * succeeding. Reuses the already-protected processCustomerGroup() seam.
 */
class ConcurrentInsertWorkspaceBackfillV1 extends WorkspaceBackfillV1
{
    private bool $injected = false;

    protected function processCustomerGroup(int $customerId): array
    {
        $outcome = parent::processCustomerGroup($customerId);

        if (! $this->injected) {
            $this->injected = true;

            DB::table('businesses')->insert([
                'uid' => (string) Str::uuid(),
                'customer_id' => $customerId,
                'workspace_id' => null,
                'name' => 'Concurrently Created Business',
                'industry' => 'photo_booth_service',
                'country_code' => 'US',
                'timezone' => 'America/New_York',
                'currency_code' => 'USD',
                'status' => 'draft',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $outcome;
    }
}
