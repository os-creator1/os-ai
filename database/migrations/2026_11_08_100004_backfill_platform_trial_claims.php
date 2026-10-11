<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A free trial happens once — including for customers who had theirs BEFORE the
 * trial ledger existed.
 *
 * Every platform subscription that carries a trial (a snapshot of trial days or
 * a recorded trial end) and that the provider ever confirmed is entered as a
 * GRANTED claim against its Workspace, mailbox and provider customer. Without
 * this, the first customer to cancel and come back after this release would be
 * offered a second trial simply because the books did not go back far enough.
 *
 * Idempotent (`insertOrIgnore` against the unique keys), and read-only with
 * respect to subscriptions: no subscription, price or trial is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_subscriptions')
            ->where(function ($q): void {
                $q->whereNotNull('trial_days_snapshot')->orWhereNotNull('trial_ends_at');
            })
            ->whereNotIn('status', ['pending', 'incomplete', 'incomplete_expired'])
            ->orderBy('id')
            ->chunkById(200, function ($subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    $email = DB::table('workspaces')
                        ->join('users', 'users.id', '=', 'workspaces.owner_user_id')
                        ->where('workspaces.id', $subscription->workspace_id)
                        ->value('users.email');

                    $row = [
                        'workspace_id' => $subscription->workspace_id,
                        'email_key' => $email === null ? null : self::mailboxKey((string) $email),
                        'provider_customer_id' => $subscription->provider_customer_id,
                        'state' => 'granted',
                        'source' => 'public',
                        'granted_at' => $subscription->trial_ends_at ?? $subscription->created_at ?? now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // Workspace, mailbox and provider customer are each unique on
                    // their own. When another history row already holds the mailbox
                    // (or customer), this Workspace is STILL recorded as having had
                    // its trial — only the colliding identity is dropped from it.
                    foreach ([[], ['email_key'], ['email_key', 'provider_customer_id']] as $drop) {
                        DB::table('platform_trial_claims')->insertOrIgnore(array_merge($row, array_fill_keys($drop, null)));

                        if (DB::table('platform_trial_claims')->where('workspace_id', $subscription->workspace_id)->exists()) {
                            break;
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        // Claims are history; there is nothing safe to "undo".
    }

    /** The same mailbox key the TrialLedger uses (kept inline: migrations must not depend on app classes). */
    private static function mailboxKey(string $email): string
    {
        $normalized = mb_strtolower(trim($email));

        if (! str_contains($normalized, '@')) {
            return $normalized;
        }

        [$local, $domain] = explode('@', $normalized, 2);

        if ($domain === 'googlemail.com') {
            $domain = 'gmail.com';
        }

        $local = explode('+', $local, 2)[0];

        if ($domain === 'gmail.com') {
            $local = str_replace('.', '', $local);
        }

        return $local . '@' . $domain;
    }
};
