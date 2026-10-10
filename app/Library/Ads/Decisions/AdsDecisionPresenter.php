<?php

namespace App\Library\Ads\Decisions;

use App\Models\Business;
use Illuminate\Support\Facades\Route;

/**
 * Acquisition Purpose + Ads Decisioning V1 — the view model of the decision
 * panel: each decision paired with its resolved call to action, plus the link to
 * the Goals page. Pure composition; it decides nothing.
 */
final class AdsDecisionPresenter
{
    public function __construct(private readonly AdsDecisionCtaResolver $ctas)
    {
    }

    /**
     * @return array{
     *     primary: ?array{decision: AdsDecision, cta: ?array{label: string, url: ?string, external: bool}},
     *     others: list<array{decision: AdsDecision, cta: ?array{label: string, url: ?string, external: bool}}>,
     *     unassigned: list<array{uid: string, name: string, spend_micros: int}>,
     *     goalsUrl: ?string,
     *     hasGoals: bool,
     *     hasCampaigns: bool,
     *     currency: string
     * }
     */
    public function present(AdsDecisionPanel $panel, string $provider, Business $business, bool $hasFullModule): array
    {
        $rows = array_map(fn (AdsDecision $d): array => [
            'decision' => $d,
            'cta' => $this->ctas->resolve($d, $provider, $business, $hasFullModule),
        ], $panel->decisions);

        return [
            'primary' => $rows[0] ?? null,
            'others' => array_slice($rows, 1),
            'unassigned' => $panel->unassigned,
            'goalsUrl' => Route::has('customer.workspaces.businesses.ads.goals')
                ? route('customer.workspaces.businesses.ads.goals', [(string) $business->workspace->uid, (string) $business->uid])
                : null,
            'hasGoals' => $panel->hasGoals,
            'hasCampaigns' => $panel->hasCampaigns,
            'currency' => strtoupper((string) $business->currency_code),
        ];
    }
}
