<?php

namespace Tests\Unit\Business;

use Tests\TestCase;

class BusinessConfigTest extends TestCase
{
    /**
     * No BUSINESS_ONBOARDING_* variable is set in phpunit.xml or
     * .env.testing, so this reflects config/business.php's own env()
     * defaults actually taking effect — not a runtime override.
     */
    public function test_defaults_are_disabled_and_use_the_default_queue(): void
    {
        $this->assertFalse(config('business.onboarding.enabled'));
        $this->assertFalse(config('business.onboarding.require_for_new_customers'));
        $this->assertSame('default', config('business.onboarding.analysis_queue'));
    }

    public function test_config_can_be_overridden_at_runtime(): void
    {
        config([
            'business.onboarding.enabled' => true,
            'business.onboarding.require_for_new_customers' => true,
            'business.onboarding.analysis_queue' => 'business-analysis',
        ]);

        $this->assertTrue(config('business.onboarding.enabled'));
        $this->assertTrue(config('business.onboarding.require_for_new_customers'));
        $this->assertSame('business-analysis', config('business.onboarding.analysis_queue'));
    }

    /**
     * config:cache serializes the array every config file returns — a file
     * that reads $_ENV/request/session state conditionally, rather than
     * only calling env() at the top level, would silently freeze stale
     * values. Loading it directly (bypassing the framework's already-booted
     * config repository) proves it is exactly that: a pure static array.
     */
    public function test_config_file_is_a_pure_static_array_safe_for_config_caching(): void
    {
        $config = require base_path('config/business.php');

        $this->assertIsArray($config);
        $this->assertSame(['onboarding'], array_keys($config));
        $this->assertSame(
            ['enabled', 'require_for_new_customers', 'analysis_queue'],
            array_keys($config['onboarding'])
        );
        $this->assertIsBool($config['onboarding']['enabled']);
        $this->assertIsBool($config['onboarding']['require_for_new_customers']);
        $this->assertIsString($config['onboarding']['analysis_queue']);
    }
}
