<?php

namespace App\Library\Legal;

use App\Models\LegalAcceptance;
use App\Models\LegalDocumentSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Durable, server-side record of an affirmative acceptance of a legal document.
 *
 * What is recorded is never taken from the client: the version and the snapshot come from the document the
 * server is offering right now. A client may echo the version (and optionally the hash) it was shown; if
 * either differs from what is currently offered the acceptance is refused, so a forged or stale value can
 * never be stored. Viewing a page is never acceptance. A document that depends on unverified facts
 * (`acceptance_eligible` false), or any document while `legal.acceptance_enabled` is off, cannot be accepted.
 *
 * The accepted text is frozen in legal_document_snapshots (keyed by content hash) together with the company
 * revision it was rendered with, so a later change to the registered office never alters what was accepted.
 * A routine company-detail update does not bump the document version, so it does not demand re-acceptance.
 */
class LegalAcceptanceService
{
    public const CONTEXTS = [
        'registration', 'subscription_checkout', 'trial_checkout', 'agency_signup',
        'billing_settings', 'document_workflow', 'communications_setup',
    ];

    public function __construct(private readonly LegalDocuments $documents)
    {
    }

    public function isOffered(string $slug): bool
    {
        $meta = $this->documents->meta($slug);

        return $meta !== null && (bool) config('legal.acceptance_enabled') && ! empty($meta['acceptance_eligible']);
    }

    /** Whether the user has accepted the version currently offered. */
    public function hasAccepted(int $userId, string $slug): bool
    {
        $meta = $this->documents->meta($slug);

        return $meta !== null && LegalAcceptance::query()
            ->where('user_id', $userId)->where('document_slug', $slug)->where('document_version', $meta['version'])
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    public function record(
        User $user,
        string $slug,
        string $submittedVersion,
        string $context,
        ?int $workspaceId = null,
        ?string $offerRef = null,
        ?string $submittedHash = null,
    ): LegalAcceptance {
        if (! $this->isOffered($slug)) {
            throw ValidationException::withMessages(['legal' => 'This document is not currently available for acceptance.']);
        }
        if (! in_array($context, self::CONTEXTS, true)) {
            throw ValidationException::withMessages(['legal' => 'Unknown acceptance context.']);
        }

        $rendered = $this->documents->render($slug);

        if (! hash_equals((string) $rendered['meta']['version'], $submittedVersion)
            || ($submittedHash !== null && ! hash_equals($rendered['hash'], $submittedHash))) {
            throw ValidationException::withMessages(['legal' => 'The document changed while you were reading it. Reload the page and accept the current version.']);
        }

        return DB::transaction(function () use ($user, $slug, $rendered, $context, $workspaceId, $offerRef) {
            $snapshot = LegalDocumentSnapshot::query()->firstOrCreate(
                ['slug' => $slug, 'version' => $rendered['meta']['version'], 'content_hash' => $rendered['hash']],
                [
                    'company_profile_id' => (int) $rendered['company']['id'],
                    'rendered_html' => $rendered['html'],
                ],
            );

            return LegalAcceptance::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'document_slug' => $slug,
                'document_version' => $rendered['meta']['version'],
                'snapshot_id' => $snapshot->id,
                'context' => $context,
                'offer_ref' => $offerRef,
                'accepted_at' => now(),
            ]);
        });
    }
}
