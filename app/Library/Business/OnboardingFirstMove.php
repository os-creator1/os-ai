<?php

namespace App\Library\Business;

use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * Adaptive onboarding — reads what the Business has ALREADY configured (persisted rows only) and names its single biggest unmet
 * need, in a fixed order. Nothing is asked twice, nothing is estimated, and the same facts always give the same answer. The
 * customer is not walked through a checklist: they see what is in place and the one thing that matters most next.
 */
final class OnboardingFirstMove
{
    /**
     * @param  array{website: array{choice: ?string}}  $review  OnboardingManager::review()
     * @return array{inPlace: list<string>, need: array{key: string, title: string, reason: string}}
     */
    public function for(Business $business, array $review): array
    {
        $inPlace = [];

        $website = DB::table('websites')->where('business_id', $business->id)->first(['status', 'published_revision_id']);
        $published = $website !== null && $website->status === 'published' && $website->published_revision_id !== null;
        $hostsElsewhere = ($review['website']['choice'] ?? null) === 'existing';

        $bookable = DB::table('booking_types')
            ->whereIn('business_location_id', DB::table('business_locations')->where('business_id', $business->id)->select('id'))
            ->where('is_active', true)
            ->exists();

        $contacts = DB::table('contacts')->where('business_id', $business->id)->exists();

        if ($published) {
            $inPlace[] = 'A published website';
        } elseif ($hostsElsewhere) {
            $inPlace[] = 'An existing website you host yourself';
        }

        if ($bookable) {
            $inPlace[] = 'Online booking';
        }

        if ($contacts) {
            $inPlace[] = 'Customers already in MotionGrove';
        }

        $need = match (true) {
            ! $published && ! $hostsElsewhere => ['key' => 'website', 'title' => 'Get your website live', 'reason' => 'You have no published website, so people searching for you have nowhere to land.'],
            ! $bookable => ['key' => 'booking', 'title' => 'Let customers book online', 'reason' => 'No booking type is active, so customers cannot book you directly.'],
            ! $contacts => ['key' => 'contacts', 'title' => 'Bring in your customers', 'reason' => 'No customers are in MotionGrove yet, so there is nobody to follow up with.'],
            default => ['key' => 'home', 'title' => 'Review your first recommendation', 'reason' => 'The basics are in place. MotionGrove picks the next move from your real data on Home.'],
        };

        return ['inPlace' => $inPlace, 'need' => $need];
    }
}
