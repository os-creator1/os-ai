<?php

namespace App\Jobs\Documents;

use App\Jobs\Base;
use App\Library\Documents\Delivery\DocumentLinkSmsSender;
use App\Models\BusinessDocument;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contract 17B §7 — delivery of the secure link by text message.
 *
 * The twin of SendDocumentLinkEmail and bound by the same rules: AFTER COMMIT
 * structurally, ENCRYPTED because it carries the one plaintext token that will
 * ever exist for the link, run once ($tries = 1), never logging the token, and
 * recording its outcome only against the link whose token it carries (a rotated
 * link is neither texted nor recorded).
 *
 * It reaches a provider only through DocumentLinkSmsSender, which uses the
 * Business SMS seam (CampaignRepository::checkQuickSendValidation + quickSend).
 */
class SendDocumentLinkSms extends Base implements ShouldQueueAfterCommit, ShouldBeEncrypted
{
    public function __construct(
        private readonly int $documentId,
        private readonly string $plaintextToken,
        private readonly ?string $message = null,
    ) {
    }

    public function handle(DocumentLinkSmsSender $sender): void
    {
        $document = BusinessDocument::query()->find($this->documentId);

        if ($document === null) {
            Log::warning('Document link text skipped: document no longer exists.', ['document_id' => $this->documentId]);

            return;
        }

        if (! self::holdsCurrentLink($document, $this->plaintextToken)) {
            Log::warning('Document link text skipped: the link was rotated or revoked.', ['document_id' => $this->documentId]);

            return;
        }

        $url = route('public.documents.show', ['uid' => $document->uid, 'token' => $this->plaintextToken]);
        $reason = $sender->deliver($document, $sender->compose($document, $url, $this->message));

        if ($reason !== null) {
            Log::warning('Document link text not sent.', ['document_id' => $this->documentId, 'reason' => $reason]);
        }

        self::recordSmsOutcome($this->documentId, $this->plaintextToken, $reason === null);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Document link text failed.', [
            'document_id' => $this->documentId,
            'exception' => $exception::class,
        ]);

        self::recordSmsOutcome($this->documentId, $this->plaintextToken, false);
    }

    /**
     * Writes the SMS delivery fact for the link whose plaintext token is given
     * — and for no other.
     */
    public static function recordSmsOutcome(int $documentId, string $plaintextToken, bool $delivered): void
    {
        $document = BusinessDocument::query()->find($documentId);

        if ($document === null || ! self::holdsCurrentLink($document, $plaintextToken)) {
            return;
        }

        BusinessDocument::query()->whereKey($documentId)
            ->where('access_token_hash', $document->access_token_hash)
            ->update($delivered
                ? ['sms_link_delivered_at' => now(), 'sms_link_delivery_failed_at' => null]
                : ['sms_link_delivered_at' => null, 'sms_link_delivery_failed_at' => now()]);
    }

    private static function holdsCurrentLink(BusinessDocument $document, string $plaintextToken): bool
    {
        $hash = $document->access_token_hash;

        return is_string($hash) && $hash !== '' && Hash::check($plaintextToken, $hash);
    }
}
