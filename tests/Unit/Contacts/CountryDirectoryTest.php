<?php

namespace Tests\Unit\Contacts;

use App\Library\Contacts\CountryDirectory;
use PHPUnit\Framework\TestCase;

class CountryDirectoryTest extends TestCase
{
    public function test_lists_countries_with_flags_and_calling_codes_and_the_common_entries(): void
    {
        $all = CountryDirectory::all();
        $byIso = array_column($all, null, 'iso');

        $this->assertGreaterThan(200, count($all));
        $this->assertSame('+1', $byIso['US']['code']);
        $this->assertSame('+370', $byIso['LT']['code']);
        $this->assertSame('Lithuania', $byIso['LT']['name']);
        $this->assertSame("\u{1F1F1}\u{1F1F9}", $byIso['LT']['flag']);
        $this->assertTrue($byIso['US']['common']);
        $this->assertFalse($byIso['NZ']['common']);
    }

    public function test_us_number_is_validated_against_us_rules_and_stored_as_digits(): void
    {
        $result = CountryDirectory::validate('(720) 664-0969', 'US');

        $this->assertTrue($result['ok']);
        $this->assertSame('17206640969', $result['digits']);
        $this->assertSame('+17206640969', $result['e164']);
    }

    public function test_lithuanian_number_is_validated_against_lithuanian_rules(): void
    {
        $result = CountryDirectory::validate('612 34567', 'LT');

        $this->assertTrue($result['ok']);
        $this->assertSame('37061234567', $result['digits']);
    }

    public function test_wrong_length_numbers_get_country_specific_errors(): void
    {
        $short = CountryDirectory::validate('720 664', 'US');
        $this->assertFalse($short['ok']);
        $this->assertStringContainsString('too short for United States', $short['error']);

        $long = CountryDirectory::validate('720 664 0969 123 456', 'US');
        $this->assertFalse($long['ok']);
        $this->assertStringContainsString('too long', $long['error']);

        // A US-valid number is not a valid Lithuanian one: no US-only assumption in either direction.
        $this->assertFalse(CountryDirectory::validate('(720) 664-0969', 'LT')['ok']);
    }

    public function test_garbage_and_missing_input_are_refused(): void
    {
        $this->assertFalse(CountryDirectory::validate('', 'US')['ok']);
        $this->assertFalse(CountryDirectory::validate('abc123', 'US')['ok']);
        $this->assertFalse(CountryDirectory::validate('7206640969', 'ZZ')['ok']);
    }

    public function test_an_explicit_country_code_that_conflicts_with_the_selected_country_is_rejected_with_a_helpful_error(): void
    {
        $us = CountryDirectory::validate('+370 612 34567', 'US');
        $this->assertFalse($us['ok']);
        $this->assertTrue($us['conflict']);
        $this->assertStringContainsString('starts with +370 (Lithuania)', $us['error']);
        $this->assertStringContainsString('United States (+1) is selected', $us['error']);

        $lt = CountryDirectory::validate('+1 (720) 664-0969', 'LT');
        $this->assertFalse($lt['ok']);
        $this->assertStringContainsString('starts with +1', $lt['error']);
    }

    public function test_an_explicit_code_matching_the_selected_country_is_accepted(): void
    {
        $this->assertTrue(CountryDirectory::validate('+370 612 34567', 'LT')['ok']);
        $this->assertSame('17206640969', CountryDirectory::validate('+1 720 664 0969', 'US')['digits']);
        // Same calling code, different region of the +1 plan: accepted, not a conflict.
        $this->assertTrue(CountryDirectory::validate('+1 (416) 555-0198', 'US')['ok'] || CountryDirectory::validate('+1 416 967 1111', 'US')['ok']);
    }
}
