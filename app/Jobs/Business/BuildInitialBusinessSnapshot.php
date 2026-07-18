<?php

namespace App\Jobs\Business;

use App\Enums\Business\OnboardingStatus;
use App\Events\Business\InitialBusinessAnalysisCompleted;
use App\Events\Business\InitialBusinessAnalysisFailed;
use App\Library\Business\InitialBusinessSnapshotBuilder;
use App\Models\CustomerOnboarding;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds and persists the deterministic initial business snapshot (RFC-001 §13).
 * Receives only scalar IDs — never serialized model state — and reloads everything
 * fresh so a stale dispatch-time snapshot can never be trusted.
 */
class BuildInitialBusinessSnapshot implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /**
     * The only string ever persisted to analysis_error or sent to a customer —
     * never exception messages, SQL, paths, or IDs.
     */
    private const SAFE_ERROR = 'We could not finish the analysis. Please retry.';

    public function __construct(
        public readonly int $onboardingId,
        public readonly int $expectedVersion,
    ) {
        // config/business.php ships in Milestone 6; 'default' matches this app's
        // own config/queue.php baseline queue name until then.
        $this->onQueue(config('business.onboarding.analysis_queue', 'default'));
    }

    public function handle(
        InitialBusinessSnapshotBuilder $builder,
        CustomerOnboardingRepository $onboardingRepository,
        BusinessRepository $businessRepository,
    ): void {
        $onboarding = CustomerOnboarding::find($this->onboardingId);

        if ($onboarding === null || $onboarding->analysis_version !== $this->expectedVersion) {
            return;
        }

        if (in_array($onboarding->status, [OnboardingStatus::Completed, OnboardingStatus::Dismissed], true)) {
            return;
        }

        if ($onboarding->business_id === null) {
            // A missing business is not a transient condition — leaving the
            // onboarding stuck in AnalysisPending forever is worse than
            // surfacing a safe, retryable failure to the customer.
            Log::error('BuildInitialBusinessSnapshot found no business attached to the onboarding', [
                'onboarding_id' => $this->onboardingId,
                'analysis_version' => $this->expectedVersion,
            ]);
            $this->markFailed($onboarding, $onboardingRepository);

            return;
        }

        $business = $businessRepository->findOwnedByCustomer($onboarding->business_id, $onboarding->customer_id);

        if ($business === null) {
            Log::error('BuildInitialBusinessSnapshot found a business that does not belong to the onboarding customer', [
                'onboarding_id' => $this->onboardingId,
                'analysis_version' => $this->expectedVersion,
            ]);
            $this->markFailed($onboarding, $onboardingRepository);

            return;
        }

        $snapshot = $builder->build($business, $onboarding->primary_goals ?? []);

        // completeAnalysis() re-checks the version itself (lockForUpdate) and no-ops
        // if a newer request superseded this one — only dispatch when it truly applied.
        $updated = $onboardingRepository->completeAnalysis($onboarding, $this->expectedVersion, $snapshot);

        if ($updated->analysis_version === $this->expectedVersion) {
            InitialBusinessAnalysisCompleted::dispatch($this->onboardingId, $this->expectedVersion);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('BuildInitialBusinessSnapshot failed', [
            'onboarding_id' => $this->onboardingId,
            'analysis_version' => $this->expectedVersion,
            'exception' => $exception,
        ]);

        $onboarding = CustomerOnboarding::find($this->onboardingId);

        if ($onboarding === null) {
            return;
        }

        $this->markFailed($onboarding, app(CustomerOnboardingRepository::class));
    }

    /**
     * The single narrow path that persists a safe failure and dispatches the
     * failure event — used by every terminal-failure branch above (missing
     * business, cross-tenant business, and the retries-exhausted failed()
     * hook) so the safe-error string, the version guard, and the after-commit
     * dispatch condition are only ever written once. Never touches Business
     * data. failAnalysis() itself re-checks the version (lockForUpdate) and
     * no-ops if a newer analysis request has already superseded this one, so
     * a stale failure can never overwrite a newer version's state.
     */
    private function markFailed(CustomerOnboarding $onboarding, CustomerOnboardingRepository $onboardingRepository): void
    {
        $updated = $onboardingRepository->failAnalysis($onboarding, $this->expectedVersion, self::SAFE_ERROR);

        if ($updated->analysis_version === $this->expectedVersion) {
            InitialBusinessAnalysisFailed::dispatch($this->onboardingId, $this->expectedVersion, self::SAFE_ERROR);
        }
    }
}
