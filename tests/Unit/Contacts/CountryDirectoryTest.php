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
}
