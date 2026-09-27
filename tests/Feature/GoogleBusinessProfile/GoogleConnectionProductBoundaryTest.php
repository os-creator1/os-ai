<?php

namespace Tests\Feature\GoogleBusinessProfile;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * SEO Contract 18 §7.3 / §15.B — the source-boundary inventory for the
 * Google connection product discriminator.
 *
 * WHY THIS TEST WAS STRENGTHENED. Its first version accepted a primary-key
 * lookup (`->where('id', $connection->id)`) as sufficient scoping, on the
 * reasoning that an id already identifies one product-specific row. That is
 * true and beside the point: it proves the statement touches exactly one
 * row, NOT that the row is a Business Profile one. Where the id comes from
 * a connection the caller handed in, a `search_console` row flows straight
 * through — which is exactly how revoke(), disconnect() and accessTokenFor()
 * could wipe or spend a Search Console grant. An id proves uniqueness;
 * only a `product` predicate, or a guard on the way in, proves product.
 *
 * So there are now two independent obligations, and a primary key satisfies
 * neither on its own:
 *
 *   1. EVERY production access to `business_google_connections` is scoped by
 *      `product` — including the raw primary-key writes.
 *   2. EVERY public method of Business-Profile-specific code that ACCEPTS a
 *      connection refuses a non-`business_profile` row before doing
 *      anything. This is the half a query-shape scan can never see, because
 *      the dangerous call never queries the table at all — it just uses the
 *      object it was given.
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

    /**
     * The ONLY files allowed to touch the table without a `product`
     * predicate, each with the reason it is safe.
     *
     * `GoogleBusinessProfileCallBudget` takes a row lock by id purely to
     * serialize per-Business call accounting. It reads no product-specific
     * field, writes nothing to the row, and §7.3 makes the budget part of
     * the SHARED Google authority stack that Search Console will reuse — so
     * pinning it to `business_profile` would be wrong, not safer. Every GBP
     * path that reaches it has already passed the guard asserted below.
     */
    private const PRODUCT_NEUTRAL_FILES = [
        'app/Library/GoogleBusinessProfile/GoogleBusinessProfileCallBudget.php',
    ];

    /**
     * Business-Profile-specific classes, and the public methods of each that
     * are handed a connection. Every one must refuse a foreign product.
     *
     * @var array<string, array<int, string>>
     */
    private const GUARDED_ENTRY_POINTS = [
        'app/Library/GoogleBusinessProfile/GoogleBusinessProfileConnectionManager.php' => [
            'attemptBelongsToActor',
            'completeConnect',
            'accessTokenFor',
            'revoke',
            'disconnect',
            'claimRefresh',
            'releaseRefreshClaim',
            'markFailure',
        ],
        'app/Library/GoogleBusinessProfile/GoogleBusinessProfileEnumerator.php' => ['enumerate'],
        'app/Library/GoogleBusinessProfile/GoogleBusinessProfileMirrorService.php' => ['refresh'],
        'app/Library/GoogleBusinessProfile/GoogleBusinessProfileCandidateTokenSigner.php' => ['issue', 'verify'],
    ];

    public function test_every_business_google_connections_access_is_scoped_by_product(): void
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

                    if (in_array($relative, self::PRODUCT_NEUTRAL_FILES, true)) {
                        continue;
                    }

                    $window = substr($source, $offset, self::WINDOW);

                    // A primary key is NOT accepted here — see the class
                    // docblock. Only an explicit product predicate is.
                    if (! str_contains($window, "'product'")) {
                        $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                        $violations[] = "{$relative}:{$line} ({$matchText}) is not scoped by 'product'.";
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            9,
            $totalMatches,
            'Expected to find the known business_google_connections access points; the scan patterns may be broken.',
        );

        $this->assertSame([], $violations, "business_google_connections access without a product predicate:\n" . implode("\n", $violations));
    }

    /**
     * The half a query scan cannot see: a method handed a connection never
     * queries the table, so no amount of query-shape checking proves it is
     * safe. Each entry point must contain a product refusal.
     */
    public function test_every_method_that_accepts_a_connection_refuses_a_foreign_product(): void
    {
        $missing = [];

        foreach (self::GUARDED_ENTRY_POINTS as $relative => $methods) {
            $source = file_get_contents(base_path($relative));
            $this->assertNotFalse($source, "{$relative} is unreadable.");

            foreach ($methods as $method) {
                $body = $this->methodBody($source, $method);

                $this->assertNotNull($body, "{$relative}::{$method}() no longer exists — the boundary list is stale.");

                $guarded = str_contains($body, 'assertBusinessProfileConnection($connection)')
                    || preg_match('/\$connection->product\s*!==\s*GoogleConnectionProduct::BusinessProfile/', $body) === 1;

                if (! $guarded) {
                    $missing[] = "{$relative}::{$method}()";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These methods accept a connection without refusing a foreign product:\n" . implode("\n", $missing),
        );
    }

    /**
     * The guard has to be the FIRST thing each method does, or a
     * `search_console` row could still reach a provider call, a ledger row
     * or a write before being refused.
     */
    public function test_the_product_guard_runs_before_any_other_work(): void
    {
        foreach (self::GUARDED_ENTRY_POINTS as $relative => $methods) {
            $source = file_get_contents(base_path($relative));

            foreach ($methods as $method) {
                $body = (string) $this->methodBody($source, $method);
                $statements = preg_split('/\R/', trim($body)) ?: [];

                // Skip comment and blank lines to find the first statement.
                $first = null;

                foreach ($statements as $line) {
                    $line = trim($line);

                    if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '*') || str_starts_with($line, '/*')) {
                        continue;
                    }

                    $first = $line;
                    break;
                }

                $this->assertNotNull($first, "{$relative}::{$method}() has an empty body.");

                $isGuard = str_contains((string) $first, 'assertBusinessProfileConnection($connection)')
                    || str_contains((string) $first, 'if ($connection->product !== GoogleConnectionProduct::BusinessProfile)');

                $this->assertTrue(
                    $isGuard,
                    "{$relative}::{$method}() does work before its product guard; the first statement is: {$first}",
                );
            }
        }
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

    /**
     * §7.3 — the shared OAuth state signer is deliberately NOT pinned to
     * Business Profile: it serves both products and carries the product in
     * its signed payload. This asserts that on purpose, so a later "tighten
     * everything" pass cannot silently break Search Console's connect flow.
     */
    public function test_the_shared_oauth_state_signer_stays_product_generic(): void
    {
        $source = file_get_contents(base_path('app/Library/GoogleBusinessProfile/GoogleOAuthStateSigner.php'));

        $this->assertStringNotContainsString(
            'GoogleConnectionProduct::BusinessProfile',
            $source,
            'The shared state signer must not be pinned to one product.',
        );
        $this->assertStringContainsString("'p' => \$connection->product->value", $source, 'The signed state must carry the product.');
    }

    /**
     * Extracts one method body by brace matching, so the assertions above
     * cannot accidentally read a neighbouring method.
     */
    private function methodBody(string $source, string $method): ?string
    {
        if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $open = strpos($source, '{', $m[0][1]);

        if ($open === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $open + 1, $i - $open - 1);
                }
            }
        }

        return null;
    }
}
