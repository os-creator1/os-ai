<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White Label — Branding tab. Purely additive: every column is nullable (or
 * defaulted), so an existing Agency's saved identity, and every existing client
 * that resolves it, behaves exactly as before.
 *
 * The app domain is only RECORDED and ownership-verified here. Nothing in this
 * migration (or the code that reads it) makes the platform answer on that host;
 * `app_domain_status` therefore tops out at `verified` until a later, separately
 * authorised infrastructure step activates routing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_white_label_settings', function (Blueprint $table): void {
            $table->char('secondary_color', 7)->nullable()->after('accent_color');
            $table->string('logo_dark_path', 191)->nullable()->after('logo_path');
            $table->string('logo_compact_path', 191)->nullable()->after('logo_dark_path');
            $table->string('favicon_path', 191)->nullable()->after('logo_compact_path');
            $table->string('support_url', 255)->nullable()->after('support_email');
            $table->string('legal_name', 120)->nullable()->after('support_url');
            $table->json('social_links')->nullable()->after('legal_name');

            $table->string('app_domain', 191)->nullable()->after('social_links');
            $table->string('app_domain_token', 64)->nullable()->after('app_domain');
            // none | pending_verification | verified
            $table->string('app_domain_status', 24)->default('none')->after('app_domain_token');
            $table->timestamp('app_domain_verified_at')->nullable()->after('app_domain_status');
            $table->timestamp('app_domain_checked_at')->nullable()->after('app_domain_verified_at');

            $table->unique('app_domain', 'agency_white_label_settings_app_domain_unique');
        });
    }

    public function down(): void
    {
        Schema::table('agency_white_label_settings', function (Blueprint $table): void {
            $table->dropUnique('agency_white_label_settings_app_domain_unique');
            $table->dropColumn([
                'secondary_color', 'logo_dark_path', 'logo_compact_path', 'favicon_path', 'support_url',
                'legal_name', 'social_links', 'app_domain', 'app_domain_token', 'app_domain_status',
                'app_domain_verified_at', 'app_domain_checked_at',
            ]);
        });
    }
};
