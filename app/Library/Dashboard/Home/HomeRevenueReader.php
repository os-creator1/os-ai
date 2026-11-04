<?php

namespace App\Library\Dashboard\Home;

use App\Enums\Calendar\AppointmentStatus;
use App\Enums\Crm\CrmOpportunityStatus;
use App\Enums\Documents\DocumentStatus;
use App\Library\Crm\CrmLocationScope;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\CrmOpportunity;
use Carbon\CarbonImmutable;

/**
 * Business Home — the revenue pipeline as it ACTUALLY stands.
 *
 * Every row is one open CRM opportunity the actor may reach (CrmLocationScope, per
 * Location), and its state and next step are derived only from persisted facts: the
 * opportunity's newest non-void document and its next scheduled appointment. Nothing
 * is estimated, scored or inferred; an opportunity with no document and no booking
 * says exactly that. Payment never moves a stage (Contract 17), so a paid document
 * surfaces as a next step ("mark won") for the owner to take — it is not done for them.
 *
 * Bounded: at most MAX_ROWS opportunities and two statements for their documents and
 * appointments, however many the Business has.
 */
final class HomeRevenueReader
{
    public const MAX_ROWS = 5;

    /** Candidates classified before ranking; bounds the work however many open deals exist. */
    public const MAX_CANDIDATES = 50;

    /**
     * DETERMINISTIC PRIORITY: the further a deal has really got, the sooner the owner is asked. Money the owner can act on
     * first, then stalled paper, then deals with nothing attached. Ties keep most-recently-updated first.
     */
    public const PRIORITY = ['paid' => 1, 'signed' => 2, 'proposal_sent' => 3, 'proposal_expired' => 4, 'proposal_draft' => 5, 'new' => 6, 'booked' => 7];

    public function __construct(private readonly CrmLocationScope $scope)
    {
    }

    /**
     * @return array{openCount: int, rows: list<array{opportunityId: int, opportunityUid: string, title: string, valueMinor: ?int, currency: ?string, state: string, stateLabel: string, nextStep: string}>}
     */
    public function read(Business $business, int $actorUserId, ?CarbonImmutable $now = null, int $limit = self::MAX_ROWS): array
    {
        $now ??= CarbonImmutable::now();

        $base = fn () => tap(
            CrmOpportunity::query()->where('business_id', $business->id)->where('status', CrmOpportunityStatus::Open->value),
            fn ($q) => $this->scope->restrict($q, $business, $actorUserId),
        );

        $openCount = $base()->count();
        $opportunities = $base()->orderByDesc('updated_at')->limit(self::MAX_CANDIDATES)->get();

        if ($opportunities->isEmpty()) {
            return ['openCount' => $openCount, 'counts' => [], 'rows' => []];
        }

        $ids = $opportunities->pluck('id')->all();

        $documents = BusinessDocument::query()
            ->where('business_id', $business->id)
            ->whereIn('crm_opportunity_id', $ids)
            ->where('status', '!=', DocumentStatus::Void->value)
            ->orderByDesc('id')
            ->get(['id', 'crm_opportunity_id', 'status'])
            ->groupBy('crm_opportunity_id');

        $appointments = Appointment::query()
            ->whereIn('crm_opportunity_id', $ids)
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('start_at', '>=', $now)
            ->orderBy('start_at')
            ->get(['id', 'crm_opportunity_id', 'start_at'])
            ->groupBy('crm_opportunity_id');

        $rows = [];

        foreach ($opportunities as $opportunity) {
            $doc = $documents->get($opportunity->id)?->first();
            $appointment = $appointments->get($opportunity->id)?->first();
            [$state, $label, $next] = $this->classify($doc?->status, $appointment?->start_at);

            $rows[] = [
                'opportunityId' => (int) $opportunity->id,
                'opportunityUid' => (string) $opportunity->uid,
                'title' => (string) $opportunity->title,
                'valueMinor' => $opportunity->value_minor,
                'currency' => $opportunity->currency_code,
                'state' => $state,
                'stateLabel' => $label,
                'priority' => self::PRIORITY[$state],
                'nextStep' => $next,
            ];
        }

        // Stable sort: PHP 8 keeps the updated-at order among equal priorities.
        usort($rows, fn (array $a, array $b): int => self::PRIORITY[$a['state']] <=> self::PRIORITY[$b['state']]);

        return ['openCount' => $openCount, 'counts' => array_count_values(array_column($rows, 'state')), 'rows' => array_slice($rows, 0, max(1, $limit))];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function classify(?DocumentStatus $document, mixed $appointmentAt): array
    {
        // Money state outranks everything: it is the furthest the journey has actually got.
        return match (true) {
            $document === DocumentStatus::Paid => ['paid', 'Paid in full', 'Mark this deal won'],
            $document === DocumentStatus::Signed => ['signed', 'Signed, awaiting payment', 'Collect payment'],
            $document === DocumentStatus::Sent => ['proposal_sent', 'Proposal sent', 'Follow up on the proposal'],
            $document === DocumentStatus::Expired => ['proposal_expired', 'Proposal expired', 'Send a fresh proposal'],
            $document === DocumentStatus::Draft => ['proposal_draft', 'Proposal drafted', 'Finish and send the proposal'],
            $appointmentAt !== null => ['booked', 'Booked ' . CarbonImmutable::parse($appointmentAt)->format('M j'), 'Send a proposal after the appointment'],
            default => ['new', 'No booking or proposal yet', 'Offer a booking or send a proposal'],
        };
    }
}
