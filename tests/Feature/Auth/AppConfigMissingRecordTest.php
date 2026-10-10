<?php

namespace Tests\Feature\Auth;

use App\Helpers\Helper;
use App\Models\AppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A fresh database that has been migrated but not yet installed (`platform:install` seeds app_config)
 * has only the few app_config rows that migrations create, and not the optional ones such as
 * `custom_script`. A missing setting must read as "not set" instead of crashing public pages; this is what the login page did on the first staging deploy:
 * 'Attempt to read property "value" on null'.
 */
class AppConfigMissingRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_record_reads_as_null_not_an_error(): void
    {
        AppConfig::query()->delete();   # migrations seed a few rows; model "no record" explicitly
        $this->assertSame(0, AppConfig::query()->count());

        $this->assertNull(Helper::app_config('custom_script'));
        $this->assertNull(Helper::app_config('a_setting_that_never_existed'));
    }

    public function test_an_existing_record_still_returns_its_value(): void
    {
        AppConfig::create(['setting' => 'custom_script', 'value' => '<!-- site tag -->']);
        AppConfig::create(['setting' => 'license', 'value' => '']);

        $this->assertSame('<!-- site tag -->', Helper::app_config('custom_script'));
        $this->assertSame('', Helper::app_config('license'), 'An empty stored value is still a stored value.');
    }

    public function test_the_login_page_renders_on_a_migrated_but_not_installed_database(): void
    {
        AppConfig::query()->delete();
        $this->assertSame(0, AppConfig::query()->count());

        $this->get(route('login'))->assertOk();
    }
}
