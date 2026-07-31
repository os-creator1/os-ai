<?php

namespace Tests\Unit\Workspace;

use App\DTO\Workspace\WorkspaceFirstBusinessInput;
use App\Models\Customer;
use Tests\TestCase;

/**
 * RFC-003 Milestone 2 Slice 2A test #3: WorkspaceFirstBusinessInput
 * preserves the explicit Customer and attributes it was constructed with,
 * with no inference from a Workspace owner or acting user anywhere in its
 * shape.
 */
class WorkspaceFirstBusinessInputTest extends TestCase
{
    public function test_preserves_the_explicit_customer_and_attributes(): void
    {
        $customer = new Customer(['user_id' => 42]);
        $attributes = ['name' => 'First Business', 'industry' => 'photo_booth_service'];

        $input = new WorkspaceFirstBusinessInput($customer, $attributes);

        $this->assertSame($customer, $input->customer);
        $this->assertSame($attributes, $input->businessAttributes);
    }

    public function test_constructor_accepts_exactly_customer_and_attributes(): void
    {
        $constructor = new \ReflectionMethod(WorkspaceFirstBusinessInput::class, '__construct');
        $parameterNames = array_map(fn ($parameter) => $parameter->getName(), $constructor->getParameters());

        $this->assertSame(['customer', 'businessAttributes'], $parameterNames);
    }
}
