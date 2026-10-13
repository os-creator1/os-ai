<?php

namespace App\Library\Assistant;

use App\Library\Assistant\Tools\ActionTool;
use App\Models\AssistantActionAudit;
use App\Models\AssistantActionProposal;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Prepares (never executes) an action: validates the model's arguments, lets the tool resolve every reference through
 * the module's own readers and build the preview, records the state the preview was computed against, and stores a
 * time-limited proposal that only the same customer, in the same Business, can later approve.
 */
final class AssistantProposals
{
    /** @param mixed $rawArgs */
    public function prepare(
        AssistantContext $context,
        AssistantConversation $conversation,
        AssistantMessage $userMessage,
        ActionTool $tool,
        mixed $rawArgs,
        string $intro,
    ): AssistantTurn {
        $registry = app(AssistantToolRegistry::class);

        try {
            $args = $registry->validate($tool->rules(), $rawArgs);
        } catch (ValidationException $e) {
            $fields = array_map(fn (string $f): string => str_replace('_', ' ', $f), array_keys($e->errors()));

            return new AssistantTurn(AssistantTurn::CLARIFY, 'I need a little more detail to set that up: ' . implode(', ', $fields) . '. Could you tell me?');
        }

        try {
            $preview = $tool->prepare($context, $args);
            $fingerprint = $tool->fingerprint($context, $preview->resolved);
        } catch (AssistantMissingInformation $e) {
            return new AssistantTurn(AssistantTurn::CLARIFY, implode(' ', $e->questions), $e->options === [] ? [] : ['options' => $e->options]);
        } catch (AssistantActionRefused $e) {
            return new AssistantTurn(AssistantTurn::UNSUPPORTED, $e->getMessage(), ['links' => []]);
        }

        $proposal = AssistantActionProposal::create([
            'uid' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'workspace_id' => $context->workspace->id,
            'business_id' => $context->business->id,
            'user_id' => $context->user->id,
            'tool_key' => $tool->key(),
            'params' => $preview->resolved,
            'preview' => $preview->toArray(),
            'params_hash' => hash('sha256', json_encode([$tool->key(), $preview->resolved, $context->business->id, $context->user->id])),
            'state_fingerprint' => $fingerprint,
            'status' => AssistantActionProposal::PENDING,
            'expires_at' => CarbonImmutable::now()->addMinutes((int) config('assistant.proposal_ttl_minutes')),
            'idempotency_key' => hash('sha256', 'assistant-proposal|' . Str::uuid()),
        ]);

        AssistantActionAudit::create([
            'proposal_id' => $proposal->id,
            'event' => 'proposed',
            'actor_user_id' => $context->user->id,
            'detail' => ['tool' => $tool->key(), 'mode' => $tool->mode(), 'message' => $userMessage->uid],
            'created_at' => now(),
        ]);

        return new AssistantTurn(
            AssistantTurn::PROPOSAL,
            $intro !== '' ? $intro : 'Here is what I would do. Nothing happens until you confirm.',
            ['mode' => $tool->mode()],
            $proposal,
        );
    }
}
