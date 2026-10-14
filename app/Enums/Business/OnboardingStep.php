<?php

namespace App\Enums\Business;

/**
 * The customer-facing wizard is Business → Location → Services → Website →
 * Finish (see current()). Goals, Assets, Analysis, Results and Complete are
 * LEGACY values kept only so rows written by the earlier eight-step wizard
 * still cast; OnboardingManager resolves them onto the current steps, and
 * none of them is ever shown or written for new progress.
 */
enum OnboardingStep: string
{
    case Goals = 'goals';
    case Business = 'business';
    case Location = 'location';
    case Services = 'services';
    case Website = 'website';
    case Finish = 'finish';
    case Assets = 'assets';
    case Analysis = 'analysis';
    case Results = 'results';
    case Complete = 'complete';

    /** @return array<int, self> The customer-facing steps, in order. */
    public static function current(): array
    {
        return [self::Business, self::Location, self::Services, self::Website, self::Finish];
    }

    public function isLegacy(): bool
    {
        return ! in_array($this, self::current(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business',
            self::Location => 'Location',
            self::Services => 'Services',
            self::Website => 'Website',
            default => 'Finish',
        };
    }
}
