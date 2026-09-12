<?php

namespace App\Enums\Dashboard;

/**
 * Customer Experience Slice 4 §5.2 — the closed set of things the dashboard
 * may say need attention. Each case is raised only from a status column that
 * already exists and was mechanically proven; anything else is not an
 * attention item.
 *
 * `BusinessPhoneMissing` is deliberately absent (Correction 1, decision B):
 * Slice 3's messaging identity resolver covers managed identities only, and
 * a null answer there is normal for a Business sending through its own
 * numbers — so there is no authoritative "no usable phone" truth to raise it
 * from, and a guessed alert is a fabricated one.
 */
enum AttentionType: string
{
    case WalletSuspended = 'wallet_suspended';
    case OutstandingDebt = 'outstanding_debt';
    case PaidActivityPaused = 'paid_activity_paused';
    case LowBalance = 'low_balance';
    case AutoRechargeFailing = 'auto_recharge_failing';
    case WebsiteUnpublished = 'website_unpublished';
    case GoogleConnectionLost = 'google_connection_lost';
    case GoogleLocationUnhealthy = 'google_location_unhealthy';
    case AutomationFailing = 'automation_failing';

    /**
     * Blocking: paid activity cannot happen at all. Warning: something is
     * wrong or about to be. Informational: setup not finished.
     */
    public function severity(): AttentionSeverity
    {
        return match ($this) {
            self::WalletSuspended, self::PaidActivityPaused => AttentionSeverity::Blocking,
            self::OutstandingDebt, self::LowBalance, self::AutoRechargeFailing,
            self::GoogleConnectionLost, self::GoogleLocationUnhealthy, self::AutomationFailing => AttentionSeverity::Warning,
            self::WebsiteUnpublished => AttentionSeverity::Informational,
        };
    }

    /**
     * One plain customer sentence. No configuration key, enum value,
     * internal noun or provider name.
     */
    public function sentence(): string
    {
        return match ($this) {
            self::WalletSuspended => 'Paid messaging is suspended for this business until the account is back in good standing.',
            self::OutstandingDebt => 'This business has an outstanding balance to settle.',
            self::PaidActivityPaused => 'Paid activity is paused, so paid messages will not send.',
            self::LowBalance => 'The balance is below the automatic top-up threshold.',
            self::AutoRechargeFailing => 'Automatic top-up has not been able to add funds.',
            self::WebsiteUnpublished => 'The website has not been published yet.',
            self::GoogleConnectionLost => 'The Google connection has stopped working and needs to be reconnected.',
            self::GoogleLocationUnhealthy => 'A Google listing needs attention.',
            self::AutomationFailing => 'Some automation runs failed in the last 30 days.',
        };
    }

    /**
     * Unified Business Home §5.3 (H-1) — what happens to the business if the
     * billing exception is left alone, so the strip says why it is worth a
     * customer's attention rather than only what the state is. Only the
     * billing cases carry one: every other type's sentence already names its
     * own consequence.
     */
    public function consequence(): ?string
    {
        return match ($this) {
            self::WalletSuspended => 'Messages that cost money will not go out until it is resolved.',
            self::OutstandingDebt => 'Paid messaging can stop until it is settled.',
            self::PaidActivityPaused => 'Automated replies and campaigns may stop going out.',
            self::LowBalance => 'Paid messaging can stop once it runs out.',
            self::AutoRechargeFailing => 'The balance will keep falling until the payment method is fixed.',
            default => null,
        };
    }

    /** The label of the one action that fixes it. */
    public function actionLabel(): string
    {
        return match ($this) {
            self::WalletSuspended, self::OutstandingDebt, self::PaidActivityPaused,
            self::LowBalance, self::AutoRechargeFailing => 'Review billing',
            self::WebsiteUnpublished => 'Open website',
            self::GoogleConnectionLost => 'Review Google connection',
            self::GoogleLocationUnhealthy => 'Review Google listings',
            self::AutomationFailing => 'Review automations',
        };
    }
}
