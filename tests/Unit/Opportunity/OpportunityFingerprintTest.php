<?php

namespace Tests\Unit\Opportunity;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Opportunity\OpportunityFingerprint;
use Tests\TestCase;

class OpportunityFingerprintTest extends TestCase
{
    public function test_deterministic_fixed_vector(): void
    {
        $fingerprint = new OpportunityFingerprint();

        $result = $fingerprint->compute(123, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', null, 1);

        $expectedCanonical = '{"business_id":123,"context":null,"fingerprint_version":1,"type":"missing_phone","worker_key":"business_advisor"}';
        $this->assertSame(hash('sha256', $expectedCanonical), $result);
    }

    public function test_same_input_produces_the_same_fingerprint(): void
    {
        $fingerprint = new OpportunityFingerprint();

        $a = $fingerprint->compute(1, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', null, 1);
        $b = $fingerprint->compute(1, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', null, 1);

        $this->assertSame($a, $b);
    }

    public function test_changing_business_id_changes_the_fingerprint(): void
    {
        $fingerprint = new OpportunityFingerprint();
        $base = $fingerprint->compute(123, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', null, 1);

        $this->assertNotSame($base, $fingerprint->compute(456, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', null, 1));
    }

    public function test_changing_worker_key_changes_the_fingerprint(): void
    {
        $fingerprint = new OpportunityFingerprint();
        $base = $fingerprint->compute(123, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', null, 1);

        $this->assertNotSame($base, $fingerprint->compute(123, OpportunityWorkerKey::Seo, 'missing_phone', null, 1));
    }

    public function test_changing_type_changes_the_fingerprint(): void
    {
        $fingerprint = new OpportunityFingerprint();
        $base = $fingerprint->compute(123, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', null, 1);

        $this->assertNotSame($base, $fingerprint->compute(123, OpportunityWorkerKey::BusinessAdvisor, 'missing_email', null, 1));
    }

    public function test_changing_context_changes_the_fingerprint(): void
    {
        $fingerprint = new OpportunityFingerprint();
        $base = $fingerprint->compute(123, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', null, 1);

        $this->assertNotSame($base, $fingerprint->compute(123, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', ['x'], 1));
    }

    public function test_changing_fingerprint_version_changes_the_fingerprint(): void
    {
        $fingerprint = new OpportunityFingerprint();
        $base = $fingerprint->compute(123, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', null, 1);

        $this->assertNotSame($base, $fingerprint->compute(123, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', null, 2));
    }

    public function test_context_map_key_order_does_not_matter(): void
    {
        $fingerprint = new OpportunityFingerprint();

        $a = $fingerprint->compute(1, OpportunityWorkerKey::BusinessAdvisor, 'some_type', ['a' => 1, 'b' => 2], 1);
        $b = $fingerprint->compute(1, OpportunityWorkerKey::BusinessAdvisor, 'some_type', ['b' => 2, 'a' => 1], 1);

        $this->assertSame($a, $b);
    }

    public function test_context_list_order_matters(): void
    {
        $fingerprint = new OpportunityFingerprint();

        $a = $fingerprint->compute(1, OpportunityWorkerKey::BusinessAdvisor, 'some_type', ['x', 'y'], 1);
        $b = $fingerprint->compute(1, OpportunityWorkerKey::BusinessAdvisor, 'some_type', ['y', 'x'], 1);

        $this->assertNotSame($a, $b);
    }

    public function test_integer_and_decimal_context_do_not_collapse(): void
    {
        $fingerprint = new OpportunityFingerprint();

        $intResult = $fingerprint->compute(1, OpportunityWorkerKey::BusinessAdvisor, 'some_type', 1, 1);
        $floatResult = $fingerprint->compute(1, OpportunityWorkerKey::BusinessAdvisor, 'some_type', 1.0, 1);

        $this->assertNotSame($intResult, $floatResult);
    }

    public function test_output_is_exactly_64_lowercase_hexadecimal_characters(): void
    {
        $fingerprint = new OpportunityFingerprint();
        $result = $fingerprint->compute(1, OpportunityWorkerKey::BusinessAdvisor, 'missing_phone', null, 1);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result);
        $this->assertSame(64, strlen($result));
    }
}
