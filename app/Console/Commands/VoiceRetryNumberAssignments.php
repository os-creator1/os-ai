<?php

namespace App\Console\Commands;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Voice\VoiceNumberAssigner;
use App\Models\BusinessMessagingNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retries failed/unconfirmed Voice Connection assignments for active local numbers, reusing VoiceNumberAssigner.
 *
 * Never buys anything and never repeats a confirmed assignment (voice_assigned_at IS NULL is part of both the
 * selection and the claim). Bounded: only numbers under the attempt cap and past their backoff are touched. Concurrency
 * is prevented by an atomic lease: a number is claimed with a conditional UPDATE that pushes its next-attempt time out
 * by the lease, so two overlapping runs can never both assign it; a crashed run's lease simply expires.
 * A no-op when no Voice Connection is configured.
 */
class VoiceRetryNumberAssignments extends Command
{
    protected $signature = 'voice:retry-number-assignments {--limit=25 : Most numbers to attempt in one run}';

    protected $description = 'Retry failed or unconfirmed Telnyx Voice Connection assignments for local numbers (bounded, backed off).';

    public function handle(VoiceNumberAssigner $assigner): int
    {
        if (trim((string) config('messaging.voice.connection_id')) === '') {
            $this->info('No Voice Connection configured; nothing to do.');

            return self::SUCCESS;
        }

        $due = BusinessMessagingNumber::query()
            ->where('number_type', PhoneNumberType::Local->value)
            ->where('status', BusinessMessagingNumberStatus::Active->value)
            ->whereNotNull('provider_number_reference')
            ->whereNull('voice_assigned_at')
            ->whereNotNull('voice_assignment_failure_reason')
            ->where('voice_assignment_attempts', '<', VoiceNumberAssigner::maxAttempts())
            ->where('voice_assignment_next_attempt_at', '<=', now())
            ->orderBy('voice_assignment_next_attempt_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        $attempted = 0;
        foreach ($due as $id) {
            $claimed = DB::table('business_messaging_numbers')
                ->where('id', $id)
                ->whereNull('voice_assigned_at')
                ->where('voice_assignment_next_attempt_at', '<=', now())
                ->update(['voice_assignment_next_attempt_at' => now()->addMinutes((int) config('messaging.voice.assignment_retry.lease_minutes'))]);

            if ($claimed !== 1) {
                continue; // another run took it, or it was confirmed meanwhile
            }

            $assigner->assign(BusinessMessagingNumber::findOrFail($id));
            $attempted++;
        }

        $this->info(sprintf('Attempted %d of %d due number(s).', $attempted, $due->count()));

        return self::SUCCESS;
    }
}
