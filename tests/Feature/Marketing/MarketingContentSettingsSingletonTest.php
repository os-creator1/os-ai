<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingContentSettings;
use App\Repositories\Contracts\MarketingContentSettingsRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Review correction (P2): MarketingContentSettings::current() used to be a
 * plain `firstOrCreate([])` with no database-enforced singleton identity —
 * two concurrent first requests could each observe an empty table and each
 * insert a row. The 2026_10_11_120000 migration now gives the table a
 * unique `singleton_key` column, and
 * EloquentMarketingContentSettingsRepository::current() is the race-safe
 * app-level half: insert, and on a duplicate-key failure, re-read the
 * winner's row instead of erroring or creating a second one.
 */
class MarketingContentSettingsSingletonTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumeSuperAdminId();
        $this->ensureRequiredAppConfigRowsExist();
    }

    public function test_repeated_current_calls_resolve_the_same_logical_record(): void
    {
        $repository = app(MarketingContentSettingsRepository::class);

        $first = $repository->current();
        $second = $repository->current();

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('marketing_content_settings', 1);
    }

    public function test_current_creates_exactly_one_row_even_when_called_many_times(): void
    {
        $repository = app(MarketingContentSettingsRepository::class);

        for ($i = 0; $i < 5; $i++) {
            $repository->current();
        }

        $this->assertDatabaseCount('marketing_content_settings', 1);
    }

    /**
     * The database-layer half of the fix, proven directly: a second row
     * sharing the same singleton_key must be refused outright, not merely
     * discouraged by application code.
     */
    public function test_a_duplicate_singleton_key_is_refused_at_the_database_layer(): void
    {
        MarketingContentSettings::query()->create(['singleton_key' => MarketingContentSettings::SINGLETON_KEY]);

        $this->expectException(QueryException::class);

        MarketingContentSettings::query()->create(['singleton_key' => MarketingContentSettings::SINGLETON_KEY]);
    }

    /**
     * The app-level half, proven directly: even after a race loses the
     * insert to a duplicate-key error, current() still returns the
     * winner's row rather than throwing or leaving a second row behind.
     */
    public function test_current_recovers_from_a_lost_insert_race(): void
    {
        $winner = MarketingContentSettings::query()->create([
            'singleton_key' => MarketingContentSettings::SINGLETON_KEY,
            'hero_headline' => 'Set by the winning request',
        ]);

        $repository = app(MarketingContentSettingsRepository::class);
        $resolved = $repository->current();

        $this->assertSame($winner->id, $resolved->id);
        $this->assertSame('Set by the winning request', $resolved->hero_headline);
        $this->assertDatabaseCount('marketing_content_settings', 1);
    }

    /**
     * Determinism across surfaces: the admin editor's save and the public
     * homepage's read must resolve the exact same row, not two different
     * ones that happen to both call themselves "current".
     */
    public function test_admin_update_and_public_read_resolve_the_same_row(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post(route('admin.marketing-content.hero.update'), [
            'hero_headline' => 'Determinism check headline',
            'hero_subheadline' => 'Determinism check subheadline',
        ])->assertRedirect(route('admin.marketing-content.index'));

        $this->assertDatabaseCount('marketing_content_settings', 1);

        $repository = app(MarketingContentSettingsRepository::class);
        $resolved = $repository->current();

        $this->assertSame('Determinism check headline', $resolved->hero_headline);
        $this->assertSame('Determinism check subheadline', $resolved->hero_subheadline);
    }
}
