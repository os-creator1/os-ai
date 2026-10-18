<?php

namespace App\Library\Ads\Decisions;

/**
 * Acquisition Purpose + Ads Decisioning V1 — the CLOSED vocabulary of what an
 * owner is told to do. Deterministic: no AI ever chooses, changes or softens
 * one of these.
 *
 * `urgency()` orders competing decisions (several goals, one provider page):
 * the highest-urgency decision is the one shown first.
 */
enum AdsDecisionState: string
{
    case CheckTracking = 'check_tracking';
    case Act = 'act';
    case FixTheFunnel = 'fix_the_funnel';
    case Watch = 'watch';
    case NotEnoughData = 'not_enough_data';
    case Wait = 'wait';
    case KeepRunning = 'keep_running';

    public function label(): string
    {
        return match ($this) {
            self::CheckTracking => 'Check tracking',
            self::Act => 'Act',
            self::FixTheFunnel => 'Fix the funnel',
            self::Watch => 'Watch',
            self::NotEnoughData => 'Not enough data',
            self::Wait => 'Wait',
            self::KeepRunning => 'Keep running',
        };
    }

    /** Higher = shown first. */
    public function urgency(): int
    {
        return match ($this) {
            self::CheckTracking => 70,
            self::Act => 60,
            self::FixTheFunnel => 50,
            self::Watch => 40,
            self::NotEnoughData => 30,
            self::Wait => 20,
            self::KeepRunning => 10,
        };
    }

    /** design-system tone the panel uses; never colour alone (the label is always printed). */
    public function tone(): string
    {
        return match ($this) {
            self::CheckTracking, self::Act => 'danger',
            self::FixTheFunnel, self::Watch => 'warning',
            self::KeepRunning => 'success',
            self::NotEnoughData, self::Wait => 'neutral',
        };
    }
}
