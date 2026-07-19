<?php

namespace Tests\Unit\Opportunity;

use Tests\TestCase;

class OpportunityConfigTest extends TestCase
{
    /**
     * No OPPORTUNITY_* variable is set in phpunit.xml or .env.testing, so
     * this reflects config/opportunity.php's own env() defaults actually
     * taking effect — not a runtime override.
     */
    public function test_defaults_are_disabled_and_use_safe_values(): void
    {
        $this->assertFalse(config('opportunity.enabled'));
        $this->assertSame('default', config('opportunity.queue'));
        $this->assertSame(30, config('opportunity.run_timeout_minutes'));
        $this->assertSame(100, config('opportunity.max_candidates_per_run'));
        $this->assertSame(15, config('opportunity.snooze_sweep_minutes'));
        $this->assertSame(1, config('opportunity.fingerprint_version'));
        $this->assertSame(1, config('opportunity.scoring_version'));
    }

    public function test_config_can_be_overridden_at_runtime(): void
    {
        config([
            'opportunity.enabled' => true,
            'opportunity.queue' => 'opportunity-engine',
            'opportunity.run_timeout_minutes' => 45,
            'opportunity.max_candidates_per_run' => 250,
            'opportunity.snooze_sweep_minutes' => 5,
        ]);

        $this->assertTrue(config('opportunity.enabled'));
        $this->assertSame('opportunity-engine', config('opportunity.queue'));
        $this->assertSame(45, config('opportunity.run_timeout_minutes'));
        $this->assertSame(250, config('opportunity.max_candidates_per_run'));
        $this->assertSame(5, config('opportunity.snooze_sweep_minutes'));
    }

    /**
     * fingerprint_version and scoring_version are trusted, source-controlled
     * algorithm versions (RFC-002 §14) — deliberately NOT env()-backed, so a
     * deployment's .env can never silently change canonicalization or
     * scoring behavior.
     */
    public function test_fingerprint_and_scoring_versions_are_not_env_backed(): void
    {
        $config = require base_path('config/opportunity.php');

        $this->assertSame(1, $config['fingerprint_version']);
        $this->assertSame(1, $config['scoring_version']);
    }

    /**
     * config:cache serializes the array every config file returns — this
     * proves config/opportunity.php is a pure static array safe for that.
     */
    public function test_config_file_is_a_pure_static_array_safe_for_config_caching(): void
    {
        $config = require base_path('config/opportunity.php');

        $this->assertIsArray($config);
        $this->assertSame(
            ['enabled', 'queue', 'run_timeout_minutes', 'max_candidates_per_run', 'snooze_sweep_minutes', 'fingerprint_version', 'scoring_version'],
            array_keys($config)
        );
        $this->assertIsBool($config['enabled']);
        $this->assertIsString($config['queue']);
        $this->assertIsInt($config['run_timeout_minutes']);
        $this->assertIsInt($config['max_candidates_per_run']);
        $this->assertIsInt($config['snooze_sweep_minutes']);
        $this->assertIsInt($config['fingerprint_version']);
        $this->assertIsInt($config['scoring_version']);
    }
}
