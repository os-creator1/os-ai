<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Opportunity\OpportunityActionExecutionStatus as Execution;
use App\Enums\Opportunity\OpportunityCompletionPolicy as Policy;
use App\Enums\Opportunity\OpportunityStatus as Status;

/**
 * The ONE customer-facing vocabulary for Growth work, derived only from records that already exist (the Opportunity's status and its
 * latest action execution). Nothing is invented: a stage is shown only when those records prove it, and "Verified" means exactly what
 * the system verified — that the approved change is present in the saved record after execution — never a business result.
 *
 * Stages: recommended · awaiting_approval · approved · executing · completed · failed · awaiting_verification · verified.
 */
final class GrowthWorkStage
{
    public const RECOMMENDED = 'recommended';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const APPROVED = 'approved';

    public const EXECUTING = 'executing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const AWAITING_VERIFICATION = 'awaiting_verification';

    public const VERIFIED = 'verified';

    private const LABELS = [
        self::RECOMMENDED => 'Recommended',
        self::AWAITING_APPROVAL => 'Awaiting your approval',
        self::APPROVED => 'Approved',
        self::EXECUTING => 'Executing',
        self::COMPLETED => 'Completed',
        self::FAILED => 'Failed',
        self::AWAITING_VERIFICATION => 'Awaiting verification',
        self::VERIFIED => 'Verified',
    ];

    /** The business-outcome disclaimer every finished stage carries: a technical success is not proof of results. */
    public const OUTCOME_NOTE = 'This does not show any change in enquiries, bookings or revenue; MotionGrove has not measured one.';

    /**
     * @return array{key: string, label: string, note: ?string}|null  null = not part of the work record (snoozed, dismissed)
     */
    public static function for(Status $status, ?Execution $execution, ?Policy $policy = null, ?string $failure = null): ?array
    {
        $key = match (true) {
            $status === Status::Snoozed || $status === Status::Dismissed => null,
            $execution === Execution::Failed && $status !== Status::Completed => self::FAILED,
            $status === Status::AwaitingApproval => self::AWAITING_APPROVAL,
            $status === Status::InProgress && $execution === Execution::Running => self::EXECUTING,
            $status === Status::InProgress && $execution === Execution::Succeeded => self::AWAITING_VERIFICATION,
            $status === Status::InProgress => self::APPROVED,
            $status === Status::Completed && $execution === Execution::Succeeded && $policy === Policy::SystemVerified => self::VERIFIED,
            $status === Status::Completed => self::COMPLETED,
            default => self::RECOMMENDED,
        };

        if ($key === null) {
            return null;
        }

        $note = match ($key) {
            self::AWAITING_APPROVAL => 'Nothing happens until you approve.',
            self::APPROVED => 'Approved and waiting to run.',
            self::EXECUTING => 'Running now.',
            self::AWAITING_VERIFICATION => 'The action ran; it has not been confirmed yet.',
            self::FAILED => ($failure !== null && trim($failure) !== '' ? trim($failure) : 'The action did not complete.') . ' You can try again from the recommendation.',
            self::VERIFIED => 'Confirmed by re-reading your saved details after the change. ' . self::OUTCOME_NOTE,
            self::COMPLETED => 'Marked complete. ' . self::OUTCOME_NOTE,
            default => null,
        };

        return ['key' => $key, 'label' => self::LABELS[$key], 'note' => $note];
    }
}
