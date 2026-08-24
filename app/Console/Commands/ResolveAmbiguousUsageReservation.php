<?php

namespace App\Console\Commands;

use App\Enums\Usage\UsageReservationStatus;
use App\Library\Usage\UsageWalletManager;
use App\Models\User;
use App\Repositories\Contracts\BusinessUsageReservationRepository;
use Illuminate\Console\Command;

/**
 * RFC-005 Milestone 5 §6.2 — bounded, human-operated resolution for a
 * reservation stuck Pending past a provider-outcome ambiguity window
 * (SendCampaignSMS.php's Twilio/TwilioCopilot classification returned
 * something other than a definitive accepted/rejected outcome). This
 * command is deliberately narrow: it can only commit or release one
 * already-identified, still-Pending, sufficiently-old reservation — it
 * is never a generic reservation-mutation tool, accepts no arbitrary
 * field override, and performs no write beyond what
 * UsageWalletManager::commit()/release() already do.
 *
 * Six safety checks, all required before any write:
 *  1. Actor is a platform administrator.
 *  2. The reservation id exists.
 *  3. The reservation is currently Pending (not already terminal).
 *  4. The reservation is at least this many minutes old (never resolves
 *     a reservation the provider might still legitimately settle).
 *  5. --outcome is exactly 'commit' or 'release' — no default, no third
 *     value, no silent guess.
 *  6. --reason is a non-empty, explicit justification.
 */
class ResolveAmbiguousUsageReservation extends Command
{
    private const int MINIMUM_AGE_MINUTES = 10;

    protected $signature = 'usage:resolve-ambiguous-reservation
        {reservation-id}
        {--outcome=}
        {--actor-user-id=}
        {--reason=}';

    protected $description = 'Manually resolve one still-Pending, provider-outcome-ambiguous usage reservation (RFC-005 Milestone 5 §6.2).';

    public function handle(
        BusinessUsageReservationRepository $reservationRepository,
        UsageWalletManager $walletManager,
    ): int {
        $actorUserId = $this->option('actor-user-id');

        if ($actorUserId === null || ! ctype_digit((string) $actorUserId)) {
            $this->error('--actor-user-id is required and must be a numeric User id.');

            return self::FAILURE;
        }

        $actorUserId = (int) $actorUserId;

        if (! (bool) User::query()->whereKey($actorUserId)->value('is_admin')) {
            $this->error("User {$actorUserId} is not a platform administrator. Aborting.");

            return self::FAILURE;
        }

        $reason = $this->option('reason');

        if (empty($reason)) {
            $this->error('--reason is required and must not be empty.');

            return self::FAILURE;
        }

        $outcome = $this->option('outcome');

        if ($outcome !== 'commit' && $outcome !== 'release') {
            $this->error("--outcome must be exactly 'commit' or 'release'.");

            return self::FAILURE;
        }

        $reservationId = (int) $this->argument('reservation-id');
        $reservation = $reservationRepository->findById($reservationId);

        if ($reservation === null) {
            $this->error("Reservation {$reservationId} does not exist.");

            return self::FAILURE;
        }

        if ($reservation->status !== UsageReservationStatus::Pending) {
            $this->error("Reservation {$reservationId} is not Pending (current status: {$reservation->status->value}). Nothing to resolve.");

            return self::FAILURE;
        }

        if ($reservation->reserved_at->diffInMinutes(now()) < self::MINIMUM_AGE_MINUTES) {
            $this->error(
                "Reservation {$reservationId} is younger than " . self::MINIMUM_AGE_MINUTES
                . " minutes — the provider may still settle it. Refusing to resolve prematurely."
            );

            return self::FAILURE;
        }

        $this->line("Reservation:    {$reservationId}");
        $this->line("Business id:    {$reservation->business_id}");
        $this->line("Reserved at:    {$reservation->reserved_at}");
        $this->line("Outcome:        {$outcome}");
        $this->line("Reason:         {$reason}");

        if (! $this->confirm('Apply this resolution?')) {
            $this->info('Aborted — no change made.');

            return self::SUCCESS;
        }

        if ($outcome === 'commit') {
            $walletManager->commit($reservationId);
        } else {
            $walletManager->release($reservationId);
        }

        $this->info("Reservation {$reservationId} resolved: {$outcome}.");

        return self::SUCCESS;
    }
}
