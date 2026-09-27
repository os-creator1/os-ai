<?php

namespace Tests\Feature\GoogleBusinessProfile;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * SEO Contract 18 §7.3 / §15.B — the source-boundary inventory for the
 * Google connection product discriminator.
 *
 * business_google_connections can now hold more than one row per Business
 * (one per GoogleConnectionProduct). Every production access to that table
 * — via the query builder or via the BusinessGoogleConnection model — must
 * therefore be scoped either by `product` (when it can return more than one
 * row for a Business) or by the row's own primary key `id` (which already
 * uniquely identifies a single product-specific row, so no further scoping
 * is needed). An access scoped by neither could silently match the wrong
 * product's row once a second row exists.
 *
 * This walks app/ once, finds every such access, and asserts each one is
 * scoped one of those two ways. Anything added later that touches the table
 * without either scope fails this test immediately, rather than surfacing
 * as a cross-product data leak.
 */
class GoogleConnectionProductBoundaryTest extends TestCase
{
    private const ANCHORS = [
        "/DB::table\\(\\s*'business_google_connections'\\s*\\)/",
        '/BusinessGoogleConnection::(?:query\(\)|create\()/',
    ];

    /**
     * How far past an anchor to look for its scoping clause. Generous
     * enough to span a chained query-builder statement or a create()
     * attribute array, never so wide it could bleed into the next
     * statement in these short methods.
     */
    private const WINDOW = 400;

    public function test_every_business_google_connections_access_is_scoped_by_product_or_primary_key(): void
    {
        $violations = [];
        $totalMatches = 0;

        foreach ((new Finder())->files()->in(base_path('app'))->name('*.php') as $file) {
            $relative = 'app/' . str_replace('\\', '/', $file->getRelativePathname());
            $source = $file->getContents();

            foreach (self::ANCHORS as $pattern) {
                if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($matches[0] as [$matchText, $offset]) {
                    $totalMatches++;
                    $window = substr($source, $offset, self::WINDOW);

                    $isProductScoped = str_contains($window, "'product'");
                    $isIdScoped = preg_match("/->where\\(\\s*'id',/", $window) === 1;

                    if (! $isProductScoped && ! $isIdScoped) {
                        $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                        $violations[] = "{$relative}:{$line} ({$matchText}) is scoped by neither 'product' nor a primary-key 'id' lookup.";
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            9,
            $totalMatches,
            'Expected to find the known business_google_connections access points; the scan patterns may be broken.',
        );

        $this->assertSame([], $violations, "Unscoped business_google_connections access found:\n" . implode("\n", $violations));
    }

    /**
     * The one place a business_google_locations row can be created:
     * GoogleBusinessProfileBindingManager::bind() must refuse to bind
     * against anything but a business_profile connection, because the
     * composite FK (business_google_connection_id, business_id) does not
     * itself encode product.
     */
    public function test_the_binding_manager_refuses_a_non_business_profile_connection(): void
    {
        $source = file_get_contents(base_path('app/Library/GoogleBusinessProfile/GoogleBusinessProfileBindingManager.php'));

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$connection->product\s*!==\s*GoogleConnectionProduct::BusinessProfile\s*\)\s*\{\s*throw new LogicException/s',
            $source,
            'bind() no longer guards against binding a non-business_profile connection.',
        );
    }
}
