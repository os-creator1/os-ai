<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 19 §5.4(2)/§12.19.D — grant the new
 * `business_advisor` capability to customers that already exist.
 *
 * Why this is mechanically necessary: customer permissions are declared in
 * config/customer-permissions.php and turned into Gates by
 * AuthServiceProvider::boot(), but they are PERSISTED per customer as a JSON
 * list in customers.permissions, written ONCE at creation from
 * App\Models\Customer::customerPermissions() and read back by
 * EloquentAccountRepository::hasPermission()/durablePermissions(). Adding a
 * key to the config therefore grants it to FUTURE customers only; without
 * this backfill, 19.D's new capability gate would refuse every existing
 * customer on the Opportunity approve/execute routes — silently removing a
 * surface they use today, and breaking 19.D's own requirement that the
 * existing `add_phone` action keeps executing end-to-end unchanged.
 *
 * This follows
 * 2026_09_25_100013_backfill_payments_contracts_customer_permission.php and
 * 2026_09_09_120006_backfill_google_business_profile_view_permission.php
 * exactly, including their idempotency rules.
 *
 * THIS GRANTS NO NEW REACH. The capability is one link in the §5.4(2)
 * chain; tenancy, Location, entitlement (PlatformFeature::AiCooBasic), the
 * action hash, approval freshness and the idempotency claim all still
 * apply, and the engine kill switch still gates the whole surface. It
 * restores the status quo under a gate that did not exist before, rather
 * than opening anything.
 *
 * Idempotent: a list already containing the key is left untouched. A null,
 * empty or non-decodable permissions value is ALSO left untouched — this
 * migration never invents a permission list where none exists.
 *
 * down() is a non-destructive no-op, consistent with both precedents.
 */
return new class extends Migration
{
    private const PERMISSION = 'business_advisor';

    public function up(): void
    {
        $this->backfillAppConfigDefault();
        $this->backfillExistingCustomers();
    }

    public function down(): void
    {
        // Intentionally a non-destructive no-op — see the class docblock.
    }

    /**
     * The operator-editable default list new customers inherit (AppConfig
     * setting 'customer_permissions', read first by
     * Customer::customerPermissions()). Absent row => nothing to do; the
     * config default already applies.
     */
    private function backfillAppConfigDefault(): void
    {
        $row = DB::table('app_config')->where('setting', 'customer_permissions')->first();

        if ($row === null) {
            return;
        }

        $updated = $this->withPermission($row->value);

        if ($updated === null) {
            return;
        }

        DB::table('app_config')->where('setting', 'customer_permissions')->update(['value' => $updated]);
    }

    private function backfillExistingCustomers(): void
    {
        DB::table('customers')
            ->select('id', 'permissions')
            ->orderBy('id')
            ->chunkById(200, function ($customers) {
                foreach ($customers as $customer) {
                    $updated = $this->withPermission($customer->permissions);

                    if ($updated === null) {
                        continue;
                    }

                    DB::table('customers')->where('id', $customer->id)->update(['permissions' => $updated]);
                }
            });
    }

    /**
     * Returns the re-encoded JSON list with the permission appended, or null
     * when nothing should be written (already present, or the stored value is
     * not a decodable JSON array).
     */
    private function withPermission(?string $rawJson): ?string
    {
        if ($rawJson === null || trim($rawJson) === '') {
            return null;
        }

        $decoded = json_decode($rawJson, true);

        if (! is_array($decoded) || $decoded === [] || ! array_is_list($decoded)) {
            return null;
        }

        if (in_array(self::PERMISSION, $decoded, true)) {
            return null;
        }

        $decoded[] = self::PERMISSION;

        return json_encode(array_values($decoded));
    }
};
