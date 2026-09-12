<?php

namespace Tests\Feature\Business;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — T-LOC-9, the source-boundary inventory
 * (contract §7.3b).
 *
 * Enumerates every production seam in app/ that can raise a Business's
 * ACTIVE location count — a new row, or an archived row made active again —
 * and asserts the set is EXACTLY the approved list. Adding a new seam fails
 * this test, so it has to be a deliberate, reviewed change to this list.
 *
 * Stated honestly (contract §7.3b point 6): this guards repository
 * architecture. It does not, and cannot, prove that arbitrary future code
 * will never write to business_locations directly — that is exactly why
 * capacity is asserted inside the one boundary rather than trusted to it.
 */
class BusinessLocationBoundaryTest extends TestCase
{
    /**
     * Patterns that create a location row or make one active.
     */
    private const WRITE_PATTERNS = [
        'repository create/reactivate/upsert' => '/(?:locationRepository|BusinessLocationRepository::class\)?)\s*->\s*(createForBusiness|reactivate|upsertPrimary)\s*\(/',
        'relation create/make/save' => '/->\s*(?:locations|activeLocations|primaryLocation)\(\)\s*->\s*(create|make|save|saveMany|createMany|firstOrCreate|updateOrCreate)\s*\(/',
        'model static create' => '/BusinessLocation::(?:query\(\)\s*->\s*)?(create|insert|firstOrCreate|updateOrCreate|forceCreate)\s*\(/',
        'model new instance' => '/new\s+BusinessLocation\s*\(/',
        'query-builder insert' => "/DB::table\\(\\s*'business_locations'\\s*\\)\\s*->\\s*(insert\\w*|upsert)\\s*\\(/",
        'lifecycle activation' => '/->\s*lifecycle_state\s*=\s*BusinessLocationLifecycleState::Active/',
    ];

    /**
     * The approved inventory: file => the seams it may contain.
     */
    private const APPROVED = [
        'app/Library/Business/BusinessLocationManager.php' => [
            'repository create/reactivate/upsert:createForBusiness',
            'repository create/reactivate/upsert:reactivate',
            'repository create/reactivate/upsert:upsertPrimary',
        ],
        'app/Repositories/Eloquent/EloquentBusinessLocationRepository.php' => [
            'relation create/make/save:create',
            'relation create/make/save:make',
            'lifecycle activation:',
        ],
    ];

    public function test_the_production_location_write_seams_are_exactly_the_approved_inventory(): void
    {
        $found = [];

        foreach ((new Finder())->files()->in(base_path('app'))->name('*.php') as $file) {
            $relative = 'app/' . str_replace('\\', '/', $file->getRelativePathname());
            $source = $file->getContents();

            foreach (self::WRITE_PATTERNS as $label => $pattern) {
                if (preg_match_all($pattern, $source, $matches) > 0) {
                    foreach (array_unique($matches[1] ?? ['']) as $method) {
                        $found[$relative][] = $label . ':' . ($label === 'lifecycle activation' ? '' : $method);
                    }
                }
            }
        }

        foreach ($found as $file => $seams) {
            $found[$file] = array_values(array_unique($seams));
            sort($found[$file]);
        }

        $approved = self::APPROVED;

        foreach ($approved as $file => $seams) {
            sort($seams);
            $approved[$file] = $seams;
        }

        ksort($found);
        ksort($approved);

        $this->assertSame(
            $approved,
            $found,
            "The production seams that can add an active location changed. Route any new one through BusinessLocationManager and update this inventory deliberately.\nFound: " . json_encode($found, JSON_PRETTY_PRINT)
        );
    }

    public function test_only_the_canonical_boundary_calls_the_repository_methods_that_add_an_active_location(): void
    {
        foreach ((new Finder())->files()->in(base_path('app'))->name('*.php') as $file) {
            $relative = 'app/' . str_replace('\\', '/', $file->getRelativePathname());

            if (in_array($relative, ['app/Library/Business/BusinessLocationManager.php', 'app/Repositories/Eloquent/EloquentBusinessLocationRepository.php', 'app/Repositories/Contracts/BusinessLocationRepository.php'], true)) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/->\s*(upsertPrimary|createForBusiness)\s*\(/', $file->getContents(), "{$relative} adds a location outside BusinessLocationManager.");
        }
    }

    public function test_onboarding_reaches_the_canonical_boundary(): void
    {
        $source = file_get_contents(base_path('app/Library/Business/BusinessManager.php'));

        $this->assertMatchesRegularExpression('/BusinessLocationManager::class\)\s*->\s*upsertPrimaryLocation\(/', $source, 'The onboarding location step delegates to the canonical boundary.');
        $this->assertDoesNotMatchRegularExpression('/locationRepository\s*->\s*upsertPrimary\(/', $source);
    }

    public function test_the_boundary_asserts_capacity_before_every_activation(): void
    {
        $source = file_get_contents(base_path('app/Library/Business/BusinessLocationManager.php'));

        foreach (['createLocation', 'reactivateLocation', 'upsertPrimaryLocation'] as $method) {
            $body = $this->methodBody($source, $method);

            $this->assertMatchesRegularExpression('/lockBusiness(Of)?\(/', $body, "{$method} locks the Business first.");
            $this->assertStringContainsString('assertCanActivateAnotherLocation', $body, "{$method} asserts capacity.");
            $this->assertLessThan(
                strpos($body, $method === 'createLocation' ? 'createForBusiness' : ($method === 'reactivateLocation' ? '->reactivate(' : '->upsertPrimary(')),
                strpos($body, 'assertCanActivateAnotherLocation'),
                "{$method} asserts capacity before its write."
            );
        }
    }

    public function test_no_controller_writes_a_location_itself(): void
    {
        foreach ((new Finder())->files()->in(base_path('app/Http/Controllers'))->name('*.php') as $file) {
            foreach (self::WRITE_PATTERNS as $label => $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $file->getContents(), "{$file->getRelativePathname()} performs a location write ({$label}).");
            }
        }
    }

    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, "function {$method}(");
        $this->assertNotFalse($start, "{$method} exists.");
        $next = strpos($source, "\n    public function ", $start + 1);
        $private = strpos($source, "\n    private function ", $start + 1);
        $ends = array_filter([$next, $private], static fn ($position) => $position !== false);

        return substr($source, $start, ($ends === [] ? strlen($source) : min($ends)) - $start);
    }
}
