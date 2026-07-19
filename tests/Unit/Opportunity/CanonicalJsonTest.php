<?php

namespace Tests\Unit\Opportunity;

use App\Library\Opportunity\CanonicalJson;
use App\Library\Opportunity\Exceptions\UnsupportedCanonicalValueException;
use stdClass;
use Tests\TestCase;

class CanonicalJsonTest extends TestCase
{
    public function test_null_and_booleans_encode_as_json_literals(): void
    {
        $this->assertSame('null', CanonicalJson::encode(null));
        $this->assertSame('true', CanonicalJson::encode(true));
        $this->assertSame('false', CanonicalJson::encode(false));
    }

    public function test_integer_and_decimal_encode_differently(): void
    {
        $this->assertSame('1', CanonicalJson::encode(1));
        $this->assertSame('1.0', CanonicalJson::encode(1.0));
        $this->assertNotSame(CanonicalJson::encode(1), CanonicalJson::encode(1.0));
    }

    public function test_associative_array_keys_are_sorted_by_byte_order(): void
    {
        $this->assertSame('{"a":2,"b":1}', CanonicalJson::encode(['b' => 1, 'a' => 2]));
    }

    public function test_list_order_and_duplicates_are_preserved(): void
    {
        $this->assertSame('[3,1,2]', CanonicalJson::encode([3, 1, 2]));
        $this->assertSame('[5,5,3]', CanonicalJson::encode([5, 5, 3]));
    }

    public function test_empty_array_encodes_as_an_empty_json_list(): void
    {
        $this->assertSame('[]', CanonicalJson::encode([]));
    }

    public function test_non_zero_indexed_array_is_encoded_as_an_object_not_a_list(): void
    {
        // array_is_list() is false here (keys start at 1) — this must not be
        // silently re-encoded as a JSON array by PHP's own numeric-key
        // coercion once its keys are sorted.
        $this->assertSame('{"1":"a","2":"b"}', CanonicalJson::encode([1 => 'a', 2 => 'b']));
    }

    public function test_nested_lists_and_maps_preserve_their_own_rules(): void
    {
        $value = ['b' => [3, 1, 2], 'a' => ['y' => 1, 'x' => 2]];

        $this->assertSame('{"a":{"x":2,"y":1},"b":[3,1,2]}', CanonicalJson::encode($value));
    }

    public function test_strings_are_nfc_normalized(): void
    {
        // 'e' followed by a combining acute accent (U+0301) — decomposed form.
        $decomposed = "cafe\u{0301}";

        $this->assertSame('"café"', CanonicalJson::encode($decomposed));
    }

    public function test_slashes_are_not_escaped(): void
    {
        $this->assertSame('"a/b"', CanonicalJson::encode('a/b'));
    }

    public function test_unicode_is_not_escaped(): void
    {
        $this->assertSame('"日本語"', CanonicalJson::encode('日本語'));
    }

    public function test_pinned_fixed_canonical_object(): void
    {
        $canonicalObject = [
            'fingerprint_version' => 1,
            'business_id' => 123,
            'worker_key' => 'business_advisor',
            'type' => 'missing_phone',
            'context' => null,
        ];

        $expected = '{"business_id":123,"context":null,"fingerprint_version":1,"type":"missing_phone","worker_key":"business_advisor"}';

        $this->assertSame($expected, CanonicalJson::encode($canonicalObject));
    }

    public function test_nan_is_rejected(): void
    {
        $this->expectException(UnsupportedCanonicalValueException::class);

        CanonicalJson::encode(NAN);
    }

    public function test_positive_infinity_is_rejected(): void
    {
        $this->expectException(UnsupportedCanonicalValueException::class);

        CanonicalJson::encode(INF);
    }

    public function test_negative_infinity_is_rejected(): void
    {
        $this->expectException(UnsupportedCanonicalValueException::class);

        CanonicalJson::encode(-INF);
    }

    public function test_arbitrary_object_is_rejected(): void
    {
        $this->expectException(UnsupportedCanonicalValueException::class);

        CanonicalJson::encode(new stdClass());
    }

    public function test_closure_is_rejected(): void
    {
        $this->expectException(UnsupportedCanonicalValueException::class);

        CanonicalJson::encode(static fn () => true);
    }

    public function test_resource_is_rejected(): void
    {
        $resource = fopen('php://memory', 'r');

        try {
            $this->expectException(UnsupportedCanonicalValueException::class);

            CanonicalJson::encode($resource);
        } finally {
            fclose($resource);
        }
    }
}
