<?php

namespace App\Library\PlatformAutomation;

use App\Models\Business;
use BackedEnum;
use App\Models\PlatformAutomationRun;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Everything a step may know about its run, resolved ONLY from the ids stored on the
 * run (workspace_id / business_id / user_id) — never from "the current Business",
 * the session, or a global first() lookup. Two Workspaces' runs can therefore never
 * see each other's recipients, facts or merge values.
 */
final class PlatformRunContext
{
    public function __construct(private readonly PlatformAutomationRun $run)
    {
    }

    public function run(): PlatformAutomationRun
    {
        return $this->run;
    }

    public function workspace(): ?Workspace
    {
        $id = $this->run->workspace_id;

        return $id === null ? null : Workspace::query()->find($id);
    }

    public function business(): ?Business
    {
        $id = $this->run->business_id;

        return $id === null ? null : Business::query()->find($id);
    }

    public function targetUser(): ?User
    {
        $id = $this->run->user_id;

        return $id === null ? null : User::query()->find($id);
    }

    /** @return list<User> the people a "recipient" resolves to for THIS run; empty when the run has no such person */
    public function recipients(string $recipient): array
    {
        $user = match ($recipient) {
            'target_user' => $this->targetUser(),
            'workspace_owner' => ($w = $this->workspace()) === null ? null : User::query()->find($w->owner_user_id),
            'business_owner' => ($b = $this->business()) === null ? null : User::query()->find($b->customer_id),
            default => null,
        };

        if ($recipient === 'platform_admins') {
            return User::query()->where('is_admin', true)->where('status', true)->orderBy('id')->get()->all();
        }

        return $user === null ? [] : [$user];
    }

    /** @return array<string, string> condition facts for this run */
    public function facts(): array
    {
        $facts = [];
        $workspaceId = $this->run->workspace_id;

        if ($workspaceId !== null) {
            $tier = DB::table('workspace_plan_assignments as a')
                ->join('workspace_plan_catalog as c', 'c.id', '=', 'a.workspace_plan_catalog_id')
                ->where('a.workspace_id', $workspaceId)->value('c.tier');
            $facts['plan_tier'] = $tier === null ? '' : (string) $tier;
            $facts['workspace_active'] = ($this->workspace()?->is_active) ? 'yes' : 'no';
            $status = PlatformSubscription::query()->where('workspace_id', $workspaceId)->value('status');
            $facts['subscription_status'] = $status instanceof BackedEnum ? (string) $status->value : (string) ($status ?? '');
        }

        foreach ((array) ($this->run->context ?? []) as $key => $value) {
            if (is_scalar($value) && isset(PlatformAutomationCatalog::FACTS[$key])) {
                $facts[$key] = (string) $value;
            }
        }

        return $facts;
    }

    /** @return array<string, string> whitelisted merge tokens -> values */
    public function mergeValues(?User $recipient = null): array
    {
        $user = $recipient ?? $this->targetUser();
        $subscription = $this->run->workspace_id === null ? null
            : PlatformSubscription::query()->where('workspace_id', $this->run->workspace_id)->first();
        $trialEnds = $subscription?->trial_ends_at;

        $plan = $this->run->workspace_id === null ? null : DB::table('workspace_plan_assignments as a')
            ->join('workspace_plan_catalog as c', 'c.id', '=', 'a.workspace_plan_catalog_id')
            ->where('a.workspace_id', $this->run->workspace_id)->value('c.display_name');

        return [
            'user.first_name' => (string) ($user?->first_name ?? 'there'),
            'user.email' => (string) ($user?->email ?? ''),
            'workspace.name' => (string) ($this->workspace()?->name ?? ''),
            'business.name' => (string) ($this->business()?->name ?? ''),
            'plan.name' => (string) ($plan ?? ''),
            'trial.ends_at' => $trialEnds === null ? '' : $trialEnds->format('F j, Y'),
            'trial.days_left' => $trialEnds === null ? '' : (string) max(0, (int) now()->diffInDays($trialEnds, false)),
            'platform.name' => (string) config('app.name'),
        ];
    }

    /** Replaces {{token}} with its value; unknown tokens were already refused at save time. */
    public function render(string $text, ?User $recipient = null): string
    {
        $values = $this->mergeValues($recipient);

        return (string) preg_replace_callback('/\{\{\s*([^}]*?)\s*\}\}/', fn ($m) => $values[$m[1]] ?? '', $text);
    }
}
