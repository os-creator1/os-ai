<?php

namespace App\Library\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\InboundWebhookEventKind;
use App\Enums\Messaging\MessagingOperationStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\MessagingTransportMode;
use App\Enums\Messaging\WebhookRejectionReason;
use App\Library\Messaging\Contracts\MessagingProviderAdapter;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\SMSCounter;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\Reports;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Slice 3 §4.6 — fail-closed attribution for managed Telnyx inbound traffic.
 *
 * Two independent signals must agree before anything is attributed: the
 * Messaging Profile ID and the destination number. Neither is ever, on its
 * own, sufficient — that single-signal shortcut is the exact defect this
 * resolver exists to prevent. There is no global-first-match, no optional
 * Business fallback, no user-provided Business id, and no URL path segment
 * involved in the decision.
 *
 * Every refusal writes exactly one idempotent rejection row, updates no
 * conversation or message row, debits no wallet, triggers no automation, and
 * attributes to no Business.
 *
 * Response codes are chosen for semantic honesty only (§4.6.4); correctness
 * against duplicate delivery rests entirely on the idempotency guards, never
 * on a status code.
 */
class InboundWebhookAttributionResolver
{
    /**
     * How many times an unattributable DELIVERY callback is asked for
     * redelivery before this platform accepts that it will never be
     * attributable.
     *
     * Sized for the early-DLR race it exists to survive — a callback that
     * beat its own operation's finalization is resolvable on the very next
     * attempt — while staying small enough that a genuinely foreign
     * provider_message_id cannot make a provider retry indefinitely.
     */
    public const EARLY_DLR_RETRY_BUDGET = 3;

    /**
     * The outcome of one locked delivery-status transition.
     *
     * These exist so the decision can be made while the operation row is held
     * FOR UPDATE while the rejection record and the HTTP answer are produced
     * after the lock is released. They are not a second transition policy:
     * MessagingOperationStatus remains the only authority, and each value
     * below is simply the answer it already gave.
     */
    private const TRANSITION_APPLIED = 'applied';

    private const TRANSITION_MALFORMED = 'malformed';

    private const TRANSITION_DUPLICATE = 'duplicate';

    private const TRANSITION_REGRESSIVE = 'regressive';

    private const TRANSITION_REPORT_INTEGRITY = 'report_integrity';

    public function __construct(
        private readonly BusinessMessagingIdentityResolver $resolver,
        private readonly MessagingWebhookRejectionRecorder $rejections,
        private readonly UsageWalletManager $walletManager,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        try {
            $adapter = app(MessagingProviderAdapter::class);
        } catch (MessagingProviderNotConfiguredException) {
            // Fail closed when managed messaging is not active: nothing is
            // attributed, and no rejection row is written for traffic we are
            // not configured to receive at all.
            return response()->json(['status' => 'ignored'], 200);
        }

        // 1. Signature verification, first, always.
        if (! $adapter->verifyInboundSignature($rawBody, self::headers($request))) {
            $this->rejections->record(
                WebhookRejectionReason::InvalidSignature,
                MessagingProvider::Telnyx,
                $rawBody,
            );

            return response()->json(['status' => 'rejected'], 403);
        }

        // 2. Parse. A verified body that is not the expected shape is a 400.
        try {
            $event = $adapter->parseInboundWebhook($rawBody);
        } catch (\Throwable) {
            $this->rejections->record(
                WebhookRejectionReason::MalformedPayload,
                MessagingProvider::Telnyx,
                $rawBody,
            );

            return response()->json(['status' => 'rejected'], 400);
        }

        // 3. Branch by kind — the two shapes have opposite expected states
        // for (provider, provider_message_id).
        return $event->kind === InboundWebhookEventKind::DeliveryStatus
            ? $this->handleDeliveryStatus($event, $rawBody)
            : $this->handleMessageReceived($event, $rawBody);
    }

    private function handleMessageReceived(InboundWebhookEvent $event, string $rawBody): JsonResponse
    {
        // A row already existing for this exact pair means this inbound
        // message was already processed — a true replay.
        if ($event->providerMessageId !== null && $event->providerMessageId !== '') {
            $alreadyProcessed = DB::table(ManagedMessageDispatcher::TABLE)
                ->where('provider', MessagingProvider::Telnyx->value)
                ->where('provider_message_id', $event->providerMessageId)
                ->exists();

            if ($alreadyProcessed) {
                $this->rejections->record(
                    WebhookRejectionReason::Duplicate,
                    MessagingProvider::Telnyx,
                    $rawBody,
                    $event->messagingProfileId,
                    $event->destinationNumber,
                );

                return response()->json(['status' => 'duplicate'], 200);
            }
        }

        // 4. Both signals must be present and normalizable before either is
        // resolved; a missing one is a malformed payload, not an
        // unattributable one.
        $profileId = $event->messagingProfileId !== null ? trim($event->messagingProfileId) : '';
        $normalizedDestination = E164Normalizer::normalize($event->destinationNumber);

        if ($profileId === '' || $normalizedDestination === null) {
            $this->rejections->record(
                WebhookRejectionReason::MalformedPayload,
                MessagingProvider::Telnyx,
                $rawBody,
                $event->messagingProfileId,
                $event->destinationNumber,
            );

            return response()->json(['status' => 'rejected'], 400);
        }

        $identityByProfile = $this->resolver->resolveByMessagingProfileId($profileId);
        $identityByNumber = $this->resolver->resolveByPhoneNumber($normalizedDestination);

        if ($identityByProfile === null || $identityByNumber === null) {
            $this->rejections->record(
                WebhookRejectionReason::UnknownMapping,
                MessagingProvider::Telnyx,
                $rawBody,
                $profileId,
                $normalizedDestination,
                $identityByProfile?->id,
                $identityByNumber?->id,
            );

            return response()->json(['status' => 'unattributed'], 200);
        }

        if ((int) $identityByProfile->id !== (int) $identityByNumber->id) {
            $this->rejections->record(
                WebhookRejectionReason::ConflictingMapping,
                MessagingProvider::Telnyx,
                $rawBody,
                $profileId,
                $normalizedDestination,
                (int) $identityByProfile->id,
                (int) $identityByNumber->id,
            );

            return response()->json(['status' => 'unattributed'], 200);
        }

        // 5. Only on full agreement. A delivery that loses the unique-key
        //    race reports the duplicate it is, rather than claiming an
        //    effect another copy actually produced.
        if (! $this->persistInbound($identityByProfile, $event, $normalizedDestination)) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        return response()->json(['status' => 'accepted'], 200);
    }

    private function handleDeliveryStatus(InboundWebhookEvent $event, string $rawBody): JsonResponse
    {
        // 1. Locate, never create.
        $operation = $event->providerMessageId === null || $event->providerMessageId === ''
            ? null
            : DB::table(ManagedMessageDispatcher::TABLE)
                ->where('provider', MessagingProvider::Telnyx->value)
                ->where('provider_message_id', $event->providerMessageId)
                ->first();

        if ($operation === null) {
            // THE EARLY-DLR RACE (audit P10).
            //
            // A delivery callback can legitimately arrive between the
            // provider accepting a message and this platform durably
            // attaching the returned provider_message_id to its operation
            // row. Answering 200 told the provider "understood, do not send
            // that again" — and the callback was then lost for good, leaving
            // the operation stuck at accepted forever.
            //
            // A 503 asks for redelivery, by which time finalization has
            // completed. The bound is deliberate and operational: the
            // recorder returns the occurrence count for this exact
            // fingerprint, and after EARLY_DLR_RETRY_BUDGET redeliveries
            // this stops asking and accepts the callback as genuinely
            // unattributable — so a provider_message_id that never belonged
            // here cannot make a provider retry forever.
            $rejection = $this->rejections->record(
                WebhookRejectionReason::UnknownMapping,
                MessagingProvider::Telnyx,
                $rawBody,
                $event->messagingProfileId,
                $event->destinationNumber,
            );

            if ((int) $rejection->occurrence_count <= self::EARLY_DLR_RETRY_BUDGET) {
                return response()->json(['status' => 'retry'], 503);
            }

            return response()->json(['status' => 'unattributed'], 200);
        }

        // 2. Business-identity validation against any evidence the payload
        // also carries — a mismatch is a conflict, never a state change.
        if (! $this->deliveryEvidenceAgrees($event, $operation)) {
            $this->rejections->record(
                WebhookRejectionReason::ConflictingMapping,
                MessagingProvider::Telnyx,
                $rawBody,
                $event->messagingProfileId,
                $event->destinationNumber,
                null,
                null,
            );

            return response()->json(['status' => 'rejected'], 200);
        }

        // 3. Status-transition guard. Replay for a delivery-status event is a
        // function of the row's CURRENT status, never of the row existing.
        //
        // The TARGET is a pure function of the payload, so it is parsed here.
        // The CURRENT status is not: it is durable state two callbacks can
        // contend for, and it is therefore read under a lock below.
        $target = self::targetStatus($event->deliveryStatus);

        if ($target === null) {
            $this->rejections->record(
                WebhookRejectionReason::MalformedPayload,
                MessagingProvider::Telnyx,
                $rawBody,
                $event->messagingProfileId,
                $event->destinationNumber,
            );

            return response()->json(['status' => 'rejected'], 400);
        }

        // THE DELIVERY-STATUS LOST-UPDATE RACE (Security Correction 39).
        //
        // This method used to decide the transition from the UNLOCKED read at
        // the top: two callbacks for one operation could both read
        // 'accepted', both independently validate — one to Delivered, one to
        // Failed, each legal from 'accepted' — and then both write. The later
        // write won, landing Delivered → Failed (or the reverse), a
        // transition MessagingOperationStatus forbids outright. Validating a
        // decision against a value another worker is already changing is not
        // a guard.
        //
        // The row is now re-selected FOR UPDATE inside the transaction and
        // the current status is derived from THAT row, so the second caller
        // blocks until the first commits and then sees its result — 'failed'
        // is no longer 'accepted', so the second transition is refused as the
        // policy already says it should be.
        //
        // The lock is held across BOTH the operation update and the
        // correlated Report synchronization, because they are one transition.
        // No provider or network call happens inside it: every branch below
        // is a database read or write, and rejection recording and the JSON
        // response both happen after the lock is released.
        $outcome = DB::transaction(function () use ($operation, $target, $event): string {
            $locked = DB::table(ManagedMessageDispatcher::TABLE)
                ->where('id', $operation->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                // The row existed for the unlocked read and does not now.
                return self::TRANSITION_MALFORMED;
            }

            $current = MessagingOperationStatus::tryFrom((string) $locked->status);

            if ($current === null) {
                return self::TRANSITION_MALFORMED;
            }

            if ($current === $target) {
                // Exact replay — a no-op, recognized only AFTER the first
                // valid transition into that status has actually been applied.
                return self::TRANSITION_DUPLICATE;
            }

            if (! $current->allowsDeliveryTransitionTo($target)) {
                // Regressive or otherwise invalid — the operation can never
                // move backward through a delivery-status callback.
                return self::TRANSITION_REGRESSIVE;
            }

            // Finding 2 — the correlated Report is validated BEFORE the
            // operation moves, deliberately.
            //
            // Returning an outcome from this closure COMMITS the transaction,
            // so discovering a Report integrity failure after updating the
            // operation would commit that update and leave the pair
            // disagreeing — exactly the partial transition §5 forbids. The
            // operation and its customer-visible Report are one transition:
            // either both move or neither does.
            $report = $locked->report_id !== null
                ? $this->integrityCheckedCorrelatedReport($locked)
                : null;

            if ($locked->report_id !== null && $report === null) {
                return self::TRANSITION_REPORT_INTEGRITY;
            }

            DB::table(ManagedMessageDispatcher::TABLE)
                ->where('id', $locked->id)
                ->update([
                    'status' => $target->value,
                    // The callback's own timestamp, never the original send's.
                    'occurred_at' => $event->occurredAt,
                    'updated_at' => Carbon::now(),
                ]);

            if ($report !== null) {
                $this->syncCorrelatedReport($report, $target);
            }

            return self::TRANSITION_APPLIED;
        });

        // Rejection recording and the response happen outside the lock. The
        // transition policy is not duplicated here — every decision above was
        // made by MessagingOperationStatus against the locked row, and this
        // only translates that one decision into an answer.
        return match ($outcome) {
            self::TRANSITION_MALFORMED => $this->refuseDeliveryStatus(
                WebhookRejectionReason::MalformedPayload, $event, $rawBody, 'rejected', 400,
            ),
            self::TRANSITION_DUPLICATE => $this->refuseDeliveryStatus(
                WebhookRejectionReason::Duplicate, $event, $rawBody, 'duplicate', 200,
            ),
            self::TRANSITION_REGRESSIVE => $this->refuseDeliveryStatus(
                WebhookRejectionReason::RegressiveTransition, $event, $rawBody, 'rejected', 200,
            ),
            self::TRANSITION_REPORT_INTEGRITY => $this->refuseDeliveryStatus(
                WebhookRejectionReason::ConflictingMapping, $event, $rawBody, 'rejected', 200,
            ),
            default => response()->json(['status' => 'accepted'], 200),
        };
    }

    /**
     * Record one delivery-status refusal and answer it, outside the lock.
     */
    private function refuseDeliveryStatus(
        WebhookRejectionReason $reason,
        InboundWebhookEvent $event,
        string $rawBody,
        string $status,
        int $code,
    ): JsonResponse {
        $this->rejections->record(
            $reason,
            MessagingProvider::Telnyx,
            $rawBody,
            $event->messagingProfileId,
            $event->destinationNumber,
        );

        return response()->json(['status' => $status], $code);
    }

    /**
     * Finding 2 — the correlated Report must pass the same Business integrity
     * Correction 38 applies at the DLR seam.
     *
     * The database cannot express `operation.business_id == report.business_id`
     * as a constraint, and the current tree is safe only because exactly one
     * application writer sets `report_id`. That is an argument about today's
     * callers, not a guarantee, so the invariant is enforced where the Report
     * is actually mutated.
     */
    private function integrityCheckedCorrelatedReport(object $operation): ?Reports
    {
        $report = Reports::find((int) $operation->report_id);

        if ($report === null) {
            return null;
        }

        $operationBusinessId = $operation->business_id ?? null;
        $reportBusinessId = $report->business_id;

        if ($operationBusinessId === null
            || $reportBusinessId === null
            || (int) $reportBusinessId !== (int) $operationBusinessId) {
            return null;
        }

        // A managed send writes no legacy sending_server_id (§4.5), so a
        // Report correlated to a managed operation must not claim one.
        if ($report->sending_server_id !== null) {
            return null;
        }

        return $report;
    }

    /**
     * ATOMIC, and idempotent by the DATABASE rather than by a prior read.
     *
     * What was here before: an `exists()` check upstream, then an unguarded
     * insert, then the measurement OUTSIDE any transaction. Two concurrent
     * copies of the same webhook both passed the check; one hit the unique
     * index and surfaced a 500. Worse, a crash between the insert and the
     * measurement left an operation row whose measurement could never be
     * written, because every later replay would see that row and take the
     * duplicate branch — the usage was lost permanently.
     *
     * The insert and its measurement are now one transaction, committing
     * together or not at all, and losing the unique key is treated as what
     * it is: a replay another copy already handled.
     *
     * @return bool false when this delivery lost the race, so the caller can
     *              report a duplicate instead of claiming an effect it did
     *              not produce
     */
    private function persistInbound(
        BusinessMessagingIdentity $identity,
        InboundWebhookEvent $event,
        string $destinationNumber,
    ): bool {
        $now = Carbon::now();
        $businessId = (int) $identity->business_id;
        $messageType = $event->mediaUrls === [] ? 'sms' : 'mms';

        try {
            DB::transaction(function () use ($identity, $event, $businessId, $messageType, $now): void {
                DB::table(ManagedMessageDispatcher::TABLE)->insert([
                    'business_id' => $businessId,
                    'business_messaging_identity_id' => (int) $identity->id,
                    'transport_mode' => MessagingTransportMode::Managed->value,
                    'provider' => MessagingProvider::Telnyx->value,
                    'direction' => 'inbound',
                    'message_type' => $messageType,
                    'operation_key' => null,
                    'provider_message_id' => $event->providerMessageId,
                    'status' => MessagingOperationStatus::Delivered->value,
                    'occurred_at' => $event->occurredAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $business = Business::query()->find($businessId);

                if ($business instanceof Business && $event->providerMessageId !== null) {
                    $this->walletManager->recordMeasurement(
                        $business,
                        PlatformFeature::MessagingTransport,
                        self::segmentQuantityFor($event->body),
                        'segment',
                        'inbound:' . MessagingProvider::Telnyx->value . ':' . $event->providerMessageId,
                        MessagingTransportMode::Managed->value,
                    );
                }
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * The real segment count, from the repository's OWN counting authority.
     *
     * This used to be the literal `'1'`, so a long inbound message was
     * measured as one segment however many it actually occupied — the meter
     * under-reported exactly the traffic that costs most. `SMSCounter` is
     * what the rest of this codebase counts with; a second algorithm here
     * would be guaranteed to disagree with it eventually.
     */
    private static function segmentQuantityFor(?string $body): string
    {
        if ($body === null || $body === '') {
            return '1';
        }

        return (string) max(1, (int) (new SMSCounter())->count($body)->messages);
    }

    /**
     * Slice 3 §4.9 / audit P7 — the operation row and the customer-visible
     * Report are two representations of one fact, so a delivery callback
     * moves both.
     *
     * The correlation is the operation's own `report_id` foreign key, added
     * to this slice's still-unmerged migration. The alternative — matching
     * on (Business, phone number, roughly when) — is ambiguous the moment a
     * Business messages the same recipient twice, and guessing which of two
     * sends a callback belongs to is not something a billing record should
     * ever do.
     */
    private function syncCorrelatedReport(Reports $report, MessagingOperationStatus $target): void
    {
        $status = match ($target) {
            MessagingOperationStatus::Delivered => 'Delivered',
            MessagingOperationStatus::Failed => 'Failed',
            default => null,
        };

        if ($status === null) {
            return;
        }

        // Keyed by the Report this caller already resolved AND integrity
        // checked, never by a primary key taken straight off the operation
        // row: whereKey($operation->report_id) would mutate whatever sits at
        // that id, which is the whole point of the check upstream.
        Reports::query()
            ->whereKey($report->getKey())
            ->update([
                'status' => $status,
                'customer_status' => $status,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * A delivery-status payload that also carries Profile/number evidence is
     * cross-checked against the stored operation's own identity; evidence
     * resolving to a different Business is a conflict.
     */
    private function deliveryEvidenceAgrees(InboundWebhookEvent $event, object $operation): bool
    {
        $storedIdentityId = $operation->business_messaging_identity_id !== null
            ? (int) $operation->business_messaging_identity_id
            : null;

        if ($storedIdentityId === null) {
            return true;
        }

        foreach ([
            $this->resolver->resolveByMessagingProfileId($event->messagingProfileId),
            $this->resolver->resolveByPhoneNumber($event->destinationNumber),
        ] as $resolved) {
            if ($resolved instanceof BusinessMessagingIdentity && (int) $resolved->id !== $storedIdentityId) {
                return false;
            }
        }

        return true;
    }

    private static function targetStatus(?string $deliveryStatus): ?MessagingOperationStatus
    {
        if ($deliveryStatus === null) {
            return null;
        }

        return match (strtolower(trim($deliveryStatus))) {
            'delivered' => MessagingOperationStatus::Delivered,
            'failed', 'delivery_failed', 'undelivered' => MessagingOperationStatus::Failed,
            'sent', 'accepted' => MessagingOperationStatus::Accepted,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $key => $value) {
            $headers[$key] = is_array($value) ? ($value[0] ?? null) : $value;
        }

        return $headers;
    }
}
