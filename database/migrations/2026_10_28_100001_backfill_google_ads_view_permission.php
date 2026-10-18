<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Google Ads Module V1 (contract 23 §7) — the `view_google_ads` backfill.
 *
 * Customer permissions are declared in config/customer-permissions.php and
 * become Gates automatically, but they are PERSISTED per customer as a JSON
 * list in customers.permissions, written once at customer creation. Adding a
 * key to the config therefore grants it to no existing customer, and every
 * existing customer would be refused the Ads pages the moment they become
 * reachable. This idempotent data operation adds `view_google_ads` (default
 * true) to (a) the AppConfig row `setting = 'customer_permissions'` when it
 * exists and (b) each existing customers.permissions JSON list that lacks it.
 *
 * `manage_google_ads` is DELIBERATELY NEVER backfilled: it defaults to false,
 * is credential-class (connect, choose the account, disconnect, settings,
 * refresh, every change made in Google Ads) and must be granted explicitly by
 * the account owner — the same rule as manage_search_console and
 * manage_google_business_profile.
 *
 * Copies 2026_09_24_100001's idiom exactly: query-builder only, chunked, a
 * NULL / empty / non-list value is left untouched (never invented into a
 * list), a permission already present is never duplicated, and it is safe to
 * re-run. down() is a deliberate non-destructive no-op.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = ['view_google_ads'];

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
     * Returns the re-encoded list with any missing permission appended, or
     * null when nothing needs to change (NULL/empty/non-list input, or every
     * permission already present).
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
