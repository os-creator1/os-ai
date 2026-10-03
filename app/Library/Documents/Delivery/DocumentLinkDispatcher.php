<?php

namespace App\Library\Documents\Delivery;

use App\Jobs\Documents\SendDocumentLinkEmail;
use App\Jobs\Documents\SendDocumentLinkSms;
use App\Models\BusinessDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Contract 17B §7 — the channels of ONE send. DocumentManager performs a single
 * lifecycle transition and a single token rotation, then hands the plaintext
 * token here once, after commit; each requested channel is just a delivery
 * attempt of that same link. Nothing here touches lifecycle or token state.
 *
 * Every channel failure is contained and recorded on its own marker (the
 * document is already committed as sent and one channel's outage must never
 * block the other or turn into a 500). The returned per-channel result is what
 * the send dialog shows; the durable record is the document's markers.
 */
class DocumentLinkDispatcher
{
    public const CHANNELS = ['email', 'sms'];

    private const REASON_TEXT = [
        DocumentLinkSmsSender::REASON_NO_CONTACT => 'The contact for this document could not be found.',
        DocumentLinkSmsSender::REASON_UNSUBSCRIBED => 'The contact is not subscribed to text messages.',
        DocumentLinkSmsSender::REASON_NO_PHONE => 'There is no phone number on this document.',
        DocumentLinkSmsSender::REASON_PHONE_INVALID => 'The phone number could not be used for text messages.',
        DocumentLinkSmsSender::REASON_NO_PATH => 'This business has no text messaging sender set up.',
        DocumentLinkSmsSender::REASON_REJECTED => 'The messaging sender was rejected.',
        DocumentLinkSmsSender::REASON_FAILED => 'The text message could not be sent.',
    ];

    public function __construct(private readonly DocumentLinkSmsSender $texts)
    {
    }

    /**
     * Null (the pre-17B callers) means email only. Anything else must be a
     * non-empty set of known channels.
     *
     * @param  array<int, mixed>|null  $channels
     * @return array<int, string>
     *
     * @throws ValidationException
     */
    public function normalize(?array $channels): array
    {
        if ($channels === null) {
            return ['email'];
        }

        $clean = array_values(array_unique(array_filter($channels, 'is_string')));

        if ($clean === [] || array_diff($clean, self::CHANNELS) !== [] || count($clean) !== count($channels)) {
            throw ValidationException::withMessages(['channels' => 'Choose at least one delivery channel: email or text message.']);
        }

        return $clean;
    }

    /**
     * Queue every requested channel for the link just minted.
     *
     * @param  array<int, string>  $channels  already normalized
     * @return array<string, array{status: string, reason?: string, message?: string}>
     */
    public function dispatch(BusinessDocument $document, string $plaintextToken, array $channels, ?string $message = null): array
    {
        $results = [];

        if (in_array('email', $channels, true)) {
            $results['email'] = $this->email($document, $plaintextToken);
        }

        if (in_array('sms', $channels, true)) {
            $results['sms'] = $this->text($document, $plaintextToken, $message);
        }

        return $results;
    }

    /** @return array{status: string} */
    private function email(BusinessDocument $document, string $plaintextToken): array
    {
        try {
            SendDocumentLinkEmail::dispatch((int) $document->id, $plaintextToken);

            return ['status' => 'queued'];
        } catch (Throwable $e) {
            Log::warning('Document link email could not be delivered.', [
                'document_id' => (int) $document->id,
                'exception' => $e::class,
            ]);

            SendDocumentLinkEmail::recordOutcome((int) $document->id, $plaintextToken, false);

            return ['status' => 'failed'];
        }
    }

    /** @return array{status: string, reason?: string, message?: string} */
    private function text(BusinessDocument $document, string $plaintextToken, ?string $message): array
    {
        // Known-unsendable (unsubscribed, no phone, no sender): record the
        // failure now and say why, rather than queueing a job that cannot work.
        $reason = $this->texts->preflight($document);

        if ($reason !== null) {
            SendDocumentLinkSms::recordSmsOutcome((int) $document->id, $plaintextToken, false);

            return ['status' => 'failed', 'reason' => $reason, 'message' => self::REASON_TEXT[$reason] ?? self::REASON_TEXT[DocumentLinkSmsSender::REASON_FAILED]];
        }

        try {
            SendDocumentLinkSms::dispatch((int) $document->id, $plaintextToken, $message);

            return ['status' => 'queued'];
        } catch (Throwable $e) {
            Log::warning('Document link text could not be delivered.', [
                'document_id' => (int) $document->id,
                'exception' => $e::class,
            ]);

            SendDocumentLinkSms::recordSmsOutcome((int) $document->id, $plaintextToken, false);

            return ['status' => 'failed', 'reason' => DocumentLinkSmsSender::REASON_FAILED, 'message' => self::REASON_TEXT[DocumentLinkSmsSender::REASON_FAILED]];
        }
    }
}
