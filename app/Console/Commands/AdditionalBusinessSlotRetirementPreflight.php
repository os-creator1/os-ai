<?php

namespace App\Console\Commands;

use App\Enums\Usage\SlotAgreementState;
use App\Models\AdditionalBusinessSlotAgreement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Implementation Contract 11 §5/§8 — the read-only conditional gate for
 * retiring the additional-business-slot purchase flow. Runs EXACTLY the
 * contract's own authoritative active-paid-holder predicate:
 *
 *     state = 'completed' AND cancellation_effective_at IS NULL
 *
 * and saves the result as a durable artifact under the existing "local"
 * filesystem disk convention (storage/app — already gitignored by its own
 * storage/app/.gitignore, so a generated report can never be accidentally
 * committed). This command performs ZERO writes of any kind: no
 * agreement, entitlement, plan-assignment, or provider/Stripe call is
 * ever made here. It never decides commercial treatment — §8 case 3
 * requires a separately-authorized human decision for any holder this
 * reports, which this command only surfaces, never resolves.
 */
class AdditionalBusinessSlotRetirementPreflight extends Command
{
    protected $signature = 'usage:additional-business-slot-retirement-preflight';

    protected $description = 'Implementation Contract 11 — read-only preflight for retiring the additional-business-slot purchase flow';

    public function handle(): int
    {
        $report = $this->buildReport();
        $path = $this->saveReport($report);

        $this->printReport($report, $path);

        return self::SUCCESS;
    }

    /**
     * @return array{generated_at: string, predicate: string, active_holder_count: int, commercial_decision_required: bool, agreements: array<int, array<string, mixed>>}
     */
    public function buildReport(): array
    {
        $agreements = AdditionalBusinessSlotAgreement::query()
            ->where('state', SlotAgreementState::Completed->value)
            ->whereNull('cancellation_effective_at')
            ->with('workspace')
            ->orderBy('id')
            ->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'predicate' => "state = 'completed' AND cancellation_effective_at IS NULL",
            'active_holder_count' => $agreements->count(),
            'commercial_decision_required' => $agreements->isNotEmpty(),
            'agreements' => $agreements->map(fn (AdditionalBusinessSlotAgreement $agreement) => [
                'agreement_id' => $agreement->id,
                'workspace_id' => $agreement->workspace_id,
                'workspace_uid' => $agreement->workspace?->uid,
                'workspace_name' => $agreement->workspace?->name,
                'current_allocation_count' => $agreement->current_allocation_count,
                'target_allocation_count' => $agreement->target_allocation_count,
                'next_renewal_at' => $agreement->next_renewal_at?->toIso8601String(),
                'requesting_customer_email_snapshot' => $agreement->requesting_customer_email_snapshot,
                'provider_session_or_intent_reference' => $agreement->provider_session_or_intent_reference,
            ])->values()->all(),
        ];
    }

    /**
     * Durable artifact — the narrowest existing convention that produces a
     * stable saved report without new schema: the standard "local"
     * filesystem disk (storage/app), whose own storage/app/.gitignore
     * already excludes everything under it from Git except the public/
     * subdirectory.
     */
    private function saveReport(array $report): string
    {
        $path = 'reports/additional-business-slot-retirement-preflight-' . now()->format('Ymd-His') . '-' . uniqid() . '.json';

        Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function printReport(array $report, string $path): void
    {
        $this->info('Contract 11 — additional-business-slot retirement preflight');
        $this->line("Predicate: {$report['predicate']}");
        $this->line("Active paid-holder count: {$report['active_holder_count']}");
        $this->line('Durable report saved to: ' . Storage::disk('local')->path($path));

        if ($report['active_holder_count'] === 0) {
            $this->info('Zero active paid holders — no commercial-treatment decision is required. The checkout entry point may be disabled unconditionally.');

            return;
        }

        $this->warn('One or more active paid holders found. Do NOT cancel, revoke, stop renewal, refund, convert, or otherwise modify these agreements. A separately-authorized human commercial decision is required before they are touched (Contract 11 §8 case 3). The checkout entry point for NEW agreements may still be disabled regardless of this outcome.');

        foreach ($report['agreements'] as $agreement) {
            $this->line(sprintf(
                '  Agreement #%d — Workspace #%d (%s, %s): allocation=%d/%d next_renewal_at=%s',
                $agreement['agreement_id'],
                $agreement['workspace_id'],
                $agreement['workspace_uid'] ?? 'unknown-uid',
                $agreement['workspace_name'] ?? 'unknown-name',
                $agreement['current_allocation_count'],
                $agreement['target_allocation_count'],
                $agreement['next_renewal_at'] ?? 'none',
            ));
        }
    }
}
