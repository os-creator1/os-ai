<?php

use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Contract 18 §7.3 / §8.1 — the Google connection product
 * discriminator. `business_google_connections` moves from ONE row per
 * Business (`bgc_business_unique` on `business_id` alone) to at most ONE
 * row per (business_id, product) pair, so a Business can hold an
 * independent connection per Google product it uses.
 *
 * BEHAVIOR-PRESERVING (the hard gate, §7.3, §15.B): every row that already
 * exists is backfilled `business_profile` by the column default itself —
 * no data migration statement is needed or run — so every existing GBP
 * connection's identity is completely unchanged. `bgc_id_business_unique`
 * (the composite key `business_google_locations`' own foreign key
 * depends on) is untouched.
 *
 * ROLLBACK WARNING inherited from the original table
 * (2026_09_09_120001_create_business_google_connections_table.php): this
 * migration's own down() only reverses the discriminator itself (restores
 * the single-row-per-Business uniqueness and drops the column); it does
 * not touch refresh_token_encrypted or any other stored authorization
 * material.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_google_connections', function (Blueprint $table) {
            $table->string('product', 24)
                ->default(GoogleConnectionProduct::BusinessProfile->value)
                ->after('business_id');
        });

        // bgc_business_unique isn't just C-1's uniqueness -- InnoDB also
        // relies on it as the supporting (leftmost-on-business_id) index
        // for this table's own business_id -> businesses(id) foreign key.
        // The replacement unique is added FIRST, so that requirement is
        // never left unsatisfied even for the instant between statements.
        Schema::table('business_google_connections', function (Blueprint $table) {
            $table->unique(['business_id', 'product'], 'bgc_business_product_unique');
        });

        Schema::table('business_google_connections', function (Blueprint $table) {
            $table->dropUnique('bgc_business_unique');
        });
    }

    public function down(): void
    {
        Schema::table('business_google_connections', function (Blueprint $table) {
            $table->unique('business_id', 'bgc_business_unique');
        });

        Schema::table('business_google_connections', function (Blueprint $table) {
            $table->dropUnique('bgc_business_product_unique');
        });

        Schema::table('business_google_connections', function (Blueprint $table) {
            $table->dropColumn('product');
        });
    }
};
