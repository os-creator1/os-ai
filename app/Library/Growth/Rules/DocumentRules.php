<?php

declare(strict_types=1);

namespace App\Library\Growth\Rules;

use App\Enums\Business\BusinessGoal;
use App\Enums\Growth\GrowthActionSafetyClass;
use App\Enums\Growth\GrowthCategory;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Growth\GrowthFactSnapshot;
use App\Library\Growth\GrowthMoney;
use App\Library\Growth\GrowthRuleDefinition;

/**
 * The four document / receivable rules, all from the canonical document and
 * schedule-item tables (see GrowthDocumentFactReader). Growth is a reader:
 * it creates no second receivables system and moves no money.
 *
 *   documents.proposal_unsigned:v1   sent, awaiting signature past the window
 *   documents.signed_unpaid:v1       signed, balance outstanding, not yet overdue
 *   payments.overdue_balance:v1      a schedule item past its due date
 *   payments.failed_payment:v1       a payment attempt failed and the item is still unpaid
 */
final class DocumentRules extends AbstractGrowthRule
{
    public const UNSIGNED = 'unsigned';

    public const SIGNED_UNPAID = 'signed_unpaid';

    public const OVERDUE = 'overdue';

    public const FAILED = 'failed_payment';

    public function __construct(private readonly string $kind = self::UNSIGNED)
    {
    }

    public function definition(): GrowthRuleDefinition
    {
        return match ($this->kind) {
            self::SIGNED_UNPAID => new GrowthRuleDefinition(
                key: 'documents.signed_unpaid:v1',
                worker: OpportunityWorkerKey::Sales,
                category: GrowthCategory::Payments,
                sourceModule: 'payments',
                domain: 'documents',
                scope: 'location',
                title: 'Signed work has an unpaid balance',
                summary: 'These documents were signed a few days ago and still have a payment outstanding.',
                factKey: 'signed_unpaid_documents',
                evidenceSummary: 'Signed documents with an unpaid schedule item that is not yet overdue.',
                actionKey: 'growth_collect_signed_payments',
                actionLabel: 'View documents',
                target: 'documents',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 2,
                minSample: 3,
                why: 'The customer has agreed to the work. A gentle reminder usually settles an open balance.',
                expected: 'Following up gives the customer a chance to pay. Nothing is charged for you.',
                goalKeys: [BusinessGoal::SalesFollowup->value],
            ),
            self::OVERDUE => new GrowthRuleDefinition(
                key: 'payments.overdue_balance:v1',
                worker: OpportunityWorkerKey::Sales,
                category: GrowthCategory::Payments,
                sourceModule: 'payments',
                domain: 'documents',
                scope: 'location',
                title: 'A payment is past its due date',
                summary: 'These documents have a payment that was due and has not been paid.',
                factKey: 'overdue_balances',
                evidenceSummary: 'Unpaid schedule items past their due date.',
                actionKey: 'growth_collect_overdue_balances',
                actionLabel: 'View overdue',
                target: 'documents',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 3,
                minSample: 3,
                why: 'The longer a balance stays open, the harder it is to collect.',
                expected: 'Following up gives the customer a chance to pay. Nothing is charged for you.',
                goalKeys: [BusinessGoal::SalesFollowup->value],
            ),
            self::FAILED => new GrowthRuleDefinition(
                key: 'payments.failed_payment:v1',
                worker: OpportunityWorkerKey::Sales,
                category: GrowthCategory::Payments,
                sourceModule: 'payments',
                domain: 'documents',
                scope: 'location',
                title: 'A customer payment did not go through',
                summary: 'A recent payment attempt failed and the balance is still unpaid.',
                factKey: 'failed_payments',
                evidenceSummary: 'Failed payment attempts on schedule items that are still unpaid.',
                actionKey: 'growth_resolve_failed_payments',
                actionLabel: 'View documents',
                target: 'documents',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 3,
                minSample: 3,
                why: 'A declined card is usually easy to fix, but only once the customer knows.',
                expected: 'Letting the customer know gives them a chance to try again. Nothing is charged for you.',
                goalKeys: [BusinessGoal::SalesFollowup->value],
            ),
            default => new GrowthRuleDefinition(
                key: 'documents.proposal_unsigned:v1',
                worker: OpportunityWorkerKey::Sales,
                category: GrowthCategory::ProposalsSales,
                sourceModule: 'documents',
                domain: 'documents',
                scope: 'location',
                title: 'Sent proposals are still unsigned',
                summary: 'These documents were sent for signature and have not been signed yet.',
                factKey: 'unsigned_documents',
                evidenceSummary: 'Documents sent for signature and still unsigned past the follow-up window.',
                actionKey: 'growth_follow_up_proposals',
                actionLabel: 'View proposals',
                target: 'documents',
                safetyClass: GrowthActionSafetyClass::ReadOnly,
                weight: 3,
                minSample: 3,
                why: 'A proposal that has gone quiet is a sale that can still be won with a quick follow-up.',
                expected: 'Following up gives the customer a chance to sign or tell you what is holding them back.',
                goalKeys: [BusinessGoal::SalesFollowup->value],
            ),
        };
    }

    protected function detect(GrowthFactSnapshot $facts): array
    {
        $thresholds = $facts->thresholds;
        $out = [];

        foreach ($facts->set('documents')->get('by_location', []) as $locationKey => $buckets) {
            $bucket = $buckets[$this->kind];

            if ($bucket['count'] === 0) {
                continue;
            }

            $evidence = $this->bucketEvidence($bucket, match ($this->kind) {
                self::UNSIGNED => ['threshold_days' => $thresholds->get('proposal_unsigned_days')],
                self::SIGNED_UNPAID => ['threshold_days' => $thresholds->get('signed_unpaid_days')],
                self::FAILED => ['window_days' => $thresholds->get('failed_payment_lookback_days')],
                default => [],
            });

            $out[(int) $locationKey] = [
                'impact' => match ($this->kind) {
                    self::OVERDUE, self::FAILED => 5,
                    default => $this->impactForValue(4, $evidence['value_minor'], $facts),
                },
                'urgency' => match ($this->kind) {
                    self::FAILED => 5,
                    self::OVERDUE => 4,
                    default => 3,
                },
                'effort' => 1,
                'confidence' => 1.0,
                'evidence' => $evidence,
            ];
        }

        return $out;
    }

    protected function population(GrowthFactSnapshot $facts): int
    {
        return (int) $facts->set('documents')->get('sent_total', 0);
    }

    protected function positiveFacts(GrowthFactSnapshot $facts): ?array
    {
        return ['sent' => $this->population($facts)];
    }

    public function headline(array $evidence): string
    {
        $count = (int) ($evidence['count'] ?? 0);
        $value = GrowthMoney::format($evidence['value_minor'] ?? null, $evidence['currency'] ?? null);
        $suffix = $value !== null ? ' — ' . $value . '.' : '.';

        return match ($this->kind) {
            self::SIGNED_UNPAID => GrowthMoney::plural($count, 'signed document has', 'signed documents have') . ' an unpaid balance' . $suffix,
            self::OVERDUE => GrowthMoney::plural($count, 'document has', 'documents have') . ' a payment past its due date' . $suffix,
            self::FAILED => GrowthMoney::plural($count, 'payment', 'payments') . ' failed and still ' . ($count === 1 ? 'needs' : 'need') . ' to be collected' . $suffix,
            default => GrowthMoney::plural($count, 'proposal has', 'proposals have') . ' been unsigned for ' . (int) ($evidence['threshold_days'] ?? 3) . '+ days' . $suffix,
        };
    }

    public function positiveStatement(array $positive): ?string
    {
        if ($positive['sent'] < 3) {
            return null;
        }

        return match ($this->kind) {
            self::OVERDUE => 'No payment is past its due date.',
            self::FAILED => 'No recent payment attempt has failed.',
            self::UNSIGNED => 'No sent proposal has been waiting unsigned for long.',
            default => null,
        };
    }
}
