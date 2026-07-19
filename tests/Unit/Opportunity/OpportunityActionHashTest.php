<?php

namespace Tests\Unit\Opportunity;

use App\Library\Opportunity\OpportunityActionHash;
use Tests\TestCase;

class OpportunityActionHashTest extends TestCase
{
    public function test_deterministic_fixed_vector(): void
    {
        $hasher = new OpportunityActionHash();

        $result = $hasher->compute(['action_key' => 'add_phone', 'schema_version' => 1]);

        $expectedCanonical = '{"action_key":"add_phone","schema_version":1}';
        $this->assertSame(hash('sha256', $expectedCanonical), $result);
    }

    public function test_object_key_order_does_not_change_the_hash(): void
    {
        $hasher = new OpportunityActionHash();

        $a = $hasher->compute(['action_key' => 'add_phone', 'schema_version' => 1]);
        $b = $hasher->compute(['schema_version' => 1, 'action_key' => 'add_phone']);

        $this->assertSame($a, $b);
    }

    public function test_ordered_list_reordering_changes_the_hash(): void
    {
        $hasher = new OpportunityActionHash();

        $a = $hasher->compute(['parameters' => ['step1', 'step2']]);
        $b = $hasher->compute(['parameters' => ['step2', 'step1']]);

        $this->assertNotSame($a, $b);
    }

    /**
     * Simulates a trusted action-specific validation rule pre-normalizing an
     * unordered list (dedupe + sort by canonical bytes) before it ever
     * reaches OpportunityActionHash — proving two different input orders
     * converge to the same hash once normalized the same way (§26, §28).
     */
    public function test_pre_normalized_unordered_values_produce_the_same_hash(): void
    {
        $hasher = new OpportunityActionHash();

        $normalize = static function (array $urls): array {
            $urls = array_values(array_unique($urls));
            sort($urls, SORT_STRING);

            return $urls;
        };

        $a = $hasher->compute(['urls' => $normalize(['https://b.test', 'https://a.test'])]);
        $b = $hasher->compute(['urls' => $normalize(['https://a.test', 'https://b.test'])]);

        $this->assertSame($a, $b);
    }

    public function test_integer_and_decimal_values_remain_distinct(): void
    {
        $hasher = new OpportunityActionHash();

        $a = $hasher->compute(['quantity' => 1]);
        $b = $hasher->compute(['quantity' => 1.0]);

        $this->assertNotSame($a, $b);
    }

    public function test_output_is_lowercase_sha256(): void
    {
        $hasher = new OpportunityActionHash();

        $result = $hasher->compute(['action_key' => 'add_phone']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result);
        $this->assertSame(64, strlen($result));
    }
}
