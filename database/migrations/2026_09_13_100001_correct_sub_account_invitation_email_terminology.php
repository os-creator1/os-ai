<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Customer Experience Redesign — Slice 1B, final terminology closure.
 *
 * The delegated-access invitation email is the one customer-facing surface
 * whose copy does NOT come from `resources/lang/en/locale.php`. It is stored
 * in the `email_templates` row `subaccount_invitation_notification`, seeded by
 * `2025_06_02_165201_create_sub_account_invitation_email_template`, and read
 * at send time by `App\Mail\SubAccountInvitation`. Correcting the locale
 * values in Slice 1A therefore did not reach it: the email still arrived in a
 * customer's inbox saying "Sub-Account".
 *
 * WHY A MIGRATION AND NOT AN EDIT TO THE ORIGINAL. Rewriting the 2025 seed
 * would only help installations that have not run it yet, and would silently
 * rewrite history for everyone else. This runs after it instead, so a FRESH
 * install seeds the old text and is corrected in the same `migrate` run,
 * while an EXISTING install is corrected in place. Neither path needs the
 * historical migration touched.
 *
 * WHY TARGETED REPLACEMENT AND NOT AN OVERWRITE. The row is operator-editable
 * — an installation may have rewritten this email entirely. Replacing the
 * whole row with contract wording would destroy that. Only the forbidden noun
 * is substituted, so a customised invitation keeps its customisation and
 * merely stops saying "Sub Account".
 *
 * IDEMPOTENT. The replacement is keyed on the forbidden noun, so a second run
 * finds nothing to change. Safe to replay.
 */
return new class extends Migration
{
    private const SLUG = 'subaccount_invitation_notification';

    /**
     * Longest first: "Sub-Accounts" must be consumed before "Sub-Account"
     * could match its prefix and leave a stray "s" behind.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        'Sub-Accounts' => 'team members',
        'Sub Accounts' => 'team members',
        'sub-accounts' => 'team members',
        'sub accounts' => 'team members',
        'Sub-Account' => 'team member',
        'Sub Account' => 'team member',
        'sub-account' => 'team member',
        'sub account' => 'team member',
    ];

    public function up(): void
    {
        $template = DB::table('email_templates')->where('slug', self::SLUG)->first();

        if ($template === null) {
            // Nothing seeded on this installation; the seed migration above
            // owns creation, and this correction has nothing to correct.
            return;
        }

        $subject = strtr((string) $template->subject, self::REPLACEMENTS);
        $content = strtr((string) $template->content, self::REPLACEMENTS);

        if ($subject === (string) $template->subject && $content === (string) $template->content) {
            // Already clean — a replay, or an operator who fixed it first.
            return;
        }

        DB::table('email_templates')
            ->where('slug', self::SLUG)
            ->update([
                'subject' => $subject,
                'content' => $content,
                'updated_at' => now(),
            ]);
    }

    /**
     * Deliberately a no-op.
     *
     * The forward direction removes a term the terminology contract forbids in
     * customer copy. An automatic rollback would put it back — reintroducing
     * the defect on a `migrate:rollback` that was almost certainly aimed at a
     * neighbouring schema change. Nothing structural is created here, so there
     * is nothing to drop; the row, its slug, its uid and every identifier are
     * untouched in both directions.
     */
    public function down(): void
    {
    }
};
