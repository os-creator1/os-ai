<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Models\Business;
use App\Models\ContentAutopilotSetting;

/**
 * Content Autopilot - the on/off switch. The only writer of `enabled` and `paused_reason`; the owner page (and nothing else)
 * turns Autopilot on or off, and the runner records WHY a switched-on Autopilot is not currently running.
 *
 * Turning it off never touches an article: drafts stay drafts, scheduled articles keep their time (the owner can still
 * unschedule them in the editor), published articles stay published.
 */
final class AutopilotSwitch
{
    public const PAUSE_OWNER = 'owner';
    public const PAUSE_BUDGET = 'budget';
    public const PAUSE_NO_WEBSITE = 'no_website';
    public const PAUSE_PLAN = 'plan';

    public function enable(Business $business, int $actorUserId): ContentAutopilotSetting
    {
        $setting = ContentAutopilotSetting::query()->firstOrNew(['business_id' => $business->id]);

        if (! $setting->enabled) {
            $setting->enabled_at = now();
            $setting->enabled_by_user_id = $actorUserId;
        }

        $setting->enabled = true;
        $setting->paused_reason = null;
        $setting->save();

        return $setting;
    }

    public function disable(Business $business): ContentAutopilotSetting
    {
        $setting = ContentAutopilotSetting::query()->firstOrNew(['business_id' => $business->id]);
        $setting->enabled = false;
        $setting->paused_reason = null;
        $setting->save();

        return $setting;
    }

    /** The owner paused it: stays on, does nothing, until resumed. */
    public function pause(Business $business): ContentAutopilotSetting
    {
        return $this->markPaused($business, self::PAUSE_OWNER);
    }

    public function resume(Business $business): ContentAutopilotSetting
    {
        return $this->markPaused($business, null);
    }

    /** Record (or clear) why a switched-on Autopilot is idle. An owner pause is never overwritten by the system. */
    public function markPaused(Business $business, ?string $reason): ContentAutopilotSetting
    {
        $setting = ContentAutopilotSetting::query()->firstOrNew(['business_id' => $business->id]);

        if ($setting->paused_reason === self::PAUSE_OWNER && $reason !== null && $reason !== self::PAUSE_OWNER) {
            return $setting;
        }

        $setting->paused_reason = $reason;
        $setting->save();

        return $setting;
    }

    public function isRunning(Business $business): bool
    {
        $setting = ContentAutopilotSetting::query()->where('business_id', $business->id)->first();

        return $setting !== null && $setting->enabled && $setting->paused_reason === null;
    }
}
