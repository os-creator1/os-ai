<?php

namespace App\Library\PlatformAutomation;

use App\Enums\PlatformAutomation\PlatformAutomationStatus;
use App\Models\PlatformAutomation;
use Illuminate\Support\Facades\DB;

/**
 * Triggers that are STATES, not events: they are found by a sweep (every 15 minutes).
 * Each candidate is a stored fact (a trial end date, an unverified email, a Business
 * without finished onboarding, rows in failed_jobs) and each fires with a stable
 * occurrence key, so a candidate seen by every sweep still produces ONE run.
 *
 * Look-back windows keep a newly enabled automation from firing for every old row.
 */
class PlatformScheduledTriggers
{
    private const CANDIDATE_CAP = 500;
    private const LOOKBACK_DAYS = 30;

    public function __construct(private readonly PlatformTriggerDispatcher $dispatcher)
    {
    }

    /** @return array<string, int> runs created per trigger */
    public function sweep(): array
    {
        $created = [];

        foreach (['user.email_verification_pending', 'business.onboarding_incomplete', 'subscription.trial_ending', 'subscription.trial_ended', 'ops.repeated_job_failures'] as $trigger) {
            $created[$trigger] = 0;

            PlatformAutomation::query()
                ->where('status', PlatformAutomationStatus::Enabled->value)
                ->where('trigger_type', $trigger)
                ->orderBy('id')
                ->each(function (PlatformAutomation $automation) use ($trigger, &$created) {
                    $created[$trigger] += $this->sweepOne($automation);
                });
        }

        return $created;
    }

    private function sweepOne(PlatformAutomation $automation): int
    {
        $params = (array) ($automation->definition['params'] ?? []);
        $n = 0;

        $fire = function (PlatformTarget $target, string $key, array $context = []) use ($automation, &$n) {
            if ($this->dispatcher->fireFor($automation, $target, $key, $context) !== null) {
                $n++;
            }
        };

        switch ($automation->trigger_type) {
            case 'user.email_verification_pending':
                $hours = (int) ($params['hours'] ?? 24);
                $users = DB::table('users')
                    ->where('is_customer', true)->where('is_admin', false)->where('status', true)
                    ->whereNull('email_verified_at')
                    ->where('created_at', '<=', now()->subHours($hours))
                    ->where('created_at', '>', now()->subDays(self::LOOKBACK_DAYS))
                    ->orderBy('id')->limit(self::CANDIDATE_CAP)->pluck('id');
                foreach ($users as $id) {
                    $fire(PlatformTarget::user((int) $id), "user:{$id}:verification_pending:{$hours}");
                }
                break;

            case 'business.onboarding_incomplete':
                $hours = (int) ($params['hours'] ?? 24);
                $businesses = DB::table('businesses as b')
                    ->join('customer_onboardings as o', 'o.customer_id', '=', 'b.customer_id')
                    ->where('o.status', '!=', 'completed')
                    ->where('b.created_at', '<=', now()->subHours($hours))
                    ->where('b.created_at', '>', now()->subDays(self::LOOKBACK_DAYS))
                    ->orderBy('b.id')->limit(self::CANDIDATE_CAP)->pluck('b.id');
                foreach ($businesses as $id) {
                    $target = PlatformTarget::business((int) $id);
                    if ($target !== null) {
                        $fire($target, "business:{$id}:onboarding_incomplete:{$hours}");
                    }
                }
                break;

            case 'subscription.trial_ending':
                $days = (int) ($params['days_before'] ?? 3);
                $subs = DB::table('platform_subscriptions')
                    ->where('status', 'trialing')
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '>', now())
                    ->where('trial_ends_at', '<=', now()->addDays($days))
                    ->orderBy('id')->limit(self::CANDIDATE_CAP)->get(['id', 'workspace_id', 'trial_ends_at']);
                foreach ($subs as $sub) {
                    $fire(PlatformTarget::workspace((int) $sub->workspace_id, (int) $sub->id), "sub:{$sub->id}:trial_ending:{$days}:" . substr((string) $sub->trial_ends_at, 0, 10));
                }
                break;

            case 'subscription.trial_ended':
                $subs = DB::table('platform_subscriptions')
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '<=', now())
                    ->where('trial_ends_at', '>', now()->subDays(2))
                    ->orderBy('id')->limit(self::CANDIDATE_CAP)->get(['id', 'workspace_id', 'trial_ends_at']);
                foreach ($subs as $sub) {
                    $fire(PlatformTarget::workspace((int) $sub->workspace_id, (int) $sub->id), "sub:{$sub->id}:trial_ended:" . substr((string) $sub->trial_ends_at, 0, 10));
                }
                break;

            case 'ops.repeated_job_failures':
                $threshold = (int) ($params['threshold'] ?? 10);
                $window = (int) ($params['window_minutes'] ?? 60);
                $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subMinutes($window))->count();
                if ($count >= $threshold) {
                    $fire(PlatformTarget::platform(), 'ops:failed_jobs:' . now()->format('YmdH'), ['failed_jobs' => $count]);
                }
                break;
        }

        return $n;
    }
}
