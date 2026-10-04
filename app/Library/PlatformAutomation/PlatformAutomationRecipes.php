<?php

namespace App\Library\PlatformAutomation;

/**
 * Default recipes. A recipe is NOT hidden logic: "Use this recipe" copies it into an
 * ordinary, disabled, fully editable automation. Definition format:
 *   ['params' => trigger params, 'conditions' => [[fact, op, value]], 'steps' => [[key, action, params]]]
 */
final class PlatformAutomationRecipes
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'trial_ending_reminder' => [
                'name' => 'Trial ending reminder',
                'description' => 'Three days before a trial ends, tell the Workspace owner and leave a follow-up for the team.',
                'trigger_type' => 'subscription.trial_ending',
                'available' => true,
                'definition' => [
                    'params' => ['days_before' => 3],
                    'conditions' => [],
                    'steps' => [
                        ['key' => 's1', 'action' => 'send_in_app_notification', 'params' => [
                            'recipient' => 'workspace_owner', 'title' => 'Your trial ends soon',
                            'message' => 'Hi {{user.first_name}}, your {{workspace.name}} trial ends in {{trial.days_left}} days. Add a payment method to keep everything running.']],
                        ['key' => 's2', 'action' => 'send_email', 'params' => [
                            'recipient' => 'workspace_owner', 'subject' => 'Your {{platform.name}} trial ends in {{trial.days_left}} days',
                            'body' => "Hi {{user.first_name}},\n\nYour {{workspace.name}} trial ends on {{trial.ends_at}}. Add a payment method before then so nothing is interrupted.", 'purpose' => 'transactional']],
                        ['key' => 's3', 'action' => 'create_internal_task', 'params' => ['body' => 'Trial ending for {{workspace.name}}: check in if they have not added a payment method.']],
                    ],
                ],
            ],
            'failed_payment_notification' => [
                'name' => 'Failed payment notification',
                'description' => 'When a payment fails and the grace period starts, email the owner and alert the platform team.',
                'trigger_type' => 'subscription.payment_failed',
                'available' => true,
                'definition' => [
                    'params' => [],
                    'conditions' => [],
                    'steps' => [
                        ['key' => 's1', 'action' => 'send_email', 'params' => [
                            'recipient' => 'workspace_owner', 'subject' => 'We could not process your payment',
                            'body' => "Hi {{user.first_name}},\n\nThe latest payment for {{workspace.name}} did not go through. Please update your payment method to avoid an interruption.", 'purpose' => 'transactional']],
                        ['key' => 's2', 'action' => 'send_in_app_notification', 'params' => [
                            'recipient' => 'platform_admins', 'title' => 'Payment failed', 'message' => '{{workspace.name}} entered its grace period.']],
                    ],
                ],
            ],
            'incomplete_onboarding_reminder' => [
                'name' => 'Incomplete onboarding reminder',
                'description' => 'A day after a Business is created without finishing onboarding, nudge the owner and open a follow-up.',
                'trigger_type' => 'business.onboarding_incomplete',
                'available' => true,
                'definition' => [
                    'params' => ['hours' => 24],
                    'conditions' => [],
                    'steps' => [
                        ['key' => 's1', 'action' => 'send_email', 'params' => [
                            'recipient' => 'business_owner', 'subject' => 'Finish setting up {{business.name}}',
                            'body' => "Hi {{user.first_name}},\n\nYou are a few steps away from a fully set up {{business.name}}. Pick up where you left off any time.", 'purpose' => 'product_notice']],
                        ['key' => 's2', 'action' => 'create_internal_task', 'params' => ['body' => '{{business.name}} has not finished onboarding after a day.']],
                    ],
                ],
            ],
            'provider_disconnected_alert' => [
                'name' => 'Provider disconnected alert',
                'description' => 'When a provider connection is revoked, tell the Business owner it needs reconnecting.',
                'trigger_type' => 'provider.reconnection_required',
                'available' => true,
                'definition' => [
                    'params' => [],
                    'conditions' => [],
                    'steps' => [
                        ['key' => 's1', 'action' => 'send_in_app_notification', 'params' => [
                            'recipient' => 'business_owner', 'title' => 'Reconnect Google Business Profile',
                            'message' => 'The connection for {{business.name}} was revoked. Reconnect it to keep your listing in sync.']],
                        ['key' => 's2', 'action' => 'send_email', 'params' => [
                            'recipient' => 'business_owner', 'subject' => 'Reconnect your Google Business Profile',
                            'body' => "Hi {{user.first_name}},\n\nThe Google Business Profile connection for {{business.name}} was revoked, so we can no longer keep it in sync. Reconnect it from the Get found page.", 'purpose' => 'product_notice']],
                    ],
                ],
            ],
            'usage_warning' => [
                'name' => 'Usage warning',
                'description' => 'When a wallet funding attempt or auto-recharge fails, warn the Business owner and the platform team.',
                'trigger_type' => 'usage.funding_failed',
                'available' => true,
                'definition' => [
                    'params' => [],
                    'conditions' => [],
                    'steps' => [
                        ['key' => 's1', 'action' => 'send_in_app_notification', 'params' => [
                            'recipient' => 'business_owner', 'title' => 'Wallet funding failed',
                            'message' => 'We could not fund the wallet for {{business.name}}. Messaging may pause until it is topped up.']],
                        ['key' => 's2', 'action' => 'send_in_app_notification', 'params' => [
                            'recipient' => 'platform_admins', 'title' => 'Funding failed', 'message' => 'A funding attempt failed for {{business.name}}.']],
                    ],
                ],
            ],
            'wallet_low' => [
                'name' => 'Wallet low',
                'description' => 'When a Business wallet is suspended, email its owner. (The wallet raises no separate low-balance event; suspension is the signal.)',
                'trigger_type' => 'usage.wallet_status_changed',
                'available' => true,
                'definition' => [
                    'params' => [],
                    'conditions' => [['fact' => 'to_status', 'op' => 'eq', 'value' => 'suspended']],
                    'steps' => [
                        ['key' => 's1', 'action' => 'send_email', 'params' => [
                            'recipient' => 'business_owner', 'subject' => 'Your wallet needs funds',
                            'body' => "Hi {{user.first_name}},\n\nThe wallet for {{business.name}} is suspended, so paid actions such as texting are paused. Add funds to resume.", 'purpose' => 'transactional']],
                    ],
                ],
            ],
            'invitation_reminder' => [
                'name' => 'Invitation reminder',
                'description' => 'Remind invited people who have not accepted.',
                'trigger_type' => 'user.invited',
                'available' => false,
                'unavailable_reason' => 'There is no invitation ledger to detect an unaccepted invitation from, so no trigger exists for it yet.',
                'definition' => ['params' => [], 'conditions' => [], 'steps' => []],
            ],
        ];
    }
}
