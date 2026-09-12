<?php

namespace Tests\Unit\Coo;

use App\Enums\Coo\SignalDirection;
use App\Library\Coo\SignalComparator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Unified Business Home and COO Decision Engine contract §6.3 — T-COO-3.
 *
 * `SignalComparator::compare()` is pure: no database, no HTTP, no AI, and no
 * injected dependency — every assertion here calls the static method
 * directly with two integers and reads the result, nothing else. Every
 * threshold comes from `config('coo.materiality.*')` (defaults: min_volume
 * 10, min_relative_change 0.30), never a literal inside the class.
 */
class SignalComparatorTest extends TestCase
{
    // -----------------------------------------------------------------
    // Volume boundary — 9, exactly 10, 11 (relative change held safely
    // above the default 0.30 floor, so volume is the only variable)
    // -----------------------------------------------------------------

    public function test_volume_one_below_the_floor_is_insufficient_data_even_with_a_large_relative_change(): void
    {
        // max(9, 3) = 9 < 10. Relative change would be 6/3 = 2.0 — huge —
        // but the volume floor gates before relative change is even judged.
        $this->assertSame(SignalDirection::InsufficientData, SignalComparator::compare(9, 3));
    }

    public function test_volume_exactly_at_the_floor_is_not_insufficient_data(): void
    {
        // max(10, 3) = 10 >= 10 — the floor is met, not exceeded, and that
        // is enough. Relative change 7/3 = 2.33 clears the second threshold.
        $this->assertSame(SignalDirection::MaterialIncrease, SignalComparator::compare(10, 3));
    }

    public function test_volume_one_above_the_floor_is_not_insufficient_data(): void
    {
        $this->assertSame(SignalDirection::MaterialIncrease, SignalComparator::compare(11, 3));
    }

    // -----------------------------------------------------------------
    // Relative-change boundary — below 0.30, exactly 0.30, above 0.30
    // (volume held safely above the default floor of 10)
    // -----------------------------------------------------------------

    public function test_relative_change_below_the_threshold_is_stable(): void
    {
        // max(12, 10) = 12 >= 10. |12-10|/max(10,1) = 2/10 = 0.20 < 0.30.
        $this->assertSame(SignalDirection::Stable, SignalComparator::compare(12, 10));
    }

    public function test_relative_change_exactly_at_the_threshold_is_material_not_stable(): void
    {
        // |13-10|/max(10,1) = 3/10 = 0.30 exactly. The contract's threshold
        // is "greater than OR EQUAL", so this must NOT be stable.
        $this->assertSame(SignalDirection::MaterialIncrease, SignalComparator::compare(13, 10));
    }

    public function test_relative_change_above_the_threshold_is_material(): void
    {
        // |14-10|/max(10,1) = 4/10 = 0.40.
        $this->assertSame(SignalDirection::MaterialIncrease, SignalComparator::compare(14, 10));
    }

    // -----------------------------------------------------------------
    // Previous = 0 — the denominator is max(previous, 1), proved directly
    // -----------------------------------------------------------------

    public function test_both_zero_is_insufficient_data(): void
    {
        $this->assertSame(SignalDirection::InsufficientData, SignalComparator::compare(0, 0));
    }

    public function test_current_below_the_volume_floor_with_previous_zero_is_insufficient_data_not_an_infinite_change(): void
    {
        // max(5, 0) = 5 < 10. Without the volume floor, |5-0|/max(0,1) = 5.0
        // would look like a 500% "material" change; the floor correctly
        // refuses to judge it at all.
        $this->assertSame(SignalDirection::InsufficientData, SignalComparator::compare(5, 0));
    }

    public function test_current_at_the_volume_floor_with_previous_zero_is_a_material_increase(): void
    {
        // max(10, 0) = 10 >= 10 (the floor is met by current alone).
        // |10-0| / max(0, 1) = 10 / 1 = 10.0 >= 0.30 — division by the
        // substituted 1, not by zero, and the result is still correctly
        // classified as material rather than refused or crashing.
        $this->assertSame(SignalDirection::MaterialIncrease, SignalComparator::compare(10, 0));
    }

    // -----------------------------------------------------------------
    // Direction — equal, increase, decrease, non-material increase/decrease
    // -----------------------------------------------------------------

    public function test_equal_values_above_the_volume_floor_are_stable(): void
    {
        $this->assertSame(SignalDirection::Stable, SignalComparator::compare(15, 15));
    }

    public function test_material_increase(): void
    {
        // max(20, 10) = 20 >= 10. |20-10|/max(10,1) = 1.0 >= 0.30.
        $this->assertSame(SignalDirection::MaterialIncrease, SignalComparator::compare(20, 10));
    }

    public function test_material_decrease(): void
    {
        // max(10, 20) = 20 >= 10. |10-20|/max(20,1) = 0.5 >= 0.30.
        $this->assertSame(SignalDirection::MaterialDecrease, SignalComparator::compare(10, 20));
    }

    public function test_non_material_increase_is_stable_not_material_increase(): void
    {
        // A real increase (11 > 10) that does not clear the relative floor:
        // 1/10 = 0.10 < 0.30. Direction alone never implies materiality.
        $this->assertSame(SignalDirection::Stable, SignalComparator::compare(11, 10));
    }

    public function test_non_material_decrease_is_stable_not_material_decrease(): void
    {
        // 1/11 ≈ 0.0909 < 0.30.
        $this->assertSame(SignalDirection::Stable, SignalComparator::compare(10, 11));
    }

    // -----------------------------------------------------------------
    // Configuration-driven — no threshold is a literal in the class
    // -----------------------------------------------------------------

    public function test_a_lower_configured_volume_floor_changes_the_classification(): void
    {
        // Under the default floor (10) this pair is insufficient data
        // (proved above by test_volume_one_below_the_floor_...). Lowering
        // the configured floor to 5 must change the outcome without any
        // code change, proving the threshold is read from config.
        Config::set('coo.materiality.min_volume', 5);
        Config::set('coo.materiality.min_relative_change', 0.30);

        $this->assertSame(SignalDirection::MaterialIncrease, SignalComparator::compare(9, 3));
    }

    public function test_a_higher_configured_relative_change_floor_changes_the_classification(): void
    {
        // Under the default 0.30 this exact pair is a material increase
        // (proved above by test_relative_change_exactly_at_the_threshold_
        // is_material_not_stable: |13-10|/10 = 0.30). Raising the
        // configured floor to 0.50 must reclassify the same pair as stable.
        Config::set('coo.materiality.min_volume', 10);
        Config::set('coo.materiality.min_relative_change', 0.50);

        $this->assertSame(SignalDirection::Stable, SignalComparator::compare(13, 10));
    }

    public function test_defaults_apply_when_config_is_absent(): void
    {
        Config::set('coo.materiality', []);

        // Falls back to the method's own literal defaults (10, 0.30), which
        // must match config/coo.php's own defaults exactly.
        $this->assertSame(SignalDirection::InsufficientData, SignalComparator::compare(9, 3));
        $this->assertSame(SignalDirection::MaterialIncrease, SignalComparator::compare(10, 3));
    }
}
