<?php

namespace Tests\Feature\Automations\Workflow\Http\Support;

use App\Enums\Business\BusinessStatus;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The V2-E route inventory and the tenants to call it as.
 *
 * ONE LIST OF ROUTES, used by every authorization proof. A route added to the
 * controller but forgotten here would escape the matrix, so the inventory test
 * asserts this list equals what the router actually registered.
 */
trait CallsWorkflowRoutes
{
    /**
     * Every V2-E route: [method, name suffix, needs a workflow, needs an enrollment].
     *
     * @return list<array{0: string, 1: string, 2: bool, 3: bool}>
     */
    protected function workflowRoutes(): array
    {
        return [
            ['GET', 'index', false, false],
            ['POST', 'store', false, false],
            ['GET', 'show', true, false],
            ['GET', 'settings', true, false],
            ['POST', 'pause', true, false],
            ['POST', 'resume', true, false],
            ['POST', 'archive', true, false],
            ['GET', 'draft.show', true, false],
            ['PUT', 'draft.autosave', true, false],
            ['POST', 'publish', true, false],
            ['POST', 'discard-draft', true, false],
            ['POST', 'simulate', true, false],
            ['POST', 'stop-all', true, false],
            ['GET', 'enrollments.index', true, false],
            ['GET', 'enrollments.logs', true, true],
            ['POST', 'enrollments.manual', true, false],
        ];
    }

    /**
     * A VALID body for each mutating route, so a denial can only ever be about
     * authorization — never a validation failure standing in for one.
     *
     * @return array<string, mixed>
     */
    protected function validBodyFor(string $name, ?Contacts $contact = null): array
    {
        return match ($name) {
            'store' => ['name' => 'Welcome series', 'trigger_type' => 'contact_created'],
            'draft.autosave' => ['definition' => ['root' => ['key' => 'r', 'type' => 'trigger', 'config' => []]], 'definition_revision' => 1],
            'simulate' => ['contact_uid' => $contact?->uid ?? 'missing'],
            'enrollments.manual' => ['contact_uids' => [$contact?->uid ?? 'missing'], 'confirmed' => true],
            default => [],
        };
    }

    protected function routeUrl(
        string $name,
        Workspace|string $workspace,
        Business|string $business,
        AutomationWorkflow|string|null $workflow = null,
        AutomationEnrollment|string|null $enrollment = null,
    ): string {
        $params = [
            $workspace instanceof Workspace ? $workspace->uid : $workspace,
            $business instanceof Business ? $business->uid : $business,
        ];

        if ($workflow !== null) {
            $params[] = $workflow instanceof AutomationWorkflow ? $workflow->uid : $workflow;
        }

        if ($enrollment !== null) {
            $params[] = $enrollment instanceof AutomationEnrollment ? $enrollment->uid : $enrollment;
        }

        return route('customer.workspaces.businesses.automations.workflows.' . $name, $params);
    }

    /** Call a route as a JSON client — the client these endpoints serve. */
    protected function callJson(string $method, string $url, array $body = []): TestResponse
    {
        return $this->json($method, $url, $body);
    }

    /**
     * A tenant with a PUBLISHED workflow, one contact, and one real enrollment —
     * everything any route in the inventory needs to be addressed.
     *
     * @return array{customer: \App\Models\Customer, business: Business, workspace: Workspace, workflow: AutomationWorkflow, contact: Contacts, enrollment: AutomationEnrollment}
     */
    protected function tenantWithWorkflow(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], name: 'Tenant workflow');
        $contact = $this->contactFor($business);

        // A real journey, so the logs route has something that exists.
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);

        return [
            'customer' => $customer,
            'business' => $business,
            'workspace' => $workspace,
            'workflow' => $workflow->fresh(),
            'contact' => $contact,
            'enrollment' => $enrollment,
        ];
    }

    /** A Business that exists and is active but has no Automations entitlement. */
    protected function unentitledTenant(): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        return [$customer, $business->fresh(), $business->workspace];
    }
}
