<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Two-record custom domains (A @ + a unique CNAME on www), fully automatic HTTPS.
 *
 * `verification_token` stops being a TXT value: it is now the unique, unpredictable label of this domain's own CNAME
 * target (`<token>.<FORGE_CNAME_ZONE>`), which is also the ownership proof. Rows that are not live get a fresh
 * lower-case token, and any that had only reached Verified/Provisioning under the old TXT proof go back to waiting for
 * DNS: ownership is re-proven under the new rule before anything is provisioned for them. Failed rows stay Failed
 * until the owner (or `website-domains:recover`) retries them; Active, Removing rows and every Forge resource id,
 * certificate reference and hostname reservation are left exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_domains', function (Blueprint $table): void {
            $table->string('www_forge_domain_id', 120)->nullable()->after('certificate_reference');
            $table->string('www_certificate_reference', 120)->nullable()->after('www_forge_domain_id');
            $table->boolean('dns_a_ok')->default(false)->after('last_checked_at');
            $table->boolean('dns_cname_ok')->default(false)->after('dns_a_ok');
            $table->unsignedSmallInteger('attempts')->default(0)->after('dns_cname_ok');
            $table->unsignedSmallInteger('certificate_attempts')->default(0)->after('attempts');
            $table->timestamp('next_attempt_at')->nullable()->after('certificate_attempts');
            $table->timestamp('processing_until')->nullable()->after('next_attempt_at');
            $table->index(['status', 'next_attempt_at'], 'website_domains_status_next_attempt_index');
        });

        $used = [];

        DB::table('website_domains')->whereNotIn('status', ['active', 'removing'])->orderBy('id')->each(function ($row) use (&$used): void {
            do {
                $token = strtolower(Str::random(32));
            } while (isset($used[$token]));
            $used[$token] = true;

            DB::table('website_domains')->where('id', $row->id)->update([
                'verification_token' => $token,
                'status' => in_array($row->status, ['verified', 'provisioning'], true) ? 'pending_verification' : $row->status,
            ]);
        });

        DB::table('website_domains')->where('status', 'pending_verification')->update(['verified_at' => null]);
    }

    public function down(): void
    {
        Schema::table('website_domains', function (Blueprint $table): void {
            $table->dropIndex('website_domains_status_next_attempt_index');
            $table->dropColumn(['www_forge_domain_id', 'www_certificate_reference', 'dns_a_ok', 'dns_cname_ok', 'attempts', 'certificate_attempts', 'next_attempt_at', 'processing_until']);
        });
    }
};
