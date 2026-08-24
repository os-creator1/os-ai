<?php

namespace App\Console\Commands;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Usage\UsageReservationStatus;
use App\Library\Usage\UsageWalletManager;
use App\Models\User;
use App\Repositories\Contracts\BusinessUsageReservationRepository;
use Illuminate\Console\Command;

/**
 * RFC-005 Milestone 5 §6.2 — bounded, human-operated resolution for a
 * reservation stuck Pending past a provider-outcome ambiguity window
 * (SendCampaignSMS.php's Twilio/TwilioCopilot classification returned
 * 'ambiguous_exception', or no marker at all). This command is
 * deliberately narrow: it can only commit or release one already-
 * identified, still-Pending Conversations-pilot reservation belonging to
 * the currently-configured pilot Business — it is never a generic
 * reservation-mutation tool, accepts no arbitrary field override, and
 * performs no write beyond what UsageWalletManager::commit()/release()
 * already do.
 *
 * Six safety checks, in this exact locked order, zero mutation on any
 * failure:
 *  1. The reservation exists (findById() returns non-null).
 *  2. Its status is exactly Pending (never Committed/Released/Expired).
 *  3. Its feature_key is exactly PlatformFeature::Conversations->value.
 *  4. The supplied --actor-user-id resolves to a platform administrator.
 *  5. --reason is supplied and non-empty.
 *  6. The reservation's own persisted business_id equals the currently-
 *     configured conversations_metering.pilot_business_id.
 */
class ResolveAmbiguousUsageReservation extends Command
{
    protected $signature = 'usage:resolve-reservation
        {reservation-id}
        {--outcome=}
        {--actor-user-id=}
        {--reason=}';

    protected $description = 'Manually resolve one still-Pending, provider-outcome-ambiguous Conversations-pilot usage reservation (RFC-005 Milestone 5 §6.2).';

    public function handle(
        BusinessUsageReservationRepository $reservationRepository,
        UsageWalletManager $walletManager,
    ): int {
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

        if ($reservation->feature_key !== PlatformFeature::Conversations->value) {
            $this->error("Reservation {$reservationId} is not a Conversations-pilot reservation (feature_key: {$reservation->feature_key}). Refusing to act as a generic reservation-mutation tool.");

            return self::FAILURE;
        }

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

        $pilotBusinessId = config('usage_billing.conversations_metering.pilot_business_id');

        if ($pilotBusinessId === null || (int) $reservation->business_id !== (int) $pilotBusinessId) {
            $this->error("Reservation {$reservationId} does not belong to the currently-configured pilot Business. Refusing to resolve.");

            return self::FAILURE;
        }

        $outcome = $this->option('outcome');

        if ($outcome !== 'sent' && $outcome !== 'not-sent') {
            $this->error("--outcome must be exactly 'sent' or 'not-sent'.");

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

        if ($outcome === 'sent') {
            $walletManager->commit($reservationId);
        } else {
            $walletManager->release($reservationId);
        }

        $this->info("Reservation {$reservationId} resolved: {$outcome}.");

        return self::SUCCESS;
    }
}
