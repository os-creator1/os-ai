<?php

namespace App\Library\PlatformAutomation;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\PlatformAutomation\Announcements\PlatformAnnouncementManager;
use App\Library\PlatformOwner\PlatformOwnerAccountActions;
use App\Mail\PlatformAutomationMail;
use App\Models\PlatformAccountNote;
use App\Models\PlatformAutomationStep;
use App\Models\User;
use App\Notifications\PlatformAutomation\PlatformNoticeNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Executes ONE step. It only ever reaches a canonical service (the Platform Owner
 * account actions, the entitlement manager, the announcement manager, Laravel's own
 * notification / mail / password-broker) — never a table another module owns.
 *
 * ACCOUNT_STATE / BILLING steps receive the APPROVING Platform Owner as `$actorUserId`
 * and the canonical service re-checks that person's authority itself; an automation
 * has no authority of its own.
 */
class PlatformActionExecutor
{
    public function __construct(
        private readonly PlatformAnnouncementManager $announcements,
        private readonly PlatformOwnerAccountActions $accountActions,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /**
     * @return array{result: array<string, mixed>, operation_ref: ?string}
     *
     * @throws PlatformStepSkipped when the run has nobody/nothing for this step (not a failure)
     */
    public function execute(PlatformAutomationStep $step, PlatformRunContext $ctx, ?int $actorUserId): array
    {
        $p = (array) $step->config;
        $run = $ctx->run();

        return match ($step->action_type) {
            'send_in_app_notification' => $this->notify($ctx, $p, $run->id),
            'send_email' => $this->email($ctx, $p),
            'send_announcement' => $this->announce($ctx, $p),
            'resend_email_verification' => $this->resendVerification($ctx),
            'send_password_reset_link' => $this->resetLink($ctx),
            'webhook' => $this->webhook($ctx, $p),
            'create_internal_task' => $this->note($ctx, PlatformAccountNote::KIND_TASK, $p),
            'add_internal_note' => $this->note($ctx, PlatformAccountNote::KIND_NOTE, $p),
            'flag_manual_review' => $this->note($ctx, PlatformAccountNote::KIND_REVIEW_FLAG, $p),
            'suspend_business' => $this->businessStatus($ctx, BusinessStatus::Inactive, $p, $actorUserId),
            'reactivate_business' => $this->businessStatus($ctx, BusinessStatus::Active, $p, $actorUserId),
            'restore_workspace_access' => $this->restoreAccess($ctx, $p, $actorUserId),
            'change_plan' => $this->changePlan($ctx, $p, $actorUserId),
            'set_feature_override' => $this->featureOverride($ctx, $p, $actorUserId),
            default => throw new \InvalidArgumentException('Unknown action.'),
        };
    }

    private function notify(PlatformRunContext $ctx, array $p, int $runId): array
    {
        $users = $this->recipientsOrSkip($ctx, (string) $p['recipient']);

        foreach ($users as $user) {
            $user->notify(new PlatformNoticeNotification($ctx->render((string) $p['title'], $user), $ctx->render((string) $p['message'], $user), 'info', null, $runId));
        }

        return ['result' => ['notified' => count($users)], 'operation_ref' => null];
    }

    private function email(PlatformRunContext $ctx, array $p): array
    {
        $users = $this->recipientsOrSkip($ctx, (string) $p['recipient']);

        foreach ($users as $user) {
            Mail::to($user->email)->send(new PlatformAutomationMail(
                $ctx->render((string) $p['subject'], $user),
                $ctx->render((string) $p['body'], $user),
                (string) ($p['purpose'] ?? 'transactional'),
            ));
        }

        return ['result' => ['emailed' => count($users)], 'operation_ref' => null];
    }

    private function announce(PlatformRunContext $ctx, array $p): array
    {
        $run = $ctx->run();
        $audience = match (true) {
            $run->business_id !== null => ['kind' => 'businesses', 'refs' => [(string) $run->business_id]],
            $run->workspace_id !== null => ['kind' => 'workspaces', 'refs' => [(string) $run->workspace_id]],
            $run->user_id !== null => ['kind' => 'users', 'refs' => [(string) $run->user_id]],
            default => throw new PlatformStepSkipped('This run has no account to announce to.'),
        };

        $announcement = $this->announcements->create([
            'title' => $ctx->render((string) $p['title']),
            'body' => $ctx->render((string) $p['body']),
            'severity' => $p['severity'] ?? 'info',
            'channels' => explode(',', (string) ($p['channels'] ?? 'banner,notification')),
            'audience' => $audience,
        ], (int) ($run->automation->created_by_user_id ?? 0) ?: (int) User::query()->where('is_admin', true)->value('id'), $run->id);

        $this->announcements->publish($announcement);

        return ['result' => ['announcement' => $announcement->uid], 'operation_ref' => 'announcement:' . $announcement->uid];
    }

    private function resendVerification(PlatformRunContext $ctx): array
    {
        $user = $ctx->targetUser() ?? throw new PlatformStepSkipped('No user on this run.');

        if ($user->hasVerifiedEmail()) {
            throw new PlatformStepSkipped('The email is already verified.');
        }

        $user->sendEmailVerificationNotification();

        return ['result' => ['sent' => true], 'operation_ref' => null];
    }

    /** The standard broker sends a LINK; this code never sees, shows or sets a password. */
    private function resetLink(PlatformRunContext $ctx): array
    {
        $user = $ctx->targetUser() ?? throw new PlatformStepSkipped('No user on this run.');
        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw new \RuntimeException('The reset link could not be sent.');
        }

        return ['result' => ['sent' => true], 'operation_ref' => null];
    }

    private function webhook(PlatformRunContext $ctx, array $p): array
    {
        $run = $ctx->run();
        $payload = json_encode([
            'automation' => $run->automation->uid,
            'run' => $run->uid,
            'trigger' => $run->trigger_type,
            'target' => ['type' => $run->target_type->value, 'id' => $run->target_id],
            'facts' => $ctx->facts(),
            'at' => now()->toIso8601String(),
        ]);
        $secret = (string) config('services.platform_automation.webhook_secret', '');

        $response = Http::timeout(10)->withHeaders([
            'Content-Type' => 'application/json',
            'X-Platform-Signature' => 'sha256=' . hash_hmac('sha256', $payload, $secret),
        ])->withBody($payload, 'application/json')->post((string) $p['url']);

        if (! $response->successful()) {
            throw new \RuntimeException('The webhook answered ' . $response->status() . '.');
        }

        return ['result' => ['status' => $response->status()], 'operation_ref' => null];
    }

    private function note(PlatformRunContext $ctx, string $kind, array $p): array
    {
        $run = $ctx->run();
        $note = PlatformAccountNote::create([
            'uid' => (string) Str::uuid(),
            'target_type' => $run->target_type->value,
            'target_id' => $run->target_id,
            'workspace_id' => $run->workspace_id,
            'business_id' => $run->business_id,
            'kind' => $kind,
            'body' => $ctx->render((string) $p['body']),
            'source_run_id' => $run->id,
        ]);

        return ['result' => ['note' => $note->uid], 'operation_ref' => 'note:' . $note->uid];
    }

    private function businessStatus(PlatformRunContext $ctx, BusinessStatus $to, array $p, ?int $actorUserId): array
    {
        $business = $ctx->business() ?? throw new PlatformStepSkipped('No Business on this run.');
        $this->requireActor($actorUserId);

        $this->accountActions->changeBusinessStatus((int) $business->id, $to, (int) $actorUserId, (string) $p['reason']);

        return ['result' => ['business' => $business->uid, 'status' => $to->value], 'operation_ref' => 'business:' . $business->id];
    }

    private function restoreAccess(PlatformRunContext $ctx, array $p, ?int $actorUserId): array
    {
        $workspace = $ctx->workspace() ?? throw new PlatformStepSkipped('No Workspace on this run.');
        $this->requireActor($actorUserId);

        $this->accountActions->restoreAccess((int) $workspace->id, (int) $actorUserId, (string) $p['reason']);

        return ['result' => ['workspace' => $workspace->uid], 'operation_ref' => 'workspace:' . $workspace->id];
    }

    private function changePlan(PlatformRunContext $ctx, array $p, ?int $actorUserId): array
    {
        $workspace = $ctx->workspace() ?? throw new PlatformStepSkipped('No Workspace on this run.');
        $this->requireActor($actorUserId);

        $this->entitlements->changePlan($workspace, WorkspacePlanTier::from((string) $p['tier']), (int) $actorUserId, (string) $p['reason']);

        return ['result' => ['workspace' => $workspace->uid, 'tier' => $p['tier']], 'operation_ref' => 'workspace:' . $workspace->id];
    }

    /**
     * Allow/deny a feature for the run's Workspace through the canonical override authority, which
     * re-checks the approver's Platform authority, requires the reason, refuses an Allow of an
     * unavailable feature, audits the transition, and is a no-op when the state is already set
     * (so a retried step cannot double-write). The plan is never touched.
     */
    private function featureOverride(PlatformRunContext $ctx, array $p, ?int $actorUserId): array
    {
        $workspace = $ctx->workspace() ?? throw new PlatformStepSkipped('No Workspace on this run.');
        $this->requireActor($actorUserId);

        $feature = PlatformFeature::tryFrom((string) $p['feature'])
            ?? throw new \InvalidArgumentException('Unknown feature.');
        $state = WorkspaceEntitlementOverrideState::from((string) $p['state']);

        $this->entitlements->createOrChangeOverride($workspace, $feature, $state, (int) $actorUserId, (string) $p['reason']);

        return [
            'result' => ['workspace' => $workspace->uid, 'feature' => $feature->value, 'state' => $state->value],
            'operation_ref' => 'override:' . $workspace->id . ':' . $feature->value . ':' . $state->value,
        ];
    }

    private function requireActor(?int $actorUserId): void
    {
        if ($actorUserId === null) {
            throw new \LogicException('An approving Platform Owner is required.');
        }
    }

    /** @return list<User> */
    private function recipientsOrSkip(PlatformRunContext $ctx, string $recipient): array
    {
        $users = $ctx->recipients($recipient);

        if ($users === []) {
            throw new PlatformStepSkipped('There is no ' . $recipient . ' for this run.');
        }

        return $users;
    }
}
