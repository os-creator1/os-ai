<?php

namespace App\Library\PlatformAutomation;

use App\Enums\PlatformAutomation\PlatformSafetyClass;
use App\Enums\PlatformAutomation\PlatformTargetType;

/**
 * The single source of truth for what Platform Automations can listen to and do.
 *
 * A trigger is `available` only where a canonical event or state actually exists
 * (see PlatformTriggerSubscriber and PlatformScheduledTriggers). The rest are listed
 * as unavailable WITH the reason, so the editor never offers a trigger that cannot
 * fire — and never fakes one.
 *
 * Param schema: key => [label, type (text|textarea|number|select), required, options?, default?, help?].
 */
final class PlatformAutomationCatalog
{
    public const MERGE_TOKENS = [
        'user.first_name', 'user.email', 'workspace.name', 'business.name', 'plan.name',
        'trial.ends_at', 'trial.days_left', 'platform.name',
    ];

    /** Facts a condition may test, with their allowed operators. */
    public const FACTS = [
        'plan_tier' => ['label' => 'Plan tier', 'type' => 'select', 'options' => ['core', 'growth', 'agency']],
        'subscription_status' => ['label' => 'Subscription status', 'type' => 'text'],
        'workspace_active' => ['label' => 'Workspace is active', 'type' => 'select', 'options' => ['yes', 'no']],
        'to_status' => ['label' => 'New status (wallet / plan events)', 'type' => 'text'],
    ];

    public const OPERATORS = ['eq' => 'is', 'neq' => 'is not', 'in' => 'is one of'];

    /** @return array<string, array<string, mixed>> */
    public static function triggers(): array
    {
        $u = PlatformTargetType::User->value;
        $w = PlatformTargetType::Workspace->value;
        $b = PlatformTargetType::Business->value;
        $p = PlatformTargetType::Platform->value;

        return [
            // USER / IDENTITY
            'user.registered' => self::t('User registered', 'User / identity', $u),
            'user.email_verified' => self::t('Email verified', 'User / identity', $u),
            'user.email_verification_pending' => self::t('Email verification still pending', 'User / identity', $u, [
                'hours' => ['Hours after registration', 'number', true, null, 24],
            ], 'Checked every 15 minutes; fires once per user.'),
            'user.password_reset_completed' => self::t('Password reset completed', 'User / identity', $u),
            // WORKSPACE / BUSINESS
            'workspace.created' => self::t('Workspace created', 'Workspace / Business', $w),
            'business.created' => self::t('Business created', 'Workspace / Business', $b),
            'workspace.suspended' => self::t('Workspace deactivated', 'Workspace / Business', $w),
            'workspace.reactivated' => self::t('Workspace reactivated', 'Workspace / Business', $w),
            'business.onboarding_incomplete' => self::t('Business onboarding incomplete', 'Workspace / Business', $b, [
                'hours' => ['Hours after the Business was created', 'number', true, null, 24],
            ], 'Checked every 15 minutes; fires once per Business.'),
            'business.onboarding_completed' => self::t('Business onboarding completed', 'Workspace / Business', $b),
            // SUBSCRIPTION / BILLING
            'subscription.activated' => self::t('Plan assigned / subscription activated', 'Subscription / billing', $w),
            'subscription.plan_upgraded' => self::t('Plan upgraded', 'Subscription / billing', $w),
            'subscription.plan_downgraded' => self::t('Plan downgraded', 'Subscription / billing', $w),
            'subscription.trial_ending' => self::t('Trial ending in N days', 'Subscription / billing', $w, [
                'days_before' => ['Days before the trial ends', 'number', true, null, 3],
            ], 'Checked every 15 minutes; fires once per trial and N.'),
            'subscription.trial_ended' => self::t('Trial ended', 'Subscription / billing', $w, [], 'Fires once per trial after its end date.'),
            'subscription.payment_failed' => self::t('Payment failed (grace period entered)', 'Subscription / billing', $w),
            'subscription.payment_recovered' => self::t('Payment recovered (access restored)', 'Subscription / billing', $w),
            'subscription.locked' => self::t('Account locked for non-payment', 'Subscription / billing', $w),
            'usage.funding_failed' => self::t('Wallet funding / auto-recharge failed', 'Subscription / billing', $b),
            'usage.wallet_status_changed' => self::t('Wallet billing status changed', 'Subscription / billing', $b, [], 'Use the condition "New status" to narrow it (for example a low or blocked wallet).'),
            // PRODUCT
            'product.website_published' => self::t('Website published', 'Product', $b),
            'product.provider_connected' => self::t('Google Business Profile connected', 'Product', $b),
            'product.provider_disconnected' => self::t('Google Business Profile disconnected', 'Product', $b),
            // PLATFORM OPERATIONS
            'provider.reconnection_required' => self::t('A provider connection was revoked and needs reconnecting', 'Platform operations', $b),
            'ops.repeated_job_failures' => self::t('Repeated queue / job failures', 'Platform operations', $p, [
                'threshold' => ['Failed jobs', 'number', true, null, 10],
                'window_minutes' => ['Within minutes', 'number', true, null, 60],
            ], 'Checked every 15 minutes against the failed-jobs table.'),
        ];
    }

    /**
     * Triggers the product calls for that have no canonical event or state to hang
     * on today. Shown disabled in the editor with the reason; never fired.
     *
     * @return array<string, string> label => reason
     */
    public static function unavailableTriggers(): array
    {
        return [
            'Password reset requested' => 'No domain event exists for a reset link being requested.',
            'User invited / invitation expired' => 'There is no invitation ledger to read an invite or its expiry from.',
            'User suspended / reactivated / role changed' => 'No canonical user-level suspension event exists; Workspace deactivation is available instead.',
            'Subscription cancelled' => 'No cancellation event is raised outside the provider webhook yet.',
            'Usage warning / hard cap reached' => 'Usage caps are enforced at spend time; no threshold event is raised.',
            'First lead / booking / proposal / payment' => 'There is no first-occurrence ledger to detect "first".',
            'Unusual usage / account requires review' => 'No anomaly signal exists to trigger from.',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function actions(): array
    {
        $recipients = ['target_user' => 'The user the run is about', 'workspace_owner' => 'Workspace owner', 'business_owner' => 'Business owner', 'platform_admins' => 'Platform Owners / admins'];

        return [
            'wait' => self::a('Wait', 'Timing', PlatformSafetyClass::ReadOnly, [
                'amount' => ['Amount', 'number', true, null, 1],
                'unit' => ['Unit', 'select', true, ['minutes' => 'minutes', 'hours' => 'hours', 'days' => 'days'], 'hours'],
            ]),
            'send_in_app_notification' => self::a('Send in-app notification', 'Communication', PlatformSafetyClass::Notification, [
                'recipient' => ['To', 'select', true, $recipients, 'workspace_owner'],
                'title' => ['Title', 'text', true],
                'message' => ['Message', 'textarea', true],
            ]),
            'send_email' => self::a('Send platform email', 'Communication', PlatformSafetyClass::ExternalMessage, [
                'recipient' => ['To', 'select', true, $recipients, 'workspace_owner'],
                'subject' => ['Subject', 'text', true],
                'body' => ['Body', 'textarea', true],
                'purpose' => ['Purpose', 'select', true, ['transactional' => 'Transactional (account, billing, security)', 'product_notice' => 'Product notice (required service information)'], 'transactional',
                    'Marketing is not available here: it needs a consent basis this engine does not hold.'],
            ]),
            'send_announcement' => self::a('Send announcement to this account', 'Communication', PlatformSafetyClass::ExternalMessage, [
                'title' => ['Title', 'text', true],
                'body' => ['Body', 'textarea', true],
                'severity' => ['Severity', 'select', true, ['info' => 'Info', 'success' => 'Success', 'warning' => 'Warning', 'critical' => 'Critical'], 'info'],
                'channels' => ['Channels', 'select', true, ['banner' => 'Banner', 'banner,notification' => 'Banner + notification', 'banner,notification,email' => 'Banner + notification + email'], 'banner,notification'],
            ]),
            'resend_email_verification' => self::a('Resend email verification', 'Account', PlatformSafetyClass::ExternalMessage, []),
            'send_password_reset_link' => self::a('Send password-reset link', 'Account', PlatformSafetyClass::ExternalMessage, [], 'Sends the standard reset LINK. A password is never read, shown or set.'),
            'webhook' => self::a('Call a webhook', 'Internal operations', PlatformSafetyClass::ExternalMessage, [
                'url' => ['HTTPS URL', 'text', true],
            ], 'POSTs the run facts as JSON, signed with the platform webhook secret. HTTPS only.'),
            'create_internal_task' => self::a('Create internal follow-up task', 'Internal operations', PlatformSafetyClass::Notification, [
                'body' => ['Task', 'textarea', true],
            ]),
            'add_internal_note' => self::a('Add internal note', 'Internal operations', PlatformSafetyClass::Notification, [
                'body' => ['Note', 'textarea', true],
            ]),
            'flag_manual_review' => self::a('Flag account for manual review', 'Internal operations', PlatformSafetyClass::Notification, [
                'body' => ['Why', 'textarea', true],
            ]),
            'suspend_business' => self::a('Deactivate the Business', 'Account state', PlatformSafetyClass::AccountState, [
                'reason' => ['Reason (recorded in the audit trail)', 'text', true],
            ]),
            'reactivate_business' => self::a('Reactivate the Business', 'Account state', PlatformSafetyClass::AccountState, [
                'reason' => ['Reason (recorded in the audit trail)', 'text', true],
            ]),
            'restore_workspace_access' => self::a('Restore Workspace access', 'Account state', PlatformSafetyClass::AccountState, [
                'reason' => ['Reason (recorded in the audit trail)', 'text', true],
            ]),
            'change_plan' => self::a('Change the Workspace plan', 'Billing', PlatformSafetyClass::Billing, [
                'tier' => ['New tier', 'select', true, ['core' => 'Core', 'growth' => 'Growth', 'agency' => 'Agency'], 'core'],
                'reason' => ['Reason (recorded in the audit trail)', 'text', true],
            ], 'Runs only after a Platform Owner approves, through the canonical plan service.'),
        ];
    }

    /** @return array<string, string> label => reason */
    public static function unavailableActions(): array
    {
        return [
            'Enable / disable a feature for an account' => 'ENTITLEMENT actions need a per-account override flow with its own approval record; not wired in V1.',
            'Revoke sessions / force password reset on next login' => 'Sessions are file-backed and there is no force-reset flag on users.',
        ];
    }

    /** Recipes: editable starting points, copied into ordinary automations. */
    public static function recipes(): array
    {
        return PlatformAutomationRecipes::all();
    }

    private static function t(string $label, string $group, string $target, array $params = [], string $help = ''): array
    {
        return ['label' => $label, 'group' => $group, 'target' => $target, 'params' => $params, 'help' => $help, 'available' => true];
    }

    private static function a(string $label, string $group, PlatformSafetyClass $class, array $params, string $help = ''): array
    {
        return ['label' => $label, 'group' => $group, 'safety' => $class->value, 'params' => $params, 'help' => $help];
    }
}
