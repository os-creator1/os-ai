<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Generation + Hosting Slice B (custom domains) — the one table
 * the hosting contract's §40 named but deliberately did not design. No
 * `business_id` column, matching every other Website-owned table:
 * tenancy is reached by joining through `websites.business_id`, never
 * duplicated here.
 *
 * `domain` is globally unique across every Website — the DB-level
 * constraint is the last line of defense against a takeover race;
 * WebsiteDomainService additionally locks and re-checks inside a
 * transaction before ever inserting.
 *
 * Every domain, primary or alias, goes through the same lifecycle
 * (pending_verification -> verified -> provisioning -> active, or
 * -> failed at any step) and needs its OWN certificate, since TLS is
 * per-hostname.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_domains', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('domain', 255)->unique();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 24)->default('pending_verification');
            $table->string('verification_token', 64);
            $table->string('failure_reason', 255)->nullable();
            // The Forge domain resource id — Forge's current per-domain
            // model requires this to request/check/remove a certificate
            // or to remove the domain itself; persisted the instant
            // attachDomain() succeeds (before the certificate step even
            // runs) so a later retry or removal never orphans a Forge-side
            // domain this row has already forgotten about.
            $table->string('forge_domain_id', 120)->nullable();
            $table->string('certificate_reference', 120)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index('website_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_domains');
    }
};
