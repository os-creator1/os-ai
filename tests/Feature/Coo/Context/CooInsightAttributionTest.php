<?php

namespace Tests\Feature\Coo\Context;

use App\Enums\Coo\CooInsightOrigin;
use App\Enums\Coo\CooScope;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\CooInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Coo\Insight\Concerns\CreatesCooInsightFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 19 §5.9 R-25/R-27, sub-slice 19.A — attribution is
 * write-once.
 *
 * The defect this prevents is subtle and would be invisible in a rendered
 * page: a cached answer quietly re-labelled as somebody else's, or re-scoped
 * to an audience it was never computed for, so the audit trail says a human
 * asked a question they never asked.
 */
class CooInsightAttributionTest extends TestCase
{
    use CreatesCooInsightFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCooInsights();
    }

    public function test_none_of_the_identity_or_attribution_columns_may_be_changed_after_insert(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $insight = $this->cachedInsight($business->fresh());

        $attempts = [
            'origin' => CooInsightOrigin::OnDemand->value,
            'actor_user_id' => (int) $customer->user_id,
            'audience_user_id' => (int) $customer->user_id + 1,
            'scope' => CooScope::Agency->value,
            'authorization_scope_fingerprint' => str_repeat('d', 64),
            'business_id' => 999,
            'workspace_id' => 999,
            'business_location_id' => 5,
        ];

        foreach ($attempts as $column => $value) {
            $fresh = CooInsight::query()->findOrFail($insight->id);
            $fresh->{$column} = $value;

            $this->assertThrows(fn () => $fresh->save(), InvalidArgumentException::class, 'coo_insights.' . $column . ' is immutable after insert');
        }

        $stored = CooInsight::query()->findOrFail($insight->id);

        $this->assertSame(CooInsightOrigin::System, $stored->origin);
        $this->assertNull($stored->actor_user_id);
        $this->assertSame((int) $customer->user_id, $stored->audience_user_id);
        $this->assertSame(CooScope::Business, $stored->scope);
    }

    /** R-27 — neither direction, because both would widen or narrow the audience. */
    public function test_a_system_row_is_never_promoted_and_an_on_demand_row_is_never_demoted(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $business = $business->fresh();

        $system = $this->cachedInsight($business);
        $onDemand = $this->cachedInsight($business, [
            'origin' => CooInsightOrigin::OnDemand->value,
            'actor_user_id' => (int) $customer->user_id,
            'audience_user_id' => null,
            'signal_fingerprint' => hash('sha256', 'a different window of facts'),
        ]);

        $this->assertThrows(
            fn () => $system->forceFill(['origin' => CooInsightOrigin::OnDemand->value, 'actor_user_id' => (int) $customer->user_id])->save(),
            InvalidArgumentException::class,
        );

        $this->assertThrows(
            fn () => $onDemand->forceFill(['origin' => CooInsightOrigin::System->value, 'actor_user_id' => null])->save(),
            InvalidArgumentException::class,
        );
    }

    /**
     * The mutable columns are exactly invalidation and the timestamps: a row
     * must still be able to stop being shown without its attribution moving.
     */
    public function test_invalidation_still_works_on_an_immutable_row(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $insight = $this->cachedInsight($business->fresh());

        app(\App\Library\Coo\Insight\CooInsightInvalidator::class)->invalidateContext((int) $business->id);

        $stored = CooInsight::query()->findOrFail($insight->id);

        $this->assertTrue($stored->isInvalidated());
        $this->assertNull($stored->actor_user_id, 'Invalidation never touches attribution.');
    }

    /**
     * A source-boundary check, because the model guard cannot see a query
     * builder update: no production code may write the write-once columns
     * anywhere except the one generator that creates the row.
     */
    public function test_no_production_code_updates_an_immutable_column(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'coo_insights') && ! str_contains($source, 'CooInsight')) {
                continue;
            }

            if (str_contains($file, 'CooInsightGenerator.php')) {
                continue; // The sole writer, and it only ever inserts.
            }

            foreach (CooInsight::IMMUTABLE_AFTER_INSERT as $column) {
                if (preg_match('/->update\(\s*\[[^\]]*[\'"]' . preg_quote($column, '/') . '[\'"]\s*=>/s', $source) === 1) {
                    $offenders[] = basename($file) . ' updates ' . $column;
                }
            }
        }

        $this->assertSame([], $offenders, 'Attribution and identity are written once, at insert, and never updated.');
    }

    /** @return array<int, string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
