<?php

namespace Tests\Feature\Seo;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Seo\SeoNapFieldResult;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileComparisonRules;
use App\Library\Seo\SeoNapComparator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * Contract 18 §8.5 — the NAP comparator: deterministic, read-time, and in
 * agreement with GBP contract §22.3's normalization for the same inputs.
 */
class SeoNapComparatorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    /**
     * The SHARED CONFORMANCE FIXTURE TABLE. Each row is asserted against the
     * SEO normalizer and against GBP's own rules class, so the two cannot
     * drift apart without this test failing. SEO re-implements the rules
     * (GBP §37.2: no dependency on GBP comparator internals); this table is
     * the seam that proves it re-implemented them faithfully.
     *
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function textFixtures(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'whitespace only' => ["  \t\n ", null],
            'trim' => ['  Acme Plumbing  ', 'acme plumbing'],
            'collapse inner whitespace' => ["Acme   \t Plumbing\nLtd", 'acme plumbing ltd'],
            'case insensitive' => ['ACME PLUMBING', 'acme plumbing'],
            'nfc composed vs decomposed' => ["Caf\u{0065}\u{0301} Bleu", "caf\u{00E9} bleu"],
            'punctuation is kept' => ['Acme, Inc.', 'acme, inc.'],
            'legal suffix is kept' => ['Acme Plumbing LLC', 'acme plumbing llc'],
            'unicode lowercase' => ['ÉCOLE', 'école'],
            'non-breaking space collapses' => ["Acme\u{00A0}Plumbing", 'acme plumbing'],
        ];
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function phoneFixtures(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'no digits' => ['call us', null],
            'plain digits' => ['5550101234', '5550101234'],
            'formatted us' => ['(555) 010-1234', '5550101234'],
            'leading plus kept' => ['+1 (555) 010-1234', '+15550101234'],
            'leading plus with dots' => ['+44.20.7946.0958', '+442079460958'],
            'inner plus dropped' => ['555+0101234', '5550101234'],
            'padded' => ['  +15550101234  ', '+15550101234'],
            'letters stripped' => ['555-010-1234 ext', '5550101234'],
            'plus only' => ['+', null],
        ];
    }

    #[DataProvider('textFixtures')]
    public function test_text_normalization_conforms_to_gbp_22_3(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, SeoNapComparator::normalizeText($input), 'SEO normalizer');
        $this->assertSame($expected, GoogleBusinessProfileComparisonRules::text($input), 'GBP rules (conformance oracle)');
    }

    #[DataProvider('phoneFixtures')]
    public function test_phone_normalization_conforms_to_gbp_22_3(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, SeoNapComparator::normalizePhone($input), 'SEO normalizer');
        $this->assertSame($expected, GoogleBusinessProfileComparisonRules::phone($input), 'GBP rules (conformance oracle)');
    }

    /**
     * @return array<string, array{0: array<string, ?string>, 1: array<string, ?string>, 2: array<string, string>}>
     */
    public static function comparisons(): array
    {
        $c = ['name' => 'Acme Plumbing', 'phone' => '+15550101234', 'address' => '12 High Street, Springfield, IL, 62701, US'];

        return [
            'everything consistent, formatting differs' => [
                $c,
                ['name' => '  acme   PLUMBING ', 'phone' => '+1 (555) 010-1234', 'address' => '12 high street, springfield, il, 62701, us'],
                ['name' => 'consistent', 'phone' => 'consistent', 'address' => 'consistent'],
            ],
            'name differs' => [
                $c,
                ['name' => 'Acme Plumbing Ltd', 'phone' => '+15550101234', 'address' => null],
                ['name' => 'mismatch', 'phone' => 'consistent', 'address' => 'unchecked'],
            ],
            'phone differs (national vs international is a mismatch: no region inference)' => [
                $c,
                ['name' => 'Acme Plumbing', 'phone' => '5550101234', 'address' => null],
                ['name' => 'consistent', 'phone' => 'mismatch', 'address' => 'unchecked'],
            ],
            'address differs' => [
                $c,
                ['name' => null, 'phone' => null, 'address' => '13 High Street'],
                ['name' => 'unchecked', 'phone' => 'unchecked', 'address' => 'mismatch'],
            ],
            'nothing recorded yet' => [
                $c,
                ['name' => null, 'phone' => null, 'address' => null],
                ['name' => 'unchecked', 'phone' => 'unchecked', 'address' => 'unchecked'],
            ],
            'no canonical phone' => [
                ['name' => 'Acme Plumbing', 'phone' => null, 'address' => null],
                ['name' => 'Acme Plumbing', 'phone' => '+15550101234', 'address' => null],
                ['name' => 'consistent', 'phone' => 'not_comparable', 'address' => 'not_comparable'],
            ],
            'no canonical anything: two absences are not agreement' => [
                ['name' => null, 'phone' => null, 'address' => null],
                ['name' => null, 'phone' => null, 'address' => null],
                ['name' => 'not_comparable', 'phone' => 'not_comparable', 'address' => 'not_comparable'],
            ],
            'blank listed value is unchecked, not a mismatch' => [
                $c,
                ['name' => '   ', 'phone' => '---', 'address' => ''],
                ['name' => 'unchecked', 'phone' => 'unchecked', 'address' => 'unchecked'],
            ],
        ];
    }

    /**
     * @param  array<string, ?string>  $canonical
     * @param  array<string, ?string>  $listed
     * @param  array<string, string>  $expected
     */
    #[DataProvider('comparisons')]
    public function test_per_field_results(array $canonical, array $listed, array $expected): void
    {
        $result = app(SeoNapComparator::class)->compare($canonical, $listed);

        $this->assertSame($expected, array_map(fn (SeoNapFieldResult $r) => $r->value, $result));
    }

    public function test_the_four_results_are_exactly_the_contracted_words(): void
    {
        $this->assertSame(
            ['consistent', 'mismatch', 'not_comparable', 'unchecked'],
            array_map(fn (SeoNapFieldResult $r) => $r->value, SeoNapFieldResult::cases())
        );
    }

    public function test_the_comparator_is_deterministic(): void
    {
        $comparator = app(SeoNapComparator::class);
        $canonical = ['name' => 'Acme', 'phone' => '+15550101234', 'address' => null];
        $listed = ['name' => 'Acme', 'phone' => '+15550101235', 'address' => null];

        $this->assertSame(
            array_map(fn ($r) => $r->value, $comparator->compare($canonical, $listed)),
            array_map(fn ($r) => $r->value, $comparator->compare($canonical, $listed)),
        );
    }

    // -----------------------------------------------------------------
    // Canonical NAP and the private-address predicate
    // -----------------------------------------------------------------

    public function test_canonical_nap_is_the_business_name_and_phone_and_a_permitted_location_address(): void
    {
        [, $business] = $this->growthTenantWithLocation();
        $location = $this->publicStorefront($business);

        $canonical = app(SeoNapComparator::class)->canonicalFor($business, $location);

        $this->assertSame($business->name, $canonical['name']);
        $this->assertSame('+15550101234', $canonical['phone']);
        $this->assertSame('12 High Street, Springfield, IL, 62701, US', $canonical['address']);
    }

    public static function addressDenyingLocations(): array
    {
        return [
            'public_address off, storefront' => [false, BusinessServiceMode::Storefront],
            'public_address on, service area' => [true, BusinessServiceMode::ServiceArea],
            'public_address on, online' => [true, BusinessServiceMode::Online],
            'public_address off, hybrid' => [false, BusinessServiceMode::Hybrid],
        ];
    }

    #[DataProvider('addressDenyingLocations')]
    public function test_the_street_address_never_enters_the_canonical_nap_when_the_predicate_denies(bool $public, BusinessServiceMode $mode): void
    {
        [, $business] = $this->growthTenantWithLocation();
        $location = $this->extraLocation($business, 'Private', [
            'service_mode' => $mode, 'public_address' => $public, 'address_line_1' => '99 Secret Lane', 'city' => 'Springfield',
        ]);

        $canonical = app(SeoNapComparator::class)->canonicalFor($business, $location);

        $this->assertNull($canonical['address']);
        $this->assertStringNotContainsString('Secret', json_encode($canonical));

        // And so the address row can never be anything but Not comparable,
        // whatever the listing shows.
        $result = app(SeoNapComparator::class)->compare($canonical, ['name' => null, 'phone' => null, 'address' => '99 Secret Lane']);
        $this->assertSame(SeoNapFieldResult::NotComparable, $result['address']);
    }

    public function test_the_comparator_holds_no_state_and_touches_no_io(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Library/Seo/SeoNapComparator.php');
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        foreach (['DB::', 'Http::', 'Cache::', 'Log::', 'now(', 'Carbon', '->save(', '->update(', 'App\\Models\\SeoCitation', 'GoogleBusinessProfileComparator', 'GoogleBusinessProfileComparisonRules', 'GoogleBusinessProfileStatusReader'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, "The comparator must not use [{$forbidden}].");
        }

        // The ONE permitted GBP dependency is the pure address predicate.
        $this->assertStringContainsString('GoogleBusinessProfileReadMask', $code);
    }
}
