<?php

namespace Tests\Feature\Navigation;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\Navigation\NavigationDispositionRegistry;
use Tests\TestCase;

/**
 * Customer navigation coherence pass, Phase 13 — the regression test that
 * stops a future PlatformFeatureRegistry flip from silently shipping a
 * feature with no customer-reachable destination.
 *
 * Deliberately NOT "every Available feature needs a top-level menu entry" —
 * NavigationDispositionRegistry's own docblock explains why AiCooBasic and
 * ProspectOutreach are correctly narrower than TopLevel. The only thing this
 * test enforces is that SOME deliberate choice was recorded for every
 * feature PlatformFeatureRegistry currently reports Available.
 */
class NavigationDispositionConsistencyTest extends TestCase
{
    public function test_every_available_platform_feature_has_a_declared_navigation_disposition(): void
    {
        $undeclared = [];

        foreach (PlatformFeature::cases() as $feature) {
            if (! PlatformFeatureRegistry::isAvailable($feature->value)) {
                continue;
            }

            if (! NavigationDispositionRegistry::has($feature->value)) {
                $undeclared[] = $feature->value;
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            'Available PlatformFeature(s) with no declared navigation disposition: [' . implode(', ', $undeclared) . ']. '
                . 'Add an entry to NavigationDispositionRegistry naming where a customer actually reaches this feature '
                . '(TopLevel, WithinModule, BusinessHomeOnly, SettingsOnly, AccountFrameOnly, or NoNavigation if that '
                . 'is a deliberate, reviewed choice) — an Available feature must never be reachable only by guessing a URL.',
        );
    }

    /**
     * The inverse failure mode: a stale entry naming a feature key that no
     * longer exists (a rename, or a key that was removed) would silently
     * rot forever otherwise.
     */
    public function test_the_registry_names_no_unknown_feature_key(): void
    {
        foreach (array_keys(NavigationDispositionRegistry::all()) as $featureKey) {
            $this->assertNotNull(
                PlatformFeature::tryFrom($featureKey),
                "NavigationDispositionRegistry names [{$featureKey}], which is not a known PlatformFeature key.",
            );
        }
    }
}
