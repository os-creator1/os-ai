<?php

namespace App\Library\Dashboard;

/**
 * Customer Experience Slice 4 §13 — everything one Dashboard render needs,
 * assembled before the view runs, so the view executes no query at all.
 *
 * `kind` is the branch §3 resolved from the CustomerContext frame, and
 * nothing else:
 *
 *  business  Business Home (§4): the selected Business, or the viewed client
 *  agency    Agency Account Home (§7): an Agency owner or admin, no client chosen
 *  chooser   several Businesses available and none chosen (§12, Slice 1B)
 *  zero      no Business the actor can enter yet (§12)
 *
 * `bands` holds only the bands that render, in page order. A band whose
 * source failed is listed in `failedBands` instead, and the view degrades it
 * to one plain line; the rest of the page still renders (§12).
 */
final class DashboardSnapshot
{
    public const KIND_BUSINESS = 'business';
    public const KIND_AGENCY = 'agency';
    public const KIND_CHOOSER = 'chooser';
    public const KIND_ZERO = 'zero';

    public const BAND_ATTENTION = 'attention';
    public const BAND_RECOMMENDATIONS = 'recommendations';
    public const BAND_HEADLINES = 'headlines';
    public const BAND_SPEND = 'spend';
    public const BAND_ACTIONS = 'actions';
    public const BAND_CLIENTS = 'clients';
    public const BAND_CAPACITY = 'capacity';
    public const BAND_PROSPECTING = 'prospecting';
    public const BAND_ACCOUNT = 'account';
    public const BAND_CHOOSER = 'chooser';
    public const BAND_TEAM_ACCOUNT = 'team_account';

    /**
     * @param  array<string, mixed>  $bands  band key => payload, in render order
     * @param  array<int, string>  $failedBands
     * @param  array<string, mixed>|null  $emptyState  the zero state's empty-state parameters
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $frameLabel,
        public readonly string $heading,
        public readonly array $bands = [],
        public readonly array $failedBands = [],
        public readonly ?array $emptyState = null,
    ) {
    }

    public function has(string $band): bool
    {
        return array_key_exists($band, $this->bands);
    }

    public function band(string $band): mixed
    {
        return $this->bands[$band] ?? null;
    }

    public function failed(string $band): bool
    {
        return in_array($band, $this->failedBands, true);
    }
}
