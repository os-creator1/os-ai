<?php

namespace App\Library\Legal;

use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Presents and records acceptance of the legal bundle for a context.
 *
 * It only ever acts on PUBLISHED documents (LegalRegistry::bundle returns null otherwise), so no acceptance of a
 * draft can be shown or stored. The form carries a fingerprint of exactly what was shown; if a document changed
 * before the form was submitted the acceptance is refused and the customer must review the new text.
 */
final class LegalAcceptanceRecorder
{
    public const FIELD = 'accept_legal';

    public const FINGERPRINT_FIELD = 'legal_fingerprint';

    public function __construct(private readonly LegalRegistry $registry) {}

    /** @return array{accept: list<LegalDocument>, linked: list<LegalDocument>, fingerprint: string}|null null = nothing to present */
    public function present(string $context): ?array
    {
        $bundle = $this->registry->bundle($context);
        if ($bundle === null) {
            return null;
        }

        return $bundle + ['fingerprint' => $this->fingerprint($bundle['accept'])];
    }

    /** @param list<LegalDocument> $docs */
    public function fingerprint(array $docs): string
    {
        return hash('sha256', implode('|', array_map(fn (LegalDocument $d) => $d->slug.'@'.$d->version.':'.$d->hash, $docs)));
    }

    /**
     * Whether this request satisfies the bundle: null when there is nothing to accept,
     * true when affirmatively accepted against the current texts, false otherwise.
     */
    public function satisfied(string $context, Request $request): ?bool
    {
        $bundle = $this->present($context);
        if ($bundle === null) {
            return null;
        }

        return $request->boolean(self::FIELD)
            && hash_equals($bundle['fingerprint'], (string) $request->input(self::FINGERPRINT_FIELD));
    }

    /** Whether this user already accepted the exact current versions in this context (and, when given, for this offer). */
    public function hasAccepted(User $user, string $context, ?string $offerUid = null): bool
    {
        $bundle = $this->present($context);
        if ($bundle === null) {
            return false;
        }

        foreach ($bundle['accept'] as $doc) {
            $exists = LegalAcceptance::query()
                ->where('user_id', $user->id)
                ->where('document_slug', $doc->slug)
                ->where('document_version', $doc->version)
                ->where('content_hash', $doc->hash)
                ->where('context', $context)
                ->when($offerUid !== null, fn ($q) => $q->where('offer_uid', $offerUid))
                ->exists();
            if (! $exists) {
                return false;
            }
        }

        return true;
    }

    /**
     * Persist one evidence row per accepted document (idempotent per user, version, context and offer).
     *
     * @return list<LegalAcceptance>
     */
    public function record(User $user, string $context, Request $request, ?int $workspaceId = null, ?int $businessId = null, ?string $offerUid = null): array
    {
        $bundle = $this->present($context);
        if ($bundle === null || $this->satisfied($context, $request) !== true) {
            return [];
        }

        $rows = [];
        foreach ($bundle['accept'] as $doc) {
            $rows[] = LegalAcceptance::query()->firstOrCreate([
                'user_id' => $user->id,
                'document_slug' => $doc->slug,
                'document_version' => $doc->version,
                'content_hash' => $doc->hash,
                'context' => $context,
                'offer_uid' => $offerUid,
            ], [
                'user_email' => (string) $user->email,
                'workspace_id' => $workspaceId,
                'business_id' => $businessId,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'accepted_at' => now(),
            ]);
        }

        return $rows;
    }
}
