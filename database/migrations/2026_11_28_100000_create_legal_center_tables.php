<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MotionGrove Legal Center.
 *
 * legal_company_profiles   Append-only revisions of the platform operator's own legal identity. The
 *                          current row is the highest revision; a save inserts a new row and never edits
 *                          an old one, so every earlier identity (and registered office) stays on record.
 *                          Not a tenant, customer, location or Agency address.
 * legal_document_snapshots The exact rendered text of a legal document at one company revision, keyed by
 *                          content hash. Written the first time that exact text is accepted.
 * legal_acceptances        One affirmative acceptance: who, where, which document version, which snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_company_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('revision')->unique();
            $table->string('brand_name', 120);
            $table->string('legal_entity', 190);
            $table->string('company_number', 60);
            $table->string('registered_address', 500);
            $table->string('country', 120);
            $table->string('legal_email', 190);
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('legal_company_profiles')->insert([
            'revision' => 1,
            'brand_name' => 'MotionGrove',
            'legal_entity' => 'Jazmin medija, MB',
            'company_number' => '306304073',
            'registered_address' => 'Bičių g. 23, LT-11111 Vilnius, Lithuania',
            'country' => 'Lithuania',
            'legal_email' => 'legal@getmotiongrove.com',
            'updated_by_user_id' => null,
            'created_at' => now(),
        ]);

        Schema::create('legal_document_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 60);
            $table->string('version', 40);
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('company_profile_id');
            $table->longText('rendered_html');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['slug', 'version', 'content_hash']);
        });

        Schema::create('legal_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('document_slug', 60);
            $table->string('document_version', 40);
            $table->unsignedBigInteger('snapshot_id');
            $table->string('context', 60);
            $table->string('offer_ref', 120)->nullable();
            $table->timestamp('accepted_at')->useCurrent();

            $table->index(['user_id', 'document_slug']);
            $table->index('snapshot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('legal_document_snapshots');
        Schema::dropIfExists('legal_company_profiles');
    }
};
