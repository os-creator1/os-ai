<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contract 18 §10.2 / Sub-slice 18A — the SEO permission backfill.
 *
 * Customer permissions are declared in config/customer-permissions.php and
 * become Gates automatically, but they are PERSISTED per customer as a JSON
 * list in customers.permissions, written once at customer creation. Adding a
 * key to the config therefore grants it to no existing customer, and every
 * existing customer would be refused the SEO surface the moment it becomes
 * reachable. This idempotent data operation adds:
 *
 *   - view_seo   (default true)
 *   - manage_seo (default true)
 *
 * to (a) the AppConfig row `setting = 'customer_permissions'` when it exists
 * and (b) each existing customers.permissions JSON list that lacks them.
 *
 * `manage_search_console` is DELIBERATELY NEVER backfilled: it defaults to
 * false, is credential-class, and must be granted by the account owner.
 *
 * Copies 2026_09_09_120006's idiom exactly: query-builder only, no Eloquent
 * dependency, chunked, a NULL / empty / non-list value is left untouched
 * (never invented into a list), a permission already present is never
 * duplicated, and the operation is safe to re-run. down() is a deliberate
 * non-destructive no-op: removing a permission from a customer who may have
 * since been deliberately granted it would be data loss.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = ['view_seo', 'manage_seo'];

    public function up(): void
    {
        $this->backfillAppConfigDefault();
        $this->backfillExistingCustomers();
    }

    public function down(): void
    {
        // Intentionally a non-destructive no-op — see the class docblock.
    }

    private function backfillAppConfigDefault(): void
    {
        $row = DB::table('app_config')->where('setting', 'customer_permissions')->first();

        if ($row === null) {
            return;
        }

        $updated = $this->withPermissions($row->value);

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
                    $updated = $this->withPermissions($customer->permissions);

                    if ($updated === null) {
                        continue;
                    }

                    DB::table('customers')->where('id', $customer->id)->update(['permissions' => $updated]);
                }
            });
    }

    /**
     * Returns the re-encoded list with any missing SEO permission appended,
     * or null when nothing needs to change (NULL/empty/non-list input, or
     * every permission already present).
     */
    private function withPermissions(?string $rawJson): ?string
    {
        if ($rawJson === null || trim($rawJson) === '') {
            return null;
        }

        $decoded = json_decode($rawJson, true);

        if (! is_array($decoded) || $decoded === [] || ! array_is_list($decoded)) {
            return null;
        }

        $missing = array_values(array_diff(self::PERMISSIONS, $decoded));

        if ($missing === []) {
            return null;
        }

        return json_encode(array_values(array_merge($decoded, $missing)));
    }
};
