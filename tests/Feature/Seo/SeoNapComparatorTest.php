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
                ['name' => 'Acme Heating', 'phone' => '+15550101234', 'address' => null],
                ['name' => 'mismatch', 'phone' => 'consistent', 'address' => 'unchecked'],
            ],
            'phone national vs international with no known country is unable to verify, not a mismatch' => [
                $c,
                ['name' => 'Acme Plumbing', 'phone' => '5550101234', 'address' => null],
                ['name' => 'consistent', 'phone' => 'not_comparable', 'address' => 'unchecked'],
            ],
            'phone with different digits is a mismatch' => [
                $c,
                ['name' => 'Acme Plumbing', 'phone' => '+15550101299', 'address' => null],
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

    // -----------------------------------------------------------------
    // B1 — address and phone are free text: the same address or number
    // written differently is NOT a mismatch, and when the comparison is not
    // confident the answer is "unable to verify", never a false Mismatch.
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>  canonical address, listed address, expected result (country US)
     */
    public static function addressFixtures(): array
    {
        $c = '123 Main St, Chicago, IL, 60601, US';

        return [
            'the audit example: no comma before the postal code, no country' => [$c, '123 Main St, Chicago, IL 60601', 'consistent'],
            'case, punctuation and the written-out street suffix' => [$c, '123 MAIN STREET, CHICAGO, IL 60601', 'consistent'],
            'the state written out and the country as USA' => [$c, '123 Main Street, Chicago, Illinois 60601, USA', 'consistent'],
            'postal code before the city (order is not a difference)' => [$c, '60601 Chicago IL 123 Main Street', 'consistent'],
            'ZIP+4' => [$c, '123 Main St, Chicago, IL 60601-1234', 'consistent'],
            'a partial address that agrees with everything it states' => [$c, '123 Main Street', 'consistent'],
            'a suite the profile does not carry is unable to verify' => [$c, '123 Main St, Suite 200, Chicago, IL 60601', 'not_comparable'],
            'only the city and state say nothing about the street' => [$c, 'Chicago, IL', 'not_comparable'],
            'the same house number on a different street is unable to verify' => [$c, '123 Elm St, Chicago, IL', 'not_comparable'],
            'a different house number is a mismatch' => [$c, '124 Main St, Chicago, IL 60601', 'mismatch'],
            'a different postal code is a mismatch' => [$c, '123 Main St, Chicago, IL 60602', 'mismatch'],
            'Canadian postal code spacing' => ['1 Main St, Toronto, ON, M5V 2T6, CA', '1 Main Street, Toronto, Ontario M5V2T6, Canada', 'consistent'],
        ];
    }

    #[DataProvider('addressFixtures')]
    public function test_addresses_are_compared_without_false_mismatches(string $canonical, string $listed, string $expected): void
    {
        $country = str_ends_with($canonical, ', CA') ? 'CA' : 'US';

        $result = app(SeoNapComparator::class)->compare(
            ['name' => null, 'phone' => null, 'address' => $canonical],
            ['name' => null, 'phone' => null, 'address' => $listed],
            $country,
            $country,
        );

        $this->assertSame($expected, $result['address']->value);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string, 3: string}>  canonical phone, listed phone, business country, expected
     */
    public static function phoneFixtures2(): array
    {
        return [
            'the audit example: national beside international, US' => ['(312) 555-1212', '+1 312 555 1212', 'US', 'consistent'],
            'a leading 1 without the plus' => ['+1 312 555 1212', '1-312-555-1212', 'US', 'consistent'],
            'Canada shares the calling code' => ['+1 416 555 0100', '(416) 555-0100', 'CA', 'consistent'],
            'an extension is not part of the number' => ['+1 312 555 1212', '312-555-1212 ext. 5', 'US', 'consistent'],
            'a UK trunk zero' => ['+44 20 7946 0958', '020 7946 0958', 'GB', 'consistent'],
            'different digits are a mismatch' => ['+1 312 555 1212', '312-555-1213', 'US', 'mismatch'],
            'another country is a mismatch' => ['+1 312 555 1212', '+44 20 7946 0958', 'US', 'mismatch'],
            'a number listed without its area code is unable to verify' => ['+1 312 555 1212', '555-1212', 'US', 'not_comparable'],
            'no country: the + form beside the bare form is unable to verify' => ['+15550101234', '5550101234', null, 'not_comparable'],
            'no country: identical digits still match' => ['+15550101234', '+1 (555) 010-1234', null, 'consistent'],
        ];
    }

    #[DataProvider('phoneFixtures2')]
    public function test_phones_are_compared_with_the_business_country_as_context(string $canonical, string $listed, ?string $country, string $expected): void
    {
        $result = app(SeoNapComparator::class)->compare(
            ['name' => null, 'phone' => $canonical, 'address' => null],
            ['name' => null, 'phone' => $listed, 'address' => null],
            $country,
            $country,
        );

        $this->assertSame($expected, $result['phone']->value);
    }

    public function test_an_unrecorded_field_is_never_a_mismatch_even_with_context(): void
    {
        $result = app(SeoNapComparator::class)->compare(
            ['name' => 'Acme', 'phone' => '+13125551212', 'address' => '123 Main St, Chicago, IL, 60601, US'],
            ['name' => null, 'phone' => null, 'address' => null],
            'US',
            'US',
        );

        $this->assertSame(['unchecked', 'unchecked', 'unchecked'], array_map(fn (SeoNapFieldResult $r) => $r->value, array_values($result)));
    }

    public function test_the_phone_is_only_claimed_for_the_main_location_of_a_multi_location_business(): void
    {
        $primary = new \App\Models\BusinessLocation(['is_primary' => true]);
        $primary->is_primary = true;
        $secondary = new \App\Models\BusinessLocation();
        $secondary->is_primary = false;

        $this->assertTrue(SeoNapComparator::phoneAppliesAt($primary, 3), 'the main Location');
        $this->assertFalse(SeoNapComparator::phoneAppliesAt($secondary, 3), 'a secondary Location may list its own number');
        $this->assertTrue(SeoNapComparator::phoneAppliesAt($secondary, 1), 'the only Location is the Business');

        $skipped = app(SeoNapComparator::class)->compare(
            ['name' => 'Acme', 'phone' => '+13125551212', 'address' => null],
            ['name' => 'Acme', 'phone' => '+14155550000', 'address' => null],
            'US',
            'US',
            comparePhone: false,
        );

        $this->assertSame(SeoNapFieldResult::NotComparable, $skipped['phone'], 'not compared, so never a false Mismatch');
        $this->assertSame(SeoNapFieldResult::Consistent, $skipped['name']);
    }

    // -----------------------------------------------------------------
    // Review follow-up: sub-units, no-calling-code phones, websites, names
    // -----------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function subUnitAddresses(): array
    {
        $sydney = '123 Smith St, Sydney, NSW, 2000, AU';
        $london = '10 Downing St, London, SW1A 2AA, GB';

        return [
            'a shop number before the house number' => [$sydney, 'Shop 3/123 Smith Street, Sydney NSW 2000', 'AU', 'not_comparable'],
            'a bare unit prefix' => [$sydney, '3/123 Smith St, Sydney NSW 2000, Australia', 'AU', 'not_comparable'],
            'a flat before the house' => [$london, 'Flat 2, 10 Downing Street, London SW1A 2AA', 'GB', 'not_comparable'],
            'a level before the house' => [$sydney, 'Level 2, 123 Smith Street, Sydney NSW 2000', 'AU', 'not_comparable'],
            'a genuinely different house number is still a mismatch' => [$sydney, 'Level 2, 99 Other Road, Sydney NSW 2000', 'AU', 'mismatch'],
        ];
    }

    #[DataProvider('subUnitAddresses')]
    public function test_a_leading_sub_unit_is_never_taken_for_the_house_number(string $canonical, string $listed, string $country, string $expected): void
    {
        $result = app(SeoNapComparator::class)->compare(
            ['name' => null, 'phone' => null, 'address' => $canonical],
            ['name' => null, 'phone' => null, 'address' => $listed],
            $country,
            $country,
        );

        $this->assertSame($expected, $result['address']->value);
    }

    /** @return array<string, array{0: string, 1: string, 2: ?string, 3: string}> */
    public static function phonesWithoutACallingCode(): array
    {
        return [
            'PH national with trunk 0 beside +63' => ['+63 917 123 4567', '0917 123 4567', 'PH', 'not_comparable'],
            'PH +63 listed, national in the profile' => ['0917 123 4567', '+639171234567', 'PH', 'not_comparable'],
            'JP trunk 0 beside +81' => ['+81 3 1234 5678', '03-1234-5678', 'JP', 'not_comparable'],
            'the same form with different digits is still a mismatch' => ['+63 917 123 4567', '+63 917 123 9999', 'PH', 'mismatch'],
            'bare forms with different digits are still a mismatch' => ['917 123 4567', '917 123 9999', 'PH', 'mismatch'],
        ];
    }

    #[DataProvider('phonesWithoutACallingCode')]
    public function test_a_country_with_no_known_calling_code_is_compared_conservatively(string $canonical, string $listed, ?string $country, string $expected): void
    {
        $result = app(SeoNapComparator::class)->compare(
            ['name' => null, 'phone' => $canonical, 'address' => null],
            ['name' => null, 'phone' => $listed, 'address' => null],
            $country,
            $country,
        );

        $this->assertSame($expected, $result['phone']->value);
    }

    public function test_a_tracking_query_or_landing_path_is_not_a_website_mismatch_but_another_host_is(): void
    {
        $c = app(SeoNapComparator::class);

        $this->assertSame(SeoNapFieldResult::Consistent, $c->compareWebsite('https://example.test', 'https://www.example.test/?utm_source=gbp&utm_medium=organic#top'));
        $this->assertSame(SeoNapFieldResult::Consistent, $c->compareWebsite('https://example.test/shop?ref=a', 'http://example.test/shop/'));
        $this->assertSame(SeoNapFieldResult::NotComparable, $c->compareWebsite('https://example.test', 'https://example.test/locations/chicago?utm_source=gbp'));
        $this->assertSame(SeoNapFieldResult::Mismatch, $c->compareWebsite('https://example.test', 'https://example.org/?utm_source=gbp'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function names(): array
    {
        return [
            'apostrophes and curly quotes' => ["Joe's Pizza", 'Joe’s Pizza', 'consistent'],
            'ampersand and "and"' => ['Smith & Sons', 'Smith and Sons', 'consistent'],
            'the HTML entity' => ['Smith &amp; Sons', 'Smith & Sons', 'consistent'],
            'a trailing legal suffix, with or without dots and commas' => ['Acme Plumbing LLC', 'Acme Plumbing, Inc.', 'consistent'],
            'a suffix on one side only' => ['Acme Plumbing', 'Acme Plumbing Ltd.', 'consistent'],
            'repeated punctuation' => ['Acme -- Plumbing!!', 'Acme Plumbing', 'consistent'],
            'a genuinely different name' => ['Acme Plumbing', 'Acme Heating', 'mismatch'],
            'a different name with the same suffix' => ['Acme Plumbing LLC', 'Apex Plumbing LLC', 'mismatch'],
        ];
    }

    #[DataProvider('names')]
    public function test_names_ignore_quotes_ampersands_punctuation_and_legal_suffixes(string $canonical, string $listed, string $expected): void
    {
        $result = app(SeoNapComparator::class)->compare(
            ['name' => $canonical, 'phone' => null, 'address' => null],
            ['name' => $listed, 'phone' => null, 'address' => null],
        );

        $this->assertSame($expected, $result['name']->value);
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
