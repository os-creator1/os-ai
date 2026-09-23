<?php

namespace App\Library\Documents;

use App\Enums\Business\BusinessStatus;
use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\DocumentVersionState;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Documents\DocumentLinkException;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\CustomerAccountAccessGuard;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;

/**
 * Implementation Contract 17 §6.3 / §6.3.1 — the ONE gate every public
 * document request passes through, on EVERY request, reading everything
 * fresh from persistence.
 *
 * THE LINK IS AUTHORIZATION, NOT AN ACCOUNT OR ENTITLEMENT BYPASS.
 * Possession of the token authorizes this end customer's access to this one
 * document. It does not make a suspended, locked or unentitled account
 * executable forever, so §6.3.1's five checks run in this exact order,
 * before anything else happens:
 *
 *   1. the document exists and the token is valid;
 *   2. the owning Business/Workspace account lifecycle currently permits
 *      customer-facing operation — resolved through the EXISTING canonical
 *      authority (CustomerAccountAccessGuard -> CustomerAccountAccessResolver),
 *      never a second lifecycle resolver invented here;
 *   3. Payments & Contracts is currently entitled for that Business — via
 *      EntitlementManager, the same authority the authenticated gate uses;
 *   4. the document's Location is usable;
 *   5. the document's own lifecycle permits the requested operation.
 *
 * ONE UNIFORM, NON-ENUMERATING REFUSAL. Every failure above throws the same
 * DocumentLinkException and the controller renders the same generic page
 * with the same status, so an attacker cannot tell an unknown uid from a
 * wrong token from a voided document from a suspended account. The `reason`
 * exists for our tests and logs, never for the response.
 *
 * NO WRITES. Nothing in this class mutates anything: §6.3's GET is
 * genuinely side-effect-free, and there is deliberately no DocumentViewed
 * event and no last_viewed_at column (§10).
 *
 * THE TOKEN IS NEVER A LOOKUP KEY. `access_token_hash` is a Hash::make()
 * value; the uid alone locates the row and the plaintext is only ever
 * compared through Hash::check() — the ClientInvitationManager discipline
 * (§6.3), mirrored exactly.
 */
class PublicDocumentGuard
{
    public function __construct(
        private readonly CustomerAccountAccessGuard $accounts,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /**
     * §6.3.1 checks 1-4, plus "this document may be viewed at all".
     *
     * @throws DocumentLinkException every failure, indistinguishably
     */
    public function resolve(string $uid, string $plaintextToken): PublicDocumentAccess
    {
        $document = BusinessDocument::query()->where('uid', $uid)->first();

        if ($document === null) {
            throw DocumentLinkException::because(DocumentLinkException::NOT_FOUND);
        }

        $this->assertTokenAuthorizes($document, $plaintextToken);

        // ---- 2/3: the account behind the document -----------------------
        $business = Business::query()->find($document->business_id);
        $workspace = $business === null ? null : Workspace::query()->find($business->workspace_id);

        if ($business === null || $workspace === null
            || $business->status !== BusinessStatus::Active
            || ! $workspace->is_active) {
            throw DocumentLinkException::because(DocumentLinkException::ACCOUNT_UNAVAILABLE);
        }

        if ($this->accounts->decisionForBusiness($business)->isLocked()) {
            throw DocumentLinkException::because(DocumentLinkException::ACCOUNT_LOCKED);
        }

        if (! $this->entitlementAllows($workspace, $business)) {
            throw DocumentLinkException::because(DocumentLinkException::NOT_ENTITLED);
        }

        // ---- 4: the Location ---------------------------------------------
        $location = BusinessLocation::query()
            ->where('business_id', $business->id)
            ->find($document->business_location_id);

        if ($location === null || ! $location->isActive()) {
            throw DocumentLinkException::because(DocumentLinkException::LOCATION_UNUSABLE);
        }

        // ---- 5a: viewable at all -----------------------------------------
        if (in_array($document->status, [DocumentStatus::Draft, DocumentStatus::Void], true)) {
            throw DocumentLinkException::because(DocumentLinkException::DOCUMENT_NOT_VIEWABLE);
        }

        $version = $document->current_version_id === null
            ? null
            : BusinessDocumentVersion::query()
                ->where('business_document_id', $document->id)
                ->find($document->current_version_id);

        // The public surface renders the FROZEN ISSUED version and nothing
        // else. A draft-state current version would mean the send path was
        // bypassed, so it is refused rather than displayed.
        if ($version === null || $version->state !== DocumentVersionState::Issued) {
            throw DocumentLinkException::because(DocumentLinkException::NO_ISSUED_VERSION);
        }

        return new PublicDocumentAccess($document, $version, $business, $workspace, $location);
    }

    /**
     * §7.3 / §7.1 — the additional lifecycle conditions signing requires.
     * Re-checked here for the early refusal, and AGAIN under the document
     * lock inside DocumentManager::sign(), which is the authoritative one.
     *
     * @throws DocumentLinkException
     */
    public function assertSignable(PublicDocumentAccess $access): void
    {
        $document = $access->document;

        if (! $document->requires_signature) {
            throw DocumentLinkException::because(DocumentLinkException::OPERATION_NOT_PERMITTED);
        }

        if ($document->status !== DocumentStatus::Sent) {
            throw DocumentLinkException::because(
                $document->status === DocumentStatus::Signed
                    ? DocumentLinkException::ALREADY_SIGNED
                    : DocumentLinkException::OPERATION_NOT_PERMITTED
            );
        }

        if ($document->expires_at !== null && $document->expires_at->isPast()) {
            throw DocumentLinkException::because(DocumentLinkException::EXPIRED);
        }

        if ($document->signature()->exists()) {
            throw DocumentLinkException::because(DocumentLinkException::ALREADY_SIGNED);
        }
    }

    /**
     * §6.3 — check 1. A document with no live link (never sent, or voided,
     * which clears the hash) is refused before any hashing work, and an
     * expired link is refused before the token is compared at all.
     *
     * @throws DocumentLinkException
     */
    private function assertTokenAuthorizes(BusinessDocument $document, string $plaintextToken): void
    {
        $hash = $document->access_token_hash;

        if ($hash === null || $hash === '') {
            throw DocumentLinkException::because(DocumentLinkException::NO_ACTIVE_LINK);
        }

        if ($document->access_token_expires_at === null || $document->access_token_expires_at->isPast()) {
            throw DocumentLinkException::because(DocumentLinkException::EXPIRED);
        }

        // NEVER queried by plaintext. Hash::check() is the only comparison;
        // a rotated token fails here because the stored hash is a different
        // bcrypt value, and a valid token for a DIFFERENT document fails
        // here because the uid located this document's hash, not that one's.
        if (! Hash::check($plaintextToken, $hash)) {
            throw DocumentLinkException::because(DocumentLinkException::INVALID_TOKEN);
        }
    }

    /**
     * §6.3.1 check 3. Its own method for exactly one reason: a test may
     * replace THIS step alone (the feature is `Planned` until Sub-slice G,
     * so EntitlementManager denies it for every tier and the public surface
     * is unreachable by design) without weakening any other check. The
     * actor id is the Business's own owning customer — server-derived,
     * never anything a browser supplied — exactly as
     * PublicBookingController does for the Calendar feature.
     */
    protected function entitlementAllows(Workspace $workspace, Business $business): bool
    {
        try {
            return $this->entitlements->decide(
                $workspace,
                $business,
                PlatformFeature::PaymentsContracts->value,
                (int) $business->customer_id,
            )->allowed;
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }
    }
}
