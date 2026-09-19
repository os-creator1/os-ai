<?php

namespace Tests\Unit\Support;

use App\Library\Opportunity\CanonicalJson as OpportunityCanonicalJson;
use App\Library\Opportunity\Exceptions\OpportunityException;
use App\Library\Opportunity\Exceptions\UnsupportedCanonicalValueException as OpportunityUnsupportedException;
use App\Library\Support\CanonicalJson;
use App\Library\Support\Exceptions\UnsupportedCanonicalValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use stdClass;
use Tests\TestCase;

/**
 * Implementation Contract 17 §5.3.2/§12.A — the canonical-JSON primitive,
 * extracted to a domain-neutral namespace BEHAVIOR-PRESERVINGLY. Two things
 * are proved here: the neutral class implements exactly the rules Contract 17's
 * content_hash relies on, and the Opportunity adapter is byte-for-byte and
 * exception-type-for-exception-type identical to what it was before.
 */
class CanonicalJsonTest extends TestCase
{
    // ------------------------------------------------------------------
    // The neutral primitive — the rules content_hash relies on (§5.3.2)
    // ------------------------------------------------------------------

    public function test_map_keys_are_recursively_sorted_by_byte_order(): void
    {
        $this->assertSame(
            '{"a":{"x":2,"y":1},"b":[3,1,2]}',
            CanonicalJson::encode(['b' => [3, 1, 2], 'a' => ['y' => 1, 'x' => 2]])
        );
    }

    public function test_the_same_semantic_object_hashes_the_same_regardless_of_key_order(): void
    {
        $one = ['name' => 'Booth', 'price' => 100, 'meta' => ['b' => 1, 'a' => 2]];
        $two = ['meta' => ['a' => 2, 'b' => 1], 'price' => 100, 'name' => 'Booth'];

        $this->assertSame(hash('sha256', CanonicalJson::encode($one)), hash('sha256', CanonicalJson::encode($two)));
    }

    public function test_list_order_and_duplicates_are_preserved_so_order_is_meaningful(): void
    {
        $this->assertSame('[3,1,2]', CanonicalJson::encode([3, 1, 2]));
        $this->assertSame('[5,5,3]', CanonicalJson::encode([5, 5, 3]));

        $this->assertNotSame(CanonicalJson::encode([1, 2]), CanonicalJson::encode([2, 1]));
    }

    public function test_a_meaningful_change_changes_the_hash(): void
    {
        $lines = [['name' => 'Booth', 'quantity' => 1, 'unit_price_minor' => 50000]];
        $changed = [['name' => 'Booth', 'quantity' => 2, 'unit_price_minor' => 50000]];

        $this->assertNotSame(
            hash('sha256', CanonicalJson::encode($lines)),
            hash('sha256', CanonicalJson::encode($changed))
        );
    }

    public function test_integers_stay_integers_and_are_not_merged_with_floats(): void
    {
        $this->assertSame('100', CanonicalJson::encode(100));
        $this->assertSame('100.0', CanonicalJson::encode(100.0));
        $this->assertNotSame(CanonicalJson::encode(100), CanonicalJson::encode(100.0));
    }

    public function test_strings_are_nfc_normalized_but_never_trimmed_or_lowercased(): void
    {
        $this->assertSame('"café"', CanonicalJson::encode("cafe\u{0301}"));
        $this->assertSame('" Padded "', CanonicalJson::encode(' Padded '));
        $this->assertSame('"MiXeD"', CanonicalJson::encode('MiXeD'));
    }

    public function test_slashes_and_unicode_are_not_escaped(): void
    {
        $this->assertSame('"a/b"', CanonicalJson::encode('a/b'));
        $this->assertSame('"日本語"', CanonicalJson::encode('日本語'));
    }

    public function test_a_non_zero_indexed_array_is_an_object_not_a_list(): void
    {
        $this->assertSame('{"1":"a","2":"b"}', CanonicalJson::encode([1 => 'a', 2 => 'b']));
    }

    public function test_empty_array_is_an_empty_list(): void
    {
        $this->assertSame('[]', CanonicalJson::encode([]));
    }

    public function test_non_finite_floats_are_refused_never_coerced(): void
    {
        foreach ([NAN, INF, -INF] as $bad) {
            $threw = false;

            try {
                CanonicalJson::encode($bad);
            } catch (UnsupportedCanonicalValueException) {
                $threw = true;
            }

            $this->assertTrue($threw);
        }
    }

    public function test_unsupported_types_are_refused_never_coerced(): void
    {
        foreach ([new stdClass(), fn () => 1] as $bad) {
            $threw = false;

            try {
                CanonicalJson::encode($bad);
            } catch (UnsupportedCanonicalValueException) {
                $threw = true;
            }

            $this->assertTrue($threw, 'An arbitrary object/closure must be refused.');
        }

        $resource = fopen('php://memory', 'r');
        $this->expectException(UnsupportedCanonicalValueException::class);

        try {
            CanonicalJson::encode($resource);
        } finally {
            fclose($resource);
        }
    }

    public function test_the_neutral_exception_is_not_an_opportunity_exception(): void
    {
        try {
            CanonicalJson::encode(NAN);
            $this->fail('Expected a refusal.');
        } catch (UnsupportedCanonicalValueException $e) {
            $this->assertNotInstanceOf(OpportunityException::class, $e);
        }
    }

    // ------------------------------------------------------------------
    // Domain neutrality
    // ------------------------------------------------------------------

    public function test_the_neutral_primitive_has_no_dependency_on_the_opportunity_domain(): void
    {
        $files = [
            (new ReflectionClass(CanonicalJson::class))->getFileName(),
            (new ReflectionClass(UnsupportedCanonicalValueException::class))->getFileName(),
            (new ReflectionClass(\App\Library\Support\Exceptions\CanonicalJsonUnavailableException::class))->getFileName(),
        ];

        foreach ($files as $file) {
            $code = '';

            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            $this->assertStringNotContainsString('Opportunity', $code, basename($file) . ' must not depend on the Opportunity domain.');
        }
    }

    // ------------------------------------------------------------------
    // Behavior preservation: the Opportunity adapter
    // ------------------------------------------------------------------

    /** @return array<string, array{0: mixed}> */
    public static function encodableValues(): array
    {
        return [
            'null' => [null],
            'true' => [true],
            'int' => [1],
            'float' => [1.0],
            'string' => ['a/b'],
            'unicode' => ['日本語'],
            'decomposed' => ["cafe\u{0301}"],
            'list' => [[3, 1, 2]],
            'dupes' => [[5, 5, 3]],
            'map' => [['b' => 1, 'a' => 2]],
            'offset keys' => [[1 => 'a', 2 => 'b']],
            'nested' => [['b' => [3, 1, 2], 'a' => ['y' => 1, 'x' => 2]]],
            'fingerprint' => [[
                'fingerprint_version' => 1, 'business_id' => 123, 'worker_key' => 'business_advisor',
                'type' => 'missing_phone', 'context' => null,
            ]],
        ];
    }

    #[DataProvider('encodableValues')]
    public function test_the_opportunity_adapter_is_byte_identical_to_the_neutral_primitive(mixed $value): void
    {
        $this->assertSame(CanonicalJson::encode($value), OpportunityCanonicalJson::encode($value));
    }

    public function test_the_opportunity_pinned_fingerprint_bytes_are_unchanged(): void
    {
        // The exact expectation from tests/Unit/Opportunity/CanonicalJsonTest —
        // proving the extraction did not move a single byte.
        $this->assertSame(
            '{"business_id":123,"context":null,"fingerprint_version":1,"type":"missing_phone","worker_key":"business_advisor"}',
            OpportunityCanonicalJson::encode([
                'fingerprint_version' => 1,
                'business_id' => 123,
                'worker_key' => 'business_advisor',
                'type' => 'missing_phone',
                'context' => null,
            ])
        );
    }

    public function test_the_opportunity_adapter_still_throws_its_own_exception_types(): void
    {
        foreach ([NAN, INF, -INF, new stdClass(), fn () => 1] as $bad) {
            $threw = false;

            try {
                OpportunityCanonicalJson::encode($bad);
            } catch (OpportunityUnsupportedException $e) {
                $threw = true;
                $this->assertInstanceOf(OpportunityException::class, $e);
                $this->assertInstanceOf(UnsupportedCanonicalValueException::class, $e->getPrevious(), 'The neutral cause must be chained.');
            }

            $this->assertTrue($threw, 'Opportunity callers must still see an OpportunityException subtype.');
        }
    }

    public function test_the_opportunity_adapter_preserves_the_exception_message(): void
    {
        try {
            CanonicalJson::encode(NAN);
        } catch (UnsupportedCanonicalValueException $neutral) {
            try {
                OpportunityCanonicalJson::encode(NAN);
            } catch (OpportunityUnsupportedException $adapted) {
                $this->assertSame($neutral->getMessage(), $adapted->getMessage());

                return;
            }
        }

        $this->fail('Both encoders must refuse NAN.');
    }
}
