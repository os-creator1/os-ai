<?php

namespace Tests\Unit\Usage;

use App\Enums\Usage\RoundingRule;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Enums\Usage\UsageReservationStatus;
use App\Enums\Usage\WalletBillingStatus;
use Tests\TestCase;

class UsageEnumsTest extends TestCase
{
    public function test_wallet_billing_status_values_round_trip(): void
    {
        $this->assertSame('active', WalletBillingStatus::Active->value);
        $this->assertSame('suspended', WalletBillingStatus::Suspended->value);
        $this->assertSame(WalletBillingStatus::Active, WalletBillingStatus::from('active'));
    }

    public function test_rounding_rule_has_exactly_one_v1_value(): void
    {
        $this->assertCount(1, RoundingRule::cases());
        $this->assertSame('round_half_up', RoundingRule::RoundHalfUp->value);
    }

    public function test_usage_reservation_status_values_round_trip(): void
    {
        $this->assertCount(4, UsageReservationStatus::cases());
        $this->assertSame('pending', UsageReservationStatus::Pending->value);
        $this->assertSame('committed', UsageReservationStatus::Committed->value);
        $this->assertSame('released', UsageReservationStatus::Released->value);
        $this->assertSame('expired', UsageReservationStatus::Expired->value);
    }

    public function test_usage_ledger_entry_type_has_exactly_twelve_cases(): void
    {
        $this->assertCount(12, UsageLedgerEntryType::cases());

        $expected = [
            'reservation', 'reservation_release', 'usage_charge', 'usage_overage_charge',
            'paid_top_up', 'auto_recharge', 'manual_credit', 'promotional_credit',
            'refund', 'dispute_chargeback', 'usage_charge_reversal', 'correction_reversal',
        ];

        $actual = array_map(fn (UsageLedgerEntryType $case) => $case->value, UsageLedgerEntryType::cases());

        $this->assertEqualsCanonicalizing($expected, $actual);

        foreach ($expected as $value) {
            $this->assertSame($value, UsageLedgerEntryType::from($value)->value);
        }
    }
}
