<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Enums\Seo\ArticleStatus;
use App\Exceptions\Seo\ArticleException;
use App\Library\Seo\Content\ArticleManager;
use App\Models\Business;
use App\Models\ContentAutopilotDecision as Decision;
use App\Models\WebsiteArticle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Content Autopilot - decide whether a validated draft may publish itself, and WHEN.
 *
 * An article schedules itself only if EVERY rule says yes:
 *   - validation found nothing at all (`validation.ready`);
 *   - the niche, by its own declared policy, is `standard` with automatic publishing `allowed` (anything unknown fails closed);
 *   - the trust ramp is over: the Business has already seen TRUST_RAMP_ARTICLES Autopilot articles published, so the owner
 *     has approved the first ones themselves;
 *   - it is not a burst: the slot is at least `min_days_between_articles` after the previous Autopilot article.
 * Anything else waits for the owner as an ordinary Draft (`awaiting_approval`).
 *
 * "Publishes itself" means scheduling through ArticleManager::schedule: the existing `articles:publish-due` goes live at the
 * slot and RE-CHECKS the article first (a draft that no longer passes goes back to Draft instead of publishing). There is no
 * second publishing path. The slot is a weekday morning in the Business's own time zone, jittered per article.
 */
final class AutopilotPublisher
{
    public const WAIT_NOT_READY = 'not_ready';
    public const WAIT_NICHE_POLICY = 'niche_policy';
    public const WAIT_TRUST_RAMP = 'trust_ramp';
    public const WAIT_NOT_SCHEDULABLE = 'not_schedulable';

    public function __construct(
        private readonly ContentPolicy $policy,
        private readonly ArticleManager $articles,
    ) {
    }

    /** Why this draft must wait for the owner, or null when it may schedule itself. */
    public function approvalReason(Decision $decision): ?string
    {
        if (($decision->validation['ready'] ?? false) !== true) {
            return self::WAIT_NOT_READY;
        }

        $business = Business::query()->find($decision->business_id);

        if ($business === null || ! $this->policy->autoPublishAllowed($business)) {
            return self::WAIT_NICHE_POLICY;
        }

        return $this->publishedSoFar($business) < ContentPolicy::TRUST_RAMP_ARTICLES ? self::WAIT_TRUST_RAMP : null;
    }

    /**
     * Schedule the decision's article if it may publish itself. Returns the reason it must wait, or null once scheduled.
     */
    public function scheduleIfAllowed(Decision $decision, ?CarbonInterface $now = null): ?string
    {
        $now ??= CarbonImmutable::now();

        if (($reason = $this->approvalReason($decision)) !== null) {
            return $reason;
        }

        $article = WebsiteArticle::query()->find($decision->article_id);
        $business = Business::query()->find($decision->business_id);

        if ($article === null || $business === null || $article->status !== ArticleStatus::Draft) {
            return self::WAIT_NOT_SCHEDULABLE;
        }

        try {
            $this->articles->schedule((int) $business->customer_id, $business, $article, $this->nextSlot($business, $now, (int) $decision->id));
        } catch (ArticleException) {
            return self::WAIT_NOT_SCHEDULABLE;
        }

        $decision->forceFill(['state' => Decision::STATE_SCHEDULED, 'reason_code' => 'auto_scheduled'])->save();

        return null;
    }

    /** How many Autopilot articles this Business has had published (the trust ramp counts publications, not drafts). */
    public function publishedSoFar(Business $business): int
    {
        return WebsiteArticle::query()->where('business_id', $business->id)->where('source', 'autopilot')->whereNotNull('published_at')->count();
    }

    /**
     * The next self-publishing slot: after `min_days_between_articles` since the latest Autopilot article was published or
     * scheduled, on a weekday between 09:00 and 11:59 in the Business's time zone (jittered per decision), never in the past.
     */
    public function nextSlot(Business $business, CarbonInterface $now, int $jitterSeed = 0): CarbonImmutable
    {
        $tz = $this->timezone($business);
        $earliest = CarbonImmutable::instance($now)->addHours(2);
        $last = $this->latestAutopilotTouch($business);

        if ($last !== null) {
            $spaced = CarbonImmutable::instance($last)->addDays(max(0, (int) config('seo.content_autopilot.min_days_between_articles', 5)));
            $earliest = $spaced->greaterThan($earliest) ? $spaced : $earliest;
        }

        $slot = $earliest->setTimezone($tz)->startOfDay()->addHours(9)->addMinutes(($jitterSeed * 37) % 180);

        if ($slot->lessThanOrEqualTo($earliest)) {
            $slot = $slot->addDay();
        }

        while ($slot->isWeekend()) {
            $slot = $slot->addDay();
        }

        return $slot->utc();
    }

    private function latestAutopilotTouch(Business $business): ?CarbonInterface
    {
        $articles = WebsiteArticle::query()->where('business_id', $business->id)->where('source', 'autopilot')
            ->whereIn('status', [ArticleStatus::Published->value, ArticleStatus::Scheduled->value])
            ->get(['published_at', 'scheduled_at']);

        $times = $articles->flatMap(fn (WebsiteArticle $a) => array_filter([$a->scheduled_at, $a->published_at]))->all();

        return $times === [] ? null : collect($times)->max();
    }

    private function timezone(Business $business): string
    {
        $tz = (string) $business->timezone;

        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : (string) config('app.timezone', 'UTC');
    }
}
