<?php

namespace App\Console\Commands;

use App\Library\Messaging\FailedNumberOrderRefunder;
use App\Library\Messaging\ProvisioningIncidentRecorder;
use App\Library\Messaging\TelnyxProvisioningAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Finishes the setup of LOCAL numbers whose order outcome was unknown ("order accepted but still pending") once the
 * carrier confirms them, with bounded, backed-off retries and a clear escalation.
 *
 * It adds no verification of its own: every attempt runs `messaging:recover-pending-number --execute`, whose checks are
 * fail-closed (Telnyx order success, the active number resource, its Messaging Profile and account, the Business
 * identity and wallet reservation, and the price proven from the carrier's billing records). That command never buys,
 * charges or refunds, and is idempotent, so a retry can never duplicate anything. Toll-free incidents stay manual.
 *
 * Concurrency: each incident is claimed with an atomic conditional UPDATE that pushes its next-attempt time out by a
 * lease, so overlapping runs cannot both work on it; a crashed run's lease expires.
 *
 * Disabled unless messaging.order_reconciliation.enabled. `--dry-run` works either way and only reports (it runs each
 * recovery in its own dry-run mode, writes nothing and consumes no attempt).
 */
class ReconcilePendingNumberOrders extends Command
{
    protected $signature = 'messaging:reconcile-pending-orders
        {--dry-run : Report what verification says for each open local incident; writes nothing}
        {--incident= : Only this incident id}
        {--limit=20 : Most incidents to work in one run}';

    protected $description = 'Verify and finish local number orders whose carrier outcome was unknown (bounded retries; never buys, charges or refunds).';

    public function handle(ProvisioningIncidentRecorder $incidents): int
    {
        $dry = (bool) $this->option('dry-run');
        $cfg = (array) config('messaging.order_reconciliation');

        if (! $dry && ! (bool) ($cfg['enabled'] ?? false)) {
            $this->info('Automatic reconciliation is disabled (messaging.order_reconciliation.enabled); nothing to do. Use --dry-run to inspect.');

            return self::SUCCESS;
        }

        $query = DB::table('business_messaging_provisioning_incidents')
            ->where('stage', TelnyxProvisioningAdapter::INCIDENT_NUMBER_ORDER_UNKNOWN)
            ->where('number_type', 'local')
            ->whereNull('resolved_at')
            ->orderBy('id');

        if ($this->option('incident') !== null) {
            $query->where('id', (int) $this->option('incident'));
        } elseif (! $dry) {
            // Automatic runs skip what is already escalated (an operator owns it) and what is not yet due.
            $query->whereNull('escalated_at')->where(fn ($q) => $q->whereNull('reconcile_next_attempt_at')->orWhere('reconcile_next_attempt_at', '<=', now()));
        }

        $rows = $query->limit(max(1, (int) $this->option('limit')))->get();

        if ($rows->isEmpty()) {
            $this->info('No open local number-order incidents are due.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line(sprintf('Incident #%d, Business #%d, %s (attempts so far: %d).', $row->id, $row->business_id, $row->phone_number, $row->reconcile_attempts));

            if ($dry) {
                [$exit, $output] = $this->recover($row, false);
                $this->line($output);
                $this->line($exit === 0 ? '=> Verified: --execute would finish this setup.' : '=> Not verifiable yet (see above); nothing written.');

                continue;
            }

            $this->attempt($incidents, $row, $cfg);
        }

        return self::SUCCESS;
    }

    /** Tells every platform administrator, once per incident. Best effort: an alert failure never disturbs reconciliation. */
    private function alertOperators(object $row, string $outcome, string $detail, int $attempts): void
    {
        try {
            $admins = \App\Models\User::query()->where('is_admin', true)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new \App\Notifications\Messaging\NumberOrderEscalatedNotification(
                    (int) $row->id, (int) $row->business_id, (string) $row->phone_number, $outcome, $detail, $attempts,
                ));
            }
        } catch (\Throwable $e) {
            Log::error('Could not alert operators about an escalated number order', ['incident_id' => $row->id, 'error' => get_class($e)]);
        }
    }

    /**
     * Runs the guarded recovery command in-process (so its own fail-closed checks are the only logic) and returns its
     * exit code and transcript. Reading the command's own record, not a console stream, keeps this independent of how
     * output is rendered.
     *
     * @return array{0: int, 1: string}
     */
    private function recover(object $row, bool $execute): array
    {
        $command = app(RecoverPendingNumber::class);
        $command->setLaravel(app());
        $exit = $command->run(
            new ArrayInput(['--business' => $row->business_id, '--incident' => $row->id, $execute ? '--execute' : '--dry-run' => true]),
            new NullOutput(),
        );

        return [$exit, trim(implode("\n", $command->transcript))];
    }

    /** @param array<string, mixed> $cfg */
    private function attempt(ProvisioningIncidentRecorder $incidents, object $row, array $cfg): void
    {
        $claimed = DB::table('business_messaging_provisioning_incidents')
            ->where('id', $row->id)->whereNull('resolved_at')
            ->where(fn ($q) => $q->whereNull('reconcile_next_attempt_at')->orWhere('reconcile_next_attempt_at', '<=', now()))
            ->update(['reconcile_next_attempt_at' => now()->addMinutes((int) $cfg['lease_minutes'])]);

        if ($claimed !== 1) {
            return; // another run took it, or it was resolved meanwhile
        }

        [$exit, $output] = $this->recover($row, true);

        if ($exit === 0) {
            Log::info('Number order reconciled automatically', ['incident_id' => $row->id, 'business_id' => $row->business_id]);
            $this->info('  Reconciled.');

            return;
        }

        $detail = preg_match('/Aborted, nothing written:\s*(.+)/', $output, $m) === 1 ? trim($m[1]) : 'verification did not complete';
        $outcome = str_contains($detail, '[failed]') ? 'failed' : (str_contains($detail, '[pending]') ? 'pending' : 'blocked');
        $detail = trim(str_replace(['[pending]', '[failed]'], '', $detail));

        $attempts = (int) $row->reconcile_attempts + 1;

        // A carrier "failed" is never acted on from a single sighting. The first one is recorded (the customer is told the
        // payment is being returned) and re-checked shortly; only a second consecutive one may return the charge, and
        // only when every refund safeguard holds. Anything short of that is escalated to an operator.
        $failedTwice = $outcome === 'failed' && $row->reconcile_outcome === 'failed';
        $refundBlocked = null;

        if ($failedTwice) {
            $refund = app(FailedNumberOrderRefunder::class)->refund($row);

            if ($refund['status'] === FailedNumberOrderRefunder::REFUNDED) {
                Log::info('Failed number order refunded automatically', ['incident_id' => $row->id, 'business_id' => $row->business_id]);
                $this->info('  Carrier failed the order; the charge was returned and the incident resolved.');

                return;
            }

            $refundBlocked = $refund['detail'];
            $detail = trim($detail . ' — not refunded automatically: ' . $refundBlocked);
        }

        $ageHours = (int) \Illuminate\Support\Carbon::parse($row->created_at)->diffInHours(now());
        // A failed order escalates only once the safeguarded refund has been tried and could not be applied; every other
        // outcome keeps trying until the
        // attempt cap or the age limit, then hand it to an operator.
        $escalate = ($outcome === 'failed' && $failedTwice) || ($outcome !== 'failed' && ($attempts >= (int) $cfg['max_attempts'] || $ageHours >= (int) $cfg['escalate_after_hours']));
        $backoff = (int) min((int) $cfg['max_backoff_minutes'], (int) $cfg['base_backoff_minutes'] * (2 ** max(0, $attempts - 1)));

        $incidents->recordReconcileAttempt((int) $row->id, $outcome, $detail, $escalate ? null : now()->addMinutes($backoff), $escalate);

        if ($escalate) {
            if ($row->escalated_at === null) {
                $this->alertOperators($row, $outcome, $detail, $attempts); // once, on the transition
            }

            Log::error('Number order reconciliation escalated to an operator', [
                'incident_id' => $row->id, 'business_id' => $row->business_id, 'outcome' => $outcome, 'attempts' => $attempts, 'detail' => $detail,
            ]);
            $this->error(sprintf('  ESCALATED (%s after %d attempt(s)): %s', $outcome, $attempts, $detail));

            return;
        }

        $this->line(sprintf('  Not yet (%s): %s. Next attempt in %d min.', $outcome, $detail, $backoff));
    }
}
