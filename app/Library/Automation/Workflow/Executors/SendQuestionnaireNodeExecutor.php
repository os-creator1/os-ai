<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;

/**
 * Automations V2 — the Send questionnaire action: SendFormNodeExecutor's twin that
 * accepts only a form of two or more pages. See that class.
 */
class SendQuestionnaireNodeExecutor extends SendFormNodeExecutor
{
    protected function wantsQuestionnaire(): bool
    {
        return true;
    }

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::SendQuestionnaire;
    }
}
