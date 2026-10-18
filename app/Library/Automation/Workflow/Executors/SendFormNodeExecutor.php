<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Forms\FormLifecycleState;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Runtime\PinnedRunLocation;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;

/**
 * Automations V2 — the Send form and Send questionnaire actions.
 *
 * Forms V1 has ONE definition for both: an ordinary form is one page and a
 * questionnaire is two or more. These are the same step with a different check on
 * the page count — "Send form" refuses a questionnaire and "Send questionnaire"
 * refuses a one-page form — so neither action can quietly send the other.
 *
 * THE FORMS DOMAIN'S OWN LINK. A form is offered at a Location through a
 * FormDeployment, whose uid is the public address (`public.forms.show`) and the
 * deterministic evidence of which Location a submission belongs to. The step links
 * to exactly that — it never clones a definition or mints a token, and the link
 * keeps every security and expiry property the Forms domain gives it (an unguessable
 * uuid, a disabled or archived deployment answering 404).
 *
 * WHICH DEPLOYMENT. Looked up INSIDE the journey's Business and for the journey's
 * pinned Location, so the submission lands at the Location the workflow is about. A
 * journey with no pinned Location uses the form's one enabled deployment, and
 * stops with `form_deployment_unavailable` when there is none or several — it never
 * picks one. A deployment at another Location is never sent
 * (`resource_outside_workflow_location`).
 *
 * Words and delivery are LinkActionNodeExecutor's.
 */
class SendFormNodeExecutor extends LinkActionNodeExecutor
{
    /** Whether this executor sends a questionnaire (two or more pages) rather than a form. */
    protected function wantsQuestionnaire(): bool
    {
        return false;
    }

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::SendForm;
    }

    protected function resolveLink(array $config, AutomationEnrollment $enrollment, Business $business, Contacts $contact): array|NodeExecutionOutcome
    {
        $formId = (int) ($config['form_id'] ?? 0);

        if ($formId <= 0) {
            return NodeExecutionOutcome::skipped('send_config_invalid');
        }

        $form = DB::table('forms')
            ->where('id', $formId)
            ->where('business_id', (int) $business->id)
            ->first(['id', 'name', 'lifecycle_state', 'current_version']);

        if ($form === null || (string) $form->lifecycle_state !== FormLifecycleState::Active->value) {
            return NodeExecutionOutcome::failed($this->wantsQuestionnaire() ? 'questionnaire_unavailable' : 'form_unavailable');
        }

        $pages = (int) (DB::table('form_versions')
            ->where('form_id', $formId)
            ->where('version', (int) $form->current_version)
            ->selectRaw('COALESCE(JSON_LENGTH(pages), 1) as pages')
            ->value('pages') ?? 1);

        if (($pages >= 2) !== $this->wantsQuestionnaire()) {
            // The form was edited into (or out of) a questionnaire after publish.
            return NodeExecutionOutcome::failed($this->wantsQuestionnaire() ? 'questionnaire_unavailable' : 'form_unavailable');
        }

        $pinned = PinnedRunLocation::pinned($enrollment);

        $deployments = DB::table('form_deployments as d')
            ->join('business_locations as l', 'l.id', '=', 'd.business_location_id')
            ->where('d.form_id', $formId)
            ->where('d.source', FormDeploymentSource::DirectLink->value)
            ->where('d.is_enabled', true)
            ->where('l.business_id', (int) $business->id)
            ->where('l.lifecycle_state', \App\Enums\Business\BusinessLocationLifecycleState::Active->value)
            ->orderBy('d.id')
            ->get(['d.uid', 'd.business_location_id']);

        if ($pinned !== null) {
            $deployment = $deployments->first(fn ($row): bool => (int) $row->business_location_id === $pinned);

            if ($deployment === null) {
                // The form is offered somewhere else (or nowhere): not this run's.
                return $deployments->isEmpty()
                    ? NodeExecutionOutcome::failed('form_deployment_unavailable')
                    : NodeExecutionOutcome::skipped(PinnedRunLocation::RESOURCE_OUTSIDE);
            }
        } else {
            if ($deployments->count() !== 1) {
                return NodeExecutionOutcome::failed('form_deployment_unavailable');
            }

            $deployment = $deployments->first();
        }

        return [
            'url' => route('public.forms.show', [(string) $deployment->uid]),
            'subject' => $this->wantsQuestionnaire() ? 'A few questions for you' : 'Please fill in this form',
            'text' => $this->wantsQuestionnaire()
                ? 'Please take a moment to answer a few questions:'
                : 'Please fill in this form:',
        ];
    }
}
