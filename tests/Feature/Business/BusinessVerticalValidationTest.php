<?php

namespace Tests\Feature\Business;

use App\Models\BusinessVertical;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §6.1 -- BusinessVertical shape
 * validation, enforced at the model boundary on every create()/update()
 * so no caller can bypass it. This protects BusinessVertical::create()/
 * update()/save() calls only (including this contract's own fixtures);
 * it does not and cannot protect a raw DB::table('business_verticals')
 * write, which bypasses Eloquent entirely -- no such call exists or is
 * authorized anywhere in this contract's implementation.
 */
class BusinessVerticalValidationTest extends TestCase
{
    use CreatesBusinessKnowledgeProfileFixtures;
    use RefreshDatabase;

    public function test_a_fully_valid_vertical_is_accepted(): void
    {
        $vertical = $this->createVertical(['key' => 'roofing', 'display_name' => 'Roofing', 'broad_industry' => 'home_services']);

        $this->assertSame('roofing', $vertical->key);
        $this->assertTrue($vertical->is_active);
    }

    public function test_key_is_required(): void
    {
        $this->expectException(ValidationException::class);
        BusinessVertical::create(['key' => '', 'display_name' => 'X', 'is_active' => true]);
    }

    public function test_key_must_match_the_lowercase_separator_pattern(): void
    {
        $this->expectException(ValidationException::class);
        $this->createVertical(['key' => 'Not Valid!']);
    }

    public function test_key_cannot_exceed_the_max_length(): void
    {
        $this->expectException(ValidationException::class);
        $this->createVertical(['key' => str_repeat('a', 41)]);
    }

    public function test_key_cannot_have_leading_trailing_or_repeated_separators(): void
    {
        $this->expectException(ValidationException::class);
        $this->createVertical(['key' => '_roofing']);
    }

    public function test_display_name_is_required_and_non_blank(): void
    {
        $this->expectException(ValidationException::class);
        $this->createVertical(['display_name' => '   ']);
    }

    public function test_display_name_cannot_exceed_the_max_length(): void
    {
        $this->expectException(ValidationException::class);
        $this->createVertical(['display_name' => str_repeat('a', 81)]);
    }

    public function test_broad_industry_must_be_a_real_business_industry_value_when_present(): void
    {
        $this->expectException(ValidationException::class);
        $this->createVertical(['broad_industry' => 'not_a_real_industry']);
    }

    public function test_broad_industry_null_is_valid(): void
    {
        $vertical = $this->createVertical(['broad_industry' => null]);

        $this->assertNull($vertical->broad_industry);
    }
}
