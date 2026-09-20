<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 16 §6/§12.E, Sub-slice E — grant the new
 * `packages_products` capability to customers that already exist.
 *
 * Why this is mechanically necessary: customer permissions are declared in
 * config/customer-permissions.php and turned into Gates by
 * AuthServiceProvider::boot(), but they are PERSISTED per customer as a JSON
 * list in customers.permissions, written ONCE at creation from
 * App\Models\Customer::customerPermissions() and read back by
 * EloquentAccountRepository::hasPermission(). Adding a key to the config
 * therefore grants it to FUTURE customers only; without this backfill every
 * existing customer would be refused by the gate on a surface their plan
 * entitles them to. This follows
 * 2026_09_25_100013_backfill_payments_contracts_customer_permission.php and
 * 2026_09_09_120006_backfill_google_business_profile_view_permission.php
 * exactly.
 *
 * The capability is one link in the §6 chain and grants nothing on its own:
 * tenancy, EntitlementManager and (for Location-scoped actions)
 * LocationAccessGuard are each still checked independently.
 *
 * Idempotent: a list already containing the key is left untouched. A null,
 * empty or non-decodable permissions value is ALSO left untouched — this
 * migration never invents a permission list where none exists.
 *
 * down() is a non-destructive no-op, consistent with the precedents above.
 */
return new class extends Migration
{
    private const PERMISSION = 'packages_products';

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
