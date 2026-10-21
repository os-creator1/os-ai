<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One reservation row per hostname, across every feature that can claim a hostname.
 *
 * A custom website domain (`website_domains`) and an Agency's branded app domain (`agency_white_label_settings.app_domain`)
 * live in different tables, so neither table's own unique index can stop the other feature claiming the same name, and a
 * check-then-insert in either service is racy. This table is the single arbiter: its UNIQUE `domain` makes two
 * simultaneous claims — from the same feature or from different ones — resolve to exactly one winner at the database.
 *
 * Existing claims are back-filled, so an already-connected website domain is reserved from the moment this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 255)->unique();
            // website_domain | agency_app_domain
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id'], 'domain_reservations_owner_unique');
        });

        $this->backfill();
    }

    /** Public so it can be exercised on a populated database without re-running the DDL. */
    public function backfill(): void
    {
        $now = now();

        DB::table('website_domains')->select(['id', 'domain'])->orderBy('id')->each(function ($row) use ($now): void {
            $this->reserve('website_domain', $row->id, (string) $row->domain, $now);
        });

        DB::table('agency_white_label_settings')->whereNotNull('app_domain')->select(['id', 'app_domain'])->orderBy('id')->each(function ($row) use ($now): void {
            $this->reserve('agency_app_domain', $row->id, (string) $row->app_domain, $now);
        });
    }

    /**
     * An existing owner is NEVER displaced: the first claim (website domains, then app domains, by id) keeps the
     * hostname. A legacy duplicate — two owners already holding one name — cannot be resolved by a migration, so it is
     * left exactly as it is and reported for a human to decide.
     */
    private function reserve(string $ownerType, int|string $ownerId, string $domain, $now): void
    {
        $inserted = DB::table('domain_reservations')->insertOrIgnore([
            'domain' => strtolower($domain), 'owner_type' => $ownerType, 'owner_id' => $ownerId,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            Log::warning('domain_reservations back-fill: hostname already reserved by another owner; left unchanged', [
                'domain' => strtolower($domain), 'owner_type' => $ownerType, 'owner_id' => $ownerId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_reservations');
    }
};
