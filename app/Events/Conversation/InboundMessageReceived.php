<?php

namespace App\Events\Conversation;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Automations V2-F §9 — a message reached a Business, and we know which one.
 *
 * DELIBERATELY NOT `App\Events\MessageReceived`. That event is the inbox's
 * websocket broadcast, emitted by the legacy path alone, and the CX contract
 * (§15.1) forbids adapting it into a domain event. This one is the domain fact,
 * emitted from BOTH inbound paths, and it is emitted ONLY once attribution is
 * authoritative:
 *
 *   legacy   DLRController::inboundDLR(), when exactly one assigned number
 *            received the message AND that number carries a Business belonging
 *            to the number's own customer — the same Business the conversation
 *            is filed under. Never the Reports row's primary-Business fallback.
 *   managed  InboundWebhookAttributionResolver::persistInbound(), after the
 *            dual-signal (Messaging Profile + destination number) attribution
 *            agreed on one Business identity.
 *
 * An unattributable message emits nothing. That is the whole of the tenancy
 * story at this seam: nothing downstream ever has to wonder which Business a
 * message belongs to, because an event only exists when that was proved.
 *
 * AFTER COMMIT, AND IDS ONLY. The listener re-reads everything, so a Business,
 * contact or workflow that changed between the webhook and the queued job is
 * seen as it is when the work runs. The sender's number is the one fact carried
 * as a value, because the managed path persists no per-message sender anywhere.
 *
 * THE OCCURRENCE KEY is namespaced by path — `report:{id}` or `operation:{id}` —
 * because the two paths record a message in different tables whose ids are
 * independent. Namespacing is what stops report 42 and operation 42 from
 * colliding into one enrollment key.
 */
class InboundMessageReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $businessId,
        public readonly string $senderPhone,
        public readonly string $occurrenceKey,
        public readonly ?int $inboundReportId = null,
    ) {
    }

    /** From the legacy path, keyed by the inbound Reports row it just wrote. */
    public static function fromLegacyReport(int $businessId, string $senderPhone, int $reportId): self
    {
        return new self($businessId, $senderPhone, 'report:' . $reportId, $reportId);
    }

    /** From the managed path, keyed by the operation row it just wrote. */
    public static function fromManagedOperation(int $businessId, string $senderPhone, int $operationId): self
    {
        return new self($businessId, $senderPhone, 'operation:' . $operationId);
    }
}
