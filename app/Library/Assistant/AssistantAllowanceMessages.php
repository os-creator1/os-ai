<?php

namespace App\Library\Assistant;

use App\Library\Ai\AiAllowance;

/**
 * The words (and the one real link) a customer sees when their included AI is not available. A STATE is described —
 * never an amount, a token count, a model or a provider — and an upgrade is offered only when upgrading is the true
 * remedy and this actor can do it. Provider failures never come through here: they are not an allowance state.
 */
final class AssistantAllowanceMessages
{
    public function __construct(private readonly AssistantUpgradePath $upgrade)
    {
    }

    /**
     * @return array{title: string, body: string, status: string, upgrade: array{url: string, label: string}|null, ownerOnly: bool, tone: string}
     */
    public function blocked(AiAllowance $allowance, AssistantContext $context): array
    {
        [$title, $body] = match (true) {
            $allowance->status === AiAllowance::TRIAL_LIMIT_REACHED => [__('ask_ai.trial_limit.title'), __('ask_ai.trial_limit.body')],
            $allowance->status === AiAllowance::INTERACTIVE_LIMIT && $allowance->isTrial => [__('ask_ai.interactive_limit.title'), __('ask_ai.interactive_limit.trial_body')],
            $allowance->status === AiAllowance::INTERACTIVE_LIMIT => [__('ask_ai.interactive_limit.title'), __('ask_ai.interactive_limit.body', ['date' => $allowance->renewsOn ?? 'next month'])],
            $allowance->status === AiAllowance::LIMIT_REACHED => [__('ask_ai.monthly_limit.title'), __('ask_ai.monthly_limit.body', ['date' => $allowance->renewsOn ?? 'next month'])],
            $allowance->policyKey === 'trial_expired' => [__('ask_ai.unavailable.title'), __('ask_ai.unavailable.trial_expired_body')],
            default => [__('ask_ai.unavailable.title'), __('ask_ai.unavailable.body')],
        };

        $remedy = $allowance->upgradeIsRemedy();
        $upgrade = $remedy ? $this->upgrade->for($context) : null;

        return [
            'title' => $title,
            'body' => $body,
            'status' => $allowance->status,
            'upgrade' => $upgrade,
            // Upgrading is the remedy but this actor cannot do it: say who can, instead of a dead button.
            'ownerOnly' => $remedy && $upgrade === null,
            'tone' => 'limit',
        ];
    }
}
