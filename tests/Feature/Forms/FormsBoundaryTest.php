<?php

namespace Tests\Feature\Forms;

use Tests\TestCase;

/**
 * Forms V1 — source-level boundaries, held by test so a later "small tidy-up"
 * cannot quietly re-couple Forms to Website or Automations.
 *
 * No database: these read the files this slice owns.
 */
class FormsBoundaryTest extends TestCase
{
    /** @return list<string> every PHP/Blade file the standalone Forms module owns */
    private function formsFiles(): array
    {
        $roots = [
            'app/Library/Forms',
            'app/Enums/Forms',
            'app/Events/Forms',
            'resources/views/customer/business/forms',
            'resources/views/public/forms',
        ];
        $files = [
            'app/Http/Controllers/Customer/Business/FormsController.php',
            'app/Http/Controllers/Customer/Business/FormSubmissionsController.php',
            'app/Http/Controllers/Customer/Business/Concerns/AuthorizesFormsRequests.php',
            'app/Http/Controllers/Public/PublicFormController.php',
            'app/Models/Form.php',
            'app/Models/FormVersion.php',
            'app/Models/FormDeployment.php',
            'app/Models/FormSubmission.php',
        ];

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                }
            }
        }

        return array_values(array_unique($files));
    }

    public function test_forms_is_never_authorized_through_the_website_feature_or_reuses_website_domain_objects(): void
    {
        $forbidden = [
            'WebsiteGeneration', 'website_generation', 'QuestionPack', 'WebsiteForm', 'WebsiteFormSubmission',
            'App\\Models\\Website', 'Website\\', 'WebsiteStarter', 'WebsiteRevision', 'WebsiteStudio',
        ];

        foreach ($this->formsFiles() as $file) {
            // The comments explain what Forms is NOT; the boundary is on CODE.
            $code = $this->withoutComments((string) file_get_contents(base_path($file)));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $code, "{$file} must not reference {$needle}");
            }
        }
    }

    public function test_forms_does_not_reach_into_central_automations(): void
    {
        foreach ($this->formsFiles() as $file) {
            $code = $this->withoutComments((string) file_get_contents(base_path($file)));

            foreach (['App\\Library\\Automation', 'WorkflowTriggerType', 'App\\Jobs\\Automation', 'AutomationJob'] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "{$file} must not reference {$needle}");
            }
        }
    }

    public function test_only_the_form_manager_writes_definitions_and_only_the_submission_service_writes_submissions(): void
    {
        $writers = [
            'Form::create' => 'app/Library/Forms/FormManager.php',
            'FormVersion::create' => 'app/Library/Forms/FormManager.php',
            'FormDeployment::create' => 'app/Library/Forms/FormManager.php',
            'FormSubmission::create' => 'app/Library/Forms/FormSubmissionService.php',
        ];

        foreach ($this->formsFiles() as $file) {
            $code = $this->withoutComments((string) file_get_contents(base_path($file)));

            foreach ($writers as $call => $owner) {
                if ($file !== $owner) {
                    $this->assertStringNotContainsString($call, $code, "{$file} must not call {$call}; only {$owner} may");
                }
            }
        }
    }

    public function test_the_forms_controllers_stay_thin_and_never_touch_the_tables_directly(): void
    {
        foreach ([
            'app/Http/Controllers/Customer/Business/FormsController.php',
            'app/Http/Controllers/Customer/Business/FormSubmissionsController.php',
            'app/Http/Controllers/Public/PublicFormController.php',
        ] as $file) {
            $code = $this->withoutComments((string) file_get_contents(base_path($file)));

            foreach (['DB::table', '->save()', '->delete()', '::create(', 'forceFill', '::insert(', '::query()->update('] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "{$file} must not write persistence itself ({$needle})");
            }
        }
    }

    public function test_the_forms_routes_are_registered_outside_the_website_prefix(): void
    {
        $customer = (string) file_get_contents(base_path('routes/customer.php'));

        $this->assertStringContainsString("'{workspaceUid}/businesses/{businessUid}/forms'", $customer);
        $this->assertStringNotContainsString('website/forms', preg_replace('/\/\*.*?\*\//s', '', $customer));
    }

    private function withoutComments(string $source): string
    {
        $source = preg_replace('/\/\*.*?\*\//s', '', $source);
        $source = preg_replace('/^\s*\/\/.*$/m', '', $source);
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        return $source;
    }
}
