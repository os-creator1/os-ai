<?php

    use App\Models\AppConfig;
    use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $app_config = AppConfig::where('setting', 'terms_of_use')->first();

        if ( ! $app_config) {
            AppConfig::create([
                'setting' => 'terms_of_use',
                'value'   => '',
            ]);
        }
        $app_config = AppConfig::where('setting', 'privacy_policy')->first();

        if ( ! $app_config) {
            AppConfig::create([
                'setting' => 'privacy_policy',
                'value'   => '',
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app_config', function (Blueprint $table) {
            //
        });
    }
};
