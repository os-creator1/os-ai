<?php

namespace App\Library\Website\Setup;

use App\Library\GoogleBusinessProfile\GoogleBusinessProfileStatusReader;
use App\Models\Business;
use Illuminate\Support\Facades\Auth;

/**
 * The Website review/social-proof component's view of "where could this
 * website's reviews come from". It is deliberately a BOUNDARY:
 *
 *  - `manual` — owner-confirmed testimonials (BusinessKnowledgeProfile);
 *    the one source that supplies review content today.
 *  - `google_business_profile` — only the connection STATE, read through
 *    GBP's own read-only GoogleBusinessProfileStatusReader (no provider
 *    call, no credentials, nothing copied into Website). GBP has no
 *    reviews/ratings read seam and its contract forbids storing review
 *    content, so `import_available` is false and the component never shows
 *    a rating, a review count or review text it does not have.
 *
 * Each source is a self-contained entry in one list, so a future canonical
 * GBP Reviews read/sync seam plugs in by flipping `import_available` and
 * supplying items — the setup screen, generation and the public section
 * need no structural change.
 */
final class WebsiteReviewSourceStatus
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_GBP = 'google_business_profile';

    public function __construct(private readonly GoogleBusinessProfileStatusReader $gbp)
    {
    }

    /**
     * @param  array<int, array<string, mixed>>  $screen  the wizard screen's steps
     * @return ?array{sources: array<int, array<string, mixed>>} null when the screen has no reviews/testimonials step
     */
    public function forBusiness(Business $business, array $screen): ?array
    {
        $hasReviewsStep = collect($screen)->contains(
            fn (array $s) => ($s['target_module'] ?? null) === 'knowledge_profile' && ($s['target_field'] ?? null) === 'testimonials'
        );

        if (! $hasReviewsStep) {
            return null;
        }

        return ['sources' => [$this->manualSource(), $this->googleSource($business)]];
    }

    /**
     * @return array<string, mixed>
     */
    private function manualSource(): array
    {
        return [
            'key' => self::SOURCE_MANUAL,
            'label' => 'Reviews you confirm yourself',
            'state' => 'available',
            'import_available' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function googleSource(Business $business): array
    {
        $user = Auth::user();
        $statuses = $user !== null && $business->workspace !== null
            ? $this->gbp->forBusiness($business->workspace, $business, $user)
            : null;

        return self::googleSourceFor($statuses);
    }

    /**
     * Maps GBP's read-only location statuses to this component's source
     * entry. `null` (this actor or plan cannot see GBP at all) says nothing
     * about GBP; otherwise "connected" means at least one bound location on
     * an active connection.
     *
     * @param  ?array<int, \App\DTO\GoogleBusinessProfile\GoogleLocationStatus>  $statuses
     * @return array<string, mixed>
     */
    public static function googleSourceFor(?array $statuses): array
    {
        if ($statuses === null) {
            return ['key' => self::SOURCE_GBP, 'label' => 'Google Business Profile', 'state' => 'unavailable', 'import_available' => false];
        }

        $connected = collect($statuses)->contains(fn ($s) => $s->bound && $s->connectionState === 'active');

        return [
            'key' => self::SOURCE_GBP,
            'label' => 'Google Business Profile',
            'state' => $connected ? 'connected' : 'not_connected',
            // GBP exposes no reviews read seam yet — never claim otherwise.
            'import_available' => false,
        ];
    }
}
