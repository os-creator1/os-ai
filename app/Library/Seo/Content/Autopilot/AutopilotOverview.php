<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Enums\Seo\ArticleStatus;
use App\Models\Business;
use App\Models\ContentAutopilotDecision as Decision;
use App\Models\ContentAutopilotSetting;
use App\Models\WebsiteArticle;
use Carbon\CarbonInterface;

/**
 * Content Autopilot - everything the owner's home for Content shows, read from what Autopilot already recorded. Read-only and
 * free: it makes no model call and runs no evaluation. The owner sees one thing to look at, never a list of SEO topics.
 *
 *   status        off / running / paused (and why, in plain words);
 *   next          the single thing happening next - a scheduled article, one being written, one waiting, or "nothing yet";
 *   month         X published, Y planned - with the maximum stated as a ceiling, never a target;
 *   question      the ONE thing Autopilot needs from the owner, only when it genuinely does;
 *   approval      drafts waiting for the owner, each with the reason it is not publishing itself;
 *   recent        what Autopilot published lately.
 */
final class AutopilotOverview
{
    private const WORKING = [Decision::STATE_BRIEFED, Decision::STATE_DRAFTING, Decision::STATE_VALIDATING];

    private const WAIT_TEXT = [
        AutopilotPublisher::WAIT_TRUST_RAMP => 'Your first articles always wait for you, so you can see how Autopilot writes.',
        AutopilotPublisher::WAIT_NICHE_POLICY => 'Articles in your field are always reviewed by you before they go live.',
        AutopilotPublisher::WAIT_NOT_SCHEDULABLE => 'It could not be scheduled automatically.',
    ];

    private const PAUSE_TEXT = [
        AutopilotSwitch::PAUSE_OWNER => 'Paused by you.',
        AutopilotSwitch::PAUSE_BUDGET => 'Paused until next month: this month\'s Autopilot allowance has been used. Nothing is lost.',
        AutopilotSwitch::PAUSE_NO_WEBSITE => 'Waiting for your website: articles are published through it.',
        AutopilotSwitch::PAUSE_PLAN => 'Not included in your current plan.',
    ];

    private const IDLE_TEXT = [
        AutopilotPlanner::REASON_MONTHLY_CEILING => 'Done for this month. Autopilot writes only when there is something worth saying.',
        AutopilotPlanner::REASON_NO_CANDIDATES => 'Nothing worth writing right now. Autopilot only writes when it has something genuinely useful to say.',
        AutopilotPlanner::REASON_WAITING => 'Waiting for the right moment: a few useful topics are not ready yet.',
        AutopilotPlanner::REASON_NO_WEBSITE => 'Waiting for your website.',
    ];

    public function __construct(private readonly AutopilotPublisher $publisher)
    {
    }

    /** @return array<string, mixed> */
    public function forBusiness(Business $business, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $setting = ContentAutopilotSetting::query()->where('business_id', $business->id)->first();
        $enabled = (bool) $setting?->enabled;
        $paused = $enabled ? $setting->paused_reason : null;

        return [
            'enabled' => $enabled,
            'paused' => $paused,
            'running' => $enabled && $paused === null,
            'statusText' => $paused !== null ? (self::PAUSE_TEXT[$paused] ?? 'Paused.') : ($enabled ? 'On. Autopilot checks every day whether something useful is worth writing.' : 'Off.'),
            'profileCompleted' => $setting?->profile_completed_at !== null,
            'next' => $this->next($business, $enabled),
            'month' => $this->month($business, $now),
            'question' => $this->question($business),
            'approval' => $this->approval($business),
            'recent' => $this->recent($business),
        ];
    }

    /** @return array{kind: string, title: ?string, detail: string, article_uid: ?string, at: ?CarbonInterface} */
    private function next(Business $business, bool $enabled): array
    {
        $scheduled = Decision::query()->where('business_id', $business->id)->where('state', Decision::STATE_SCHEDULED)->whereNotNull('article_id')->with('article')->get()
            ->filter(fn (Decision $d) => $d->article?->status === ArticleStatus::Scheduled)
            ->sortBy(fn (Decision $d) => $d->article->scheduled_at)->first();

        if ($scheduled !== null) {
            return ['kind' => 'scheduled', 'title' => $scheduled->article->title, 'detail' => 'Scheduled to go live.', 'article_uid' => (string) $scheduled->article->uid, 'at' => $scheduled->article->scheduled_at];
        }

        $working = Decision::query()->where('business_id', $business->id)->whereIn('state', self::WORKING)->latest('id')->first();

        if ($working !== null) {
            return ['kind' => 'writing', 'title' => $working->brief['topic'] ?? null, 'detail' => 'Being written from your business facts.', 'article_uid' => null, 'at' => null];
        }

        $deferred = Decision::query()->where('business_id', $business->id)->where('state', Decision::STATE_DEFERRED_BUDGET)->latest('id')->first();

        if ($deferred !== null) {
            return ['kind' => 'deferred', 'title' => $deferred->brief['topic'] ?? null, 'detail' => 'Chosen, and will be written when next month\'s allowance starts.', 'article_uid' => null, 'at' => null];
        }

        $waiting = Decision::query()->where('business_id', $business->id)->where('state', Decision::STATE_AWAITING_APPROVAL)->whereNotNull('article_id')->with('article')->latest('id')->first();

        if ($waiting !== null && $waiting->article !== null) {
            return ['kind' => 'approval', 'title' => $waiting->article->title, 'detail' => 'Ready for you to review.', 'article_uid' => (string) $waiting->article->uid, 'at' => null];
        }

        $last = Decision::query()->where('business_id', $business->id)->whereIn('decision', [Decision::DECISION_NONE, Decision::DECISION_HOLD])->latest('id')->first();

        return [
            'kind' => 'idle',
            'title' => null,
            'detail' => $enabled
                ? (self::IDLE_TEXT[$last?->reason_code ?? ''] ?? 'Nothing is planned yet. Autopilot only writes when it has something genuinely useful to say.')
                : 'Turn Autopilot on and it will decide, day by day, whether something useful is worth writing.',
            'article_uid' => null,
            'at' => null,
        ];
    }

    /** @return array{published: int, planned: int, max: int} */
    private function month(Business $business, CarbonInterface $now): array
    {
        $published = WebsiteArticle::query()->where('business_id', $business->id)->where('source', 'autopilot')
            ->whereBetween('published_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])->count();

        $planned = Decision::query()->where('business_id', $business->id)->where('decision', Decision::DECISION_CREATE)
            ->whereIn('state', [...self::WORKING, Decision::STATE_AWAITING_APPROVAL, Decision::STATE_SCHEDULED, Decision::STATE_DEFERRED_BUDGET])->count();

        return ['published' => $published, 'planned' => $planned, 'max' => max(0, (int) config('seo.content_autopilot.max_new_articles_per_month', 4))];
    }

    /** @return array{key: string, prompt: string}|null */
    private function question(Business $business): ?array
    {
        $open = Decision::query()->where('business_id', $business->id)->where('state', Decision::STATE_NEEDS_INPUT)->latest('id')->first();

        return $open === null ? null : ['key' => (string) $open->needs_input['key'], 'prompt' => (string) $open->needs_input['prompt']];
    }

    /** @return list<array{article_uid: string, title: string, note: string, ready: bool}> */
    private function approval(Business $business): array
    {
        return Decision::query()->where('business_id', $business->id)->where('state', Decision::STATE_AWAITING_APPROVAL)->whereNotNull('article_id')->with('article')->orderBy('id')->get()
            ->filter(fn (Decision $d) => $d->article !== null && $d->article->status === ArticleStatus::Draft)
            ->map(function (Decision $d) {
                $wait = $this->publisher->approvalReason($d);
                $soft = (array) ($d->validation['soft'] ?? []);

                return [
                    'article_uid' => (string) $d->article->uid,
                    'title' => (string) $d->article->title,
                    'ready' => ($d->validation['ready'] ?? false) === true,
                    'note' => $wait === AutopilotPublisher::WAIT_NOT_READY
                        ? 'Worth a quick look: ' . ($soft[0] ?? 'a detail needs your OK.')
                        : (self::WAIT_TEXT[$wait ?? ''] ?? 'Ready for your OK.'),
                ];
            })->values()->all();
    }

    /** @return list<array{uid: string, title: string, published_at: ?CarbonInterface}> */
    private function recent(Business $business): array
    {
        return WebsiteArticle::query()->where('business_id', $business->id)->where('source', 'autopilot')->where('status', ArticleStatus::Published->value)
            ->orderByDesc('published_at')->limit(5)->get(['uid', 'title', 'published_at'])
            ->map(fn (WebsiteArticle $a) => ['uid' => (string) $a->uid, 'title' => (string) $a->title, 'published_at' => $a->published_at])->all();
    }
}
