<?php

namespace App\Library\Conversations;

use App\Models\Business;
use App\Models\Campaigns;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\Contacts;
use App\Library\Business\Migration\ChatBoxBusinessBackfillV1;
use App\Models\CustomerBasedSendingServer;
use App\Models\PhoneNumbers;
use App\Models\SpamWord;
use App\Models\User;
use App\Library\Tool;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * The ONE manual-send path for a Conversation (reply + retry), shared by the desktop
 * CRM (ChatBoxController) and the mobile API (Mobile\MobileConversationController).
 *
 * This is the former private ChatBoxController logic moved verbatim; behaviour is
 * unchanged. Callers have ALREADY resolved the tenancy chain (Workspace, Business,
 * permission, entitlement, Location ACL) and loaded $box through it — this class
 * never authorizes, it sends. There is no second delivery engine: everything below
 * funnels into CampaignRepository::quickSend() and the managed dispatcher exactly as
 * the desktop always did, with its wallet, blacklist/STOP, readiness and
 * idempotency rules.
 *
 * Responses keep the desktop shape: {status, message[, media_url]}.
 */
class ConversationSender
{
    public function __construct(private readonly CampaignRepository $campaigns)
    {
    }

    /**
     * @param  string  $sendUid  the client's UUID: one logical message, one bubble, across retries
     */
    public function reply(ChatBox $box, string $message, ?UploadedFile $media, string $sendUid, bool $requireManaged = false): JsonResponse
    {
        $response = $this->performReply($box, $message, $media, $sendUid, $requireManaged);
        // Phones (including this user's other devices) see the new outgoing bubble live. Never throws; never pushes.
        app(\App\Library\Mobile\MobileConversationNotifier::class)->changed($box, \App\Events\Mobile\MobileConversationChanged::OUTBOUND);

        return $response;
    }

    private function performReply(ChatBox $box, string $message, ?UploadedFile $media, string $sendUid, bool $requireManaged): JsonResponse
    {
        $business = $box->business;

        $owner = $this->owner($business);

        [$input, $refusal] = $this->buildManualSendInput(
            $box,
            $business,
            $owner,
            $message,
            $media,
        );

        $isManaged = \App\Library\Messaging\ManagedDispatchDelegate::isManaged((int) $business->id);

        if ($refusal !== null) {
            $this->recordPreparationFailureIfManaged($box, $refusal, $sendUid, $input, $isManaged);

            return $refusal;
        }

        $input['idempotency_token'] = $sendUid;
        $input['send_uid'] = $sendUid;
        // Mobile sends require a durable operation key and tracked transport.
        // If the identity disappears after the mobile preflight, the transport
        // delegate MUST refuse instead of falling through to legacy SMS.
        if ($requireManaged) {
            $input['require_managed'] = true;
        }

        // Correction round 4, item 3 — the managed dispatch operation
        // key is scoped to THIS conversation, distinct from the raw
        // client 'idempotency_token' above (which two different
        // conversations of the same Business may legitimately share):
        // without box_id in the key, ManagedMessageDispatcher's own
        // idempotency (business_id, operation_key) could resolve a
        // SECOND conversation's send to the FIRST conversation's
        // already-accepted operation and report success without ever
        // sending to the second conversation's recipient.
        $input['managed_operation_key'] = 'conversation:' . $box->id . ':' . $sendUid . ':initial';

        // A thread on one of the agency's own Telnyx numbers (Outreach can use several) is
        // answered from THAT number, exactly as retry() already does — not from the default.
        if (\App\Library\AgencyOutreach\AgencyByoSender::channelForConversation($business, (int) $box->id) !== null) {
            $input['conversation_box_id'] = $box->id;
        }

        $campaign = new Campaigns();
        $campaign->business_id = $business->id;

        return $this->attemptManagedSend($box, $business, $campaign, $input, $sendUid, $isManaged);
    }

    /**
     * A deliberate new provider attempt for a Failed / Delivery-failed bubble. null = no such bubble in this conversation.
     */
    public function retry(ChatBox $box, string $sendUid): ?JsonResponse
    {
        $response = $this->performRetry($box, $sendUid);

        if ($response !== null) {
            app(\App\Library\Mobile\MobileConversationNotifier::class)->changed($box, \App\Events\Mobile\MobileConversationChanged::OUTBOUND);
        }

        return $response;
    }

    private function performRetry(ChatBox $box, string $sendUid): ?JsonResponse
    {
        $business = $box->business;

        $message = ChatBoxMessage::where('box_id', $box->id)->where('send_uid', $sendUid)->first();

        if ($message === null) {
            return null;
        }

        // The double-click / concurrent-retry guard (item 4, item 8 C):
        // only a message CURRENTLY in a retryable terminal state may
        // start a new attempt, and the claim — reading the row, checking
        // it, and moving it to the transient 'sending' state — is one
        // atomic transaction under a row lock. A second click that
        // arrives while the first is still inside this transaction
        // blocks on the lock, then sees 'sending' (not 'failed' or
        // 'delivery_failed') and is refused: it can never also pass the
        // claim and start a second provider attempt.
        //
        // Correction round 3, item 4; round 5 (state machine), item 3 —
        // a row already 'sending' is not simply refused any more:
        // ManagedSendStateMachine::reconcileSendingRow() first asks the
        // durable operation audit what actually happened to the attempt
        // that claimed it, so a request that died between the claim and
        // the provider call (or between provider acceptance and the
        // history write) does not leave the bubble stuck offering
        // neither Retry nor a truthful state forever. The SAME
        // reconciliation also runs from the conversation read path
        // (timeline()) so a stuck row recovers without the customer
        // needing to press Retry at all — see that method.
        $claim = DB::transaction(function () use ($message, $business) {
            $locked = ChatBoxMessage::whereKey($message->id)->lockForUpdate()->first();

            if ($locked === null) {
                return ['claimed' => null, 'current' => null];
            }

            $locked = ManagedSendStateMachine::reconcileSendingRow($locked, (int) $business->id);

            if (! ManagedSendStateMachine::isRetryEligible($locked->send_status)) {
                return ['claimed' => null, 'current' => $locked];
            }

            $locked->update([
                'send_status' => ManagedSendStateMachine::SENDING,
                'retry_count' => (int) $locked->retry_count + 1,
                // The claim liveness stamp ManagedSendStateMachine::reconcileSendingRow()
                // reads back (item 4) — never elapsed time on an
                // AMBIGUOUS provider outcome, only on "is this claim's
                // own request plausibly still running at all".
                'send_claimed_at' => now(),
            ]);

            return ['claimed' => $locked->fresh(), 'current' => null];
        });

        $claimed = $claim['claimed'];

        if ($claimed === null) {
            $current = $claim['current'];

            if ($current !== null && ManagedSendStateMachine::isTerminalSuccess($current->send_status)) {
                // Reconciliation found the provider HAD accepted it —
                // never resent, and reported as the success it is.
                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.campaigns.message_successfully_delivered'),
                ]);
            }

            // Correction round 4, item 1 — an 'ambiguous' bubble is
            // never claim-eligible (ManagedSendStateMachine::isRetryEligible()
            // is false for it), so it always lands here. Reported with
            // its own truthful, distinct refusal — never "already in
            // progress", which would wrongly suggest a later click
            // could succeed — whether this request found it already
            // 'ambiguous' or reconciliation just now turned a stuck
            // 'sending' claim into one.
            if ($current !== null && $current->send_status === ManagedSendStateMachine::AMBIGUOUS) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.conversations.retry_refused_ambiguous'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.conversations.retry_in_progress'),
            ]);
        }

        // Retry is a manual-send, managed-transport feature in this
        // slice (item 6). A PR-301 retry bubble is PERMANENTLY a
        // MANAGED-TRANSPORT message (correction round 4, item 2): once
        // created, it must never silently fall through to whatever
        // legacy/BYO sending server the Business happens to also have
        // just because the managed identity that created it was later
        // archived — quickSend() below would otherwise do exactly that,
        // since ManagedDispatchDelegate::attempt() returns null (not a
        // thrown exception) for "no managed identity at all" and every
        // legacy caller treats that null as "fall through to legacy".
        // Checked BEFORE buildManualSendInput() so a legacy-specific
        // preparation check (sender-id verification, in particular)
        // never even runs for this case either (item 5) — zero provider
        // calls of ANY kind, managed or legacy, and the claim already
        // made above is released back to a truthful 'failed' by the
        // same write reply()'s own MessagingIdentityConflictException
        // catch already makes for the narrower case where the identity
        // resolves but no usable number/destination does (still checked
        // fresh below, once this coarser gate passes).
        if (! \App\Library\Messaging\ManagedDispatchDelegate::isManaged((int) $business->id)) {
            $this->recordManagedSendFailure(
                $box,
                ['message' => $claimed->message, 'media_url' => $claimed->media_url, 'sms_type' => $claimed->sms_type ?? 'plain'],
                $sendUid,
                ConversationSendFailureReason::MessagingNotReady,
            );

            return response()->json([
                'status'  => 'error',
                'message' => ConversationSendFailureReason::MessagingNotReady->customerMessage(),
            ]);
        }

        $owner = $this->owner($business);

        [$input, $refusal] = $this->buildManualSendInput(
            $box,
            $business,
            $owner,
            (string) $claimed->message,
            null,
            $claimed->media_url !== null && $claimed->media_url !== '' ? $claimed->media_url : null,
        );

        if ($refusal !== null) {
            // Re-checking readiness itself refused (item 4) before any
            // provider call — the claimed 'sending' state goes straight
            // back to a truthful 'failed', under the same bubble. (No
            // media is re-validated on retry, so the client-input-error
            // branch can never actually apply here — recordPreparationFailureIfManaged()
            // is still used for consistency with reply()'s own path.)
            //
            // $trackHistory is TRUE, unconditionally (correction round
            // 4, item 5) — exactly like attemptManagedSend()'s own
            // $trackHistory, never re-derived from a fresh isManaged()
            // here either: this call only runs once the check above has
            // already proven the identity current and usable, but
            // stating it explicitly closes the same bug class
            // structurally rather than relying solely on that ordering.
            // A retry has ALREADY claimed an existing bubble as 'sending'.
            // Even an opted-out / client-input refusal must release that
            // claim. The initial-send path still skips creating a new bubble
            // for opt-out, but this path updates the SAME existing send_uid.
            $this->recordManagedSendFailure($box, $input, $sendUid, ConversationSendFailureReason::SendFailed);

            return $refusal;
        }

        // A FRESH, deterministic operation key every attempt — a
        // genuinely new provider send is never silently short-circuited
        // by the dispatcher's own same-key idempotency (§4.9) — while
        // 'send_uid' keeps this the SAME logical, customer-visible
        // bubble no matter how many attempts it takes. Conversation-
        // scoped exactly as reply()'s own initial key is (item 3):
        // 'idempotency_token' stays the M5-reservation-compatible
        // 'retry:{send_uid}:{n}' value it always was; the managed
        // dispatch key is the separate, box-scoped one.
        $input['idempotency_token'] = 'retry:' . $sendUid . ':' . $claimed->retry_count;
        $input['send_uid'] = $sendUid;
        $input['managed_operation_key'] = 'conversation:' . $box->id . ':' . $sendUid . ':retry:' . $claimed->retry_count;

        // Correction round 6, item 2 — history must attach to THIS
        // exact conversation, never one re-derived from the Business's
        // current primary managed number (which may have changed since
        // this bubble was first created).
        $input['conversation_box_id'] = $box->id;

        // Correction round 6, item 3 — this send must reach managed
        // transport or fail closed; ManagedDispatchDelegate::attempt()
        // enforces it at the actual dispatch seam, never relying solely
        // on the isManaged() precheck above (a race can archive the
        // identity in between).
        $input['require_managed'] = true;

        $campaign = new Campaigns();
        $campaign->business_id = $business->id;

        try {
            // retry() only ever operates on an already-tracked bubble
            // (found by its persisted send_uid), so history must be
            // recorded regardless of the Business's CURRENT live managed
            // status — unlike reply(), this is never re-derived here.
            return $this->attemptManagedSend($box, $business, $campaign, $input, $sendUid, true);
        } catch (Throwable $exception) {
            $this->recordManagedSendFailure(
                $box,
                ['message' => $claimed->message, 'media_url' => $claimed->media_url, 'sms_type' => $claimed->sms_type ?? 'plain'],
                $sendUid,
                ConversationSendFailureReason::SendFailed,
            );

            throw $exception;
        }
}

    /**
     * Everything reply() and retry() share to build quickSend()'s
     * $input: the spam filter, sender-id/sending-server rules, and the
     * destination's country/region.
     *
     * $mediaFile is a freshly uploaded file (reply()'s own upload,
     * validated and stored here exactly as before). $existingMediaUrl is
     * a retry's ALREADY-uploaded media, reused verbatim — never
     * re-validated or re-uploaded, since it was the first time.
     *
     * ALWAYS RETURNS THE BEST-KNOWN PARTIAL INPUT (correction round 3,
     * item 2), alongside a refusal when one of the checks below fails.
     * Media is validated and uploaded BEFORE the sender-id and
     * destination checks that follow it, so a LATER refusal — an
     * unparseable destination, a sender-id problem — must not lose an
     * attachment that already, genuinely, succeeded: the caller records
     * the failed bubble from this same partial input, media_url and
     * sms_type=mms included, so the customer sees what they actually
     * tried to send and a retry reuses that exact attachment rather
     * than silently downgrading to text-only.
     *
     * @return array{0: array<string, mixed>, 1: ?JsonResponse}
     */
    private function buildManualSendInput(
        ChatBox $box,
        Business $business,
        User $owner,
        string $message,
        ?UploadedFile $mediaFile,
        ?string $existingMediaUrl = null,
    ): array {
        // The reply goes out from the conversation's own Business-side
        // number — `from` — to its external party, `to`. Never the other
        // way round.
        $sender_id = $box->from;

        $input = [
            'sender_id'    => $sender_id,
            'originator'   => 'phone_number',
            'sms_type'     => 'plain',
            'message'      => $message,
            'exist_c_code' => 'yes',
            'user'         => $owner,
            'user_id'      => $owner->id,
            'business_id'  => $business->id,
        ];

        // Opt-out is a recipient-level sending guard, NOT a display-name
        // lookup. Several contacts in separate groups may share the same
        // Business-scoped number; displayContact deliberately returns null
        // in that case. Any unsubscribed matching contact still prevents SMS,
        // even if another group lists that number as subscribed and no
        // blacklist row exists. Initial refusals create no failed bubble.
        $recipient = ChatBoxBusinessBackfillV1::normalizeCounterparty((string) $box->to);
        $suppressed = $recipient !== '' && Contacts::query()
            ->where('business_id', (int) $business->id)
            ->where('phone', $recipient)
            ->where('status', Contacts::STATUS_UNSUBSCRIBE)
            ->exists();

        if ($suppressed) {
            return [$input, response()->json([
                'status'  => 'error',
                'message' => __('locale.conversations.contact_opted_out'),
                'client_input_error' => true,
                'reason'  => 'opted_out',
            ])];
        }

        if ($owner->customer->getOption('send_spam_message') == 'no') {
            $spamWords = SpamWord::whereRaw("LOWER(?) LIKE CONCAT('%', LOWER(word), '%')", [$message])->get();
            if ($spamWords->isNotEmpty()) {
                return [$input, response()->json([
                    'status'  => 'error',
                    'message' => 'Your message contains spam words.',
                ])];
            }
        }

        if ($mediaFile !== null) {
            $v = Validator::make(['media_image' => $mediaFile], [
                'media_image' => 'required|mimes:mp4,mov,ogg,qt,jpeg,png,jpg,gif,bmp,webp|max:20000',
            ]);

            if ($v->fails()) {
                // A malformed/oversized upload is a CLIENT-input problem
                // (correction round 2, item 2) — the same kind of thing
                // the empty-message and missing-token checks above are,
                // neither of which has ever created a bubble either.
                // It is not a fact about whether the MESSAGE could be
                // sent, so it is marked for the caller to skip recording
                // a failed bubble for, preserving existing validation
                // semantics exactly. Nothing was uploaded, so the
                // partial input carries no media of its own.
                return [$input, response()->json([
                    'status'  => 'error',
                    'message' => $v->errors()->first(),
                    'client_input_error' => true,
                ])];
            }

            $input['media_url'] = Tool::uploadImage($mediaFile);
            $input['sms_type']  = 'mms';
        } elseif ($existingMediaUrl !== null) {
            $input['media_url'] = $existingMediaUrl;
            $input['sms_type']  = 'mms';
        }

        // The conversation's own server is reused only when it is one the
        // Business is actually authorised to send through; otherwise the
        // plan's server is used. quickSend() applies the same assignment
        // rule to any explicit server alongside a Business, so passing an
        // unassigned one would simply be refused there.
        if ($box->sending_server_id) {
            $assigned = CustomerBasedSendingServer::where('business_id', $business->id)
                ->where('sending_server', $box->sending_server_id)
                ->where('status', 1)
                ->exists();

            if ($assigned) {
                $input['sending_server'] = $box->sending_server_id;
            }
        }

        // Sender verification proves the customer owns the number THEY chose
        // to send from, in `phone_numbers`. A managed Business chooses no
        // number: the managed dispatcher resolves the Business's own single
        // active primary number from the tenancy-verified Business itself
        // (Slice 3 §4.5), and a managed number is never a `phone_numbers`
        // row — so on every plan with verification on (the plan default)
        // this refused every managed reply before it could be sent. The
        // check stays exactly as it was for every other Business.
        if ($owner->customer->getOption('sender_id_verification') == 'yes'
            && ! \App\Library\Messaging\ManagedDispatchDelegate::isManaged((int) $business->id)) {
            $number = PhoneNumbers::where('business_id', $business->id)
                ->where('number', $sender_id)
                ->where('status', 'assigned')
                ->first();

            if (! $number) {
                return [$input, response()->json([
                    'status'  => 'error',
                    'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $sender_id]),
                ])];
            }

            if (! str_contains((string) $number->capabilities, 'sms')) {
                return [$input, response()->json([
                    'status'  => 'error',
                    'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $sender_id, 'type' => 'sms']),
                ])];
            }

            $input['phone_number'] = $sender_id;
        }

        try {
            $phoneUtil         = PhoneNumberUtil::getInstance();
            $phoneNumberObject = $phoneUtil->parse('+' . ltrim((string) $box->to, '+'));
            $countryCode       = $phoneNumberObject->getCountryCode();
            $regionCode        = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);

            if (! $phoneUtil->isPossibleNumber($phoneNumberObject) || empty($countryCode) || empty($regionCode)) {
                return [$input, response()->json([
                    'status'  => 'error',
                    'message' => __('locale.customer.invalid_phone_number', ['phone' => $box->to]),
                ])];
            }
        } catch (NumberParseException) {
            return [$input, response()->json([
                'status'  => 'error',
                'message' => 'Invalid phone number parse',
            ])];
        }

        $input['country_code'] = $countryCode;
        $input['recipient']    = $phoneNumberObject->getNationalNumber();
        $input['region_code']  = $regionCode;

        return [$input, null];
    }

    /**
     * Runs quickSend() and shapes its JSON response exactly as reply()
     * always has, with one addition (item 1): recording a truthful,
     * customer-safe failed bubble instead of letting the message
     * disappear, whenever $trackHistory says this send should be
     * tracked at all — no history write is ever attempted when it is
     * false (item 8 H).
     *
     * $trackHistory is EXPLICIT, not re-derived from
     * ManagedDispatchDelegate::isManaged() here (correction round 3,
     * item 3). A first send (reply()) asks isManaged() fresh, exactly
     * as before — a genuinely non-managed Business gets no tracking at
     * all. A retry() is always tracking an ALREADY-tracked bubble by
     * construction (retry() only ever finds one by its persisted
     * send_uid), so it always passes true: a Business whose managed
     * identity was archived AFTER that bubble was created must still
     * have this attempt's outcome recorded onto it, or the bubble is
     * left stranded in the transient 'sending' state retry() just
     * claimed it into, offering neither Retry nor a truthful failure
     * ever again.
     */
    private function attemptManagedSend(ChatBox $box, Business $business, Campaigns $campaign, array $input, string $sendUid, bool $trackHistory): JsonResponse
    {
        $managed = $trackHistory;

        try {
            $data = $this->campaigns->quickSend($campaign, $input, true);
        } catch (\App\Library\Messaging\Exceptions\ByoSenderNotReadyException $e) {
            $byoReason = ConversationSendFailureReason::forByoCode($e->reasonCode);

            if ($managed) {
                $this->recordManagedSendFailure($box, $input, $sendUid, $byoReason);
            }

            return response()->json([
                'status'  => 'error',
                'message' => $byoReason->customerMessage(),
            ]);
        } catch (\App\Library\Messaging\Exceptions\MessagingIdentityConflictException) {
            // Zero provider calls (Slice 3 §4.5) — a Class A refusal
            // before any commitment (item 1), always safe to offer a
            // deliberate retry for.
            if ($managed) {
                $this->recordManagedSendFailure($box, $input, $sendUid, ConversationSendFailureReason::MessagingNotReady);
            }

            return response()->json([
                'status'  => 'error',
                'message' => ConversationSendFailureReason::MessagingNotReady->customerMessage(),
            ]);
        } catch (\App\Library\Messaging\Exceptions\MessagingInsufficientFundsException) {
            // Canonical per-segment billing (ManagedTransportBilling): the
            // wallet refused BEFORE any provider contact — a conclusive,
            // safely retryable refusal once the wallet is funded.
            if ($managed) {
                $this->recordManagedSendFailure($box, $input, $sendUid, ConversationSendFailureReason::InsufficientBalance);
            }

            return response()->json([
                'status'  => 'error',
                'message' => ConversationSendFailureReason::InsufficientBalance->customerMessage(),
            ]);
        } catch (\App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException) {
            // §4.4 — the platform kill switch is off, or managed
            // messaging is otherwise unconfigured. Thrown BEFORE the
            // adapter is even resolved (ManagedMessageDispatcher::dispatch()'s
            // very first check), so this is also zero provider calls —
            // a platform-side readiness problem, not the customer's,
            // and retryable the moment availability is restored.
            if ($managed) {
                $this->recordManagedSendFailure($box, $input, $sendUid, ConversationSendFailureReason::MessagingUnavailable);
            }

            return response()->json([
                'status'  => 'error',
                'message' => ConversationSendFailureReason::MessagingUnavailable->customerMessage(),
            ]);
        }

        $payload = $data->getData();

        if (! isset($payload->status)) {
            if ($managed) {
                $this->recordManagedSendFailure($box, $input, $sendUid, ConversationSendFailureReason::SendFailed);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        if ($payload->status === 'success') {
            return response()->json([
                'status'    => 'success',
                'message'   => __('locale.campaigns.message_successfully_delivered'),
                'media_url' => $payload->data->media_url ?? null,
            ]);
        }

        if ($managed) {
            // 'managed' === true means the dispatcher itself reached
            // provider dispatch and was rejected — its own coarse
            // category (never a provider payload or id) maps to a more
            // specific reason than this seam could otherwise derive.
            // Everything else never reached the dispatcher at all
            // (coverage, blacklist, the legacy balance check, spam, an
            // unparsable destination), so only the generic classifier
            // has anything to go on.
            $dispatcherRejected = ($payload->managed ?? false) === true;

            $reason = $dispatcherRejected
                ? ConversationSendFailureReason::fromProviderErrorCategory(
                    isset($payload->error_category) ? \App\Enums\Messaging\ProviderErrorCategory::tryFrom((string) $payload->error_category) : null,
                    \App\Library\AgencyOutreach\AgencyByoSender::channelForConversation($business, (int) $box->id) !== null,
                )
                : $this->classifyEarlyRefusal((string) $payload->message);

            $this->recordManagedSendFailure($box, $input, $sendUid, $reason);

            if ($dispatcherRejected) {
                // Correction round 4, item 1 — the reason's OWN customer
                // message, not the dispatcher's flatly generic
                // 'campaign_sending_failed' text (identical for every
                // category today): an Ambiguous outcome in particular
                // must never read as a plain, retryable failure. An
                // EARLY refusal (below) already carries its own
                // specific, customer-safe text — coverage, blacklist,
                // balance — and classifyEarlyRefusal() exists only to
                // pick a bubble reason CODE for it, never to replace
                // that text.
                return response()->json([
                    'status'  => $payload->status,
                    'message' => $reason->customerMessage(),
                ]);
            }
        }

        return response()->json([
            'status'  => $payload->status,
            'message' => $payload->message,
        ]);
    }

    /**
     * The one early-return legacy refusal specific enough to name
     * (insufficient balance, matched against the ACTIVE locale's own
     * rendered message — never a hardcoded English string) — everything
     * else quickSend() can refuse a managed Business's send for before
     * ever reaching the dispatcher is reported generically (item 3):
     * nothing more specific is genuinely known about it here.
     */
    private function classifyEarlyRefusal(string $message): ConversationSendFailureReason
    {
        $template = __('locale.campaigns.not_enough_balance', ['current_balance' => '__CB__', 'campaign_price' => '__CP__']);
        $prefix = Str::before($template, '__CB__');

        if ($prefix !== '' && $prefix !== $template && str_starts_with($message, $prefix)) {
            return ConversationSendFailureReason::InsufficientBalance;
        }

        return ConversationSendFailureReason::SendFailed;
    }

    /**
     * Correction round 2, item 2 — buildManualSendInput() refusing a
     * managed Business's send during PREPARATION (spam, an unparseable
     * destination, sender-id checks — all before quickSend() and
     * therefore before any provider call) must show the same truthful
     * failed bubble a provider-level refusal already does; it was
     * silently disappearing instead, exactly like the bug item 1
     * originally fixed for the provider-reached case.
     *
     * SKIPPED when $trackHistory is false (untouched, item 8 H) and for
     * $refusal's own 'client_input_error' marker — the media-upload
     * validation branch is a pure client-input problem (a malformed or
     * oversized file), not a fact about whether the message could be
     * sent, exactly like the empty-message/missing-token checks that
     * have never created a bubble either.
     *
     * $trackHistory is EXPLICIT, not re-derived from
     * ManagedDispatchDelegate::isManaged() here (correction round 4,
     * item 5 — the exact same bug class attemptManagedSend()'s own
     * $trackHistory closed in round 3). reply() passes a freshly
     * computed isManaged() result; retry() always passes true, since by
     * the time it reaches this call it has already passed its own
     * upfront isManaged() gate (item 2) — re-deriving it here a second
     * time, after buildManualSendInput() may have run with a
     * Business's managed identity that was archived MID-REQUEST, is
     * exactly the stale-recheck pattern that previously left a claimed
     * 'sending' bubble stranded forever.
     */
    private function recordPreparationFailureIfManaged(
        ChatBox $box,
        JsonResponse $refusal,
        string $sendUid,
        array $attempted,
        bool $trackHistory,
    ): void {
        if (! $trackHistory) {
            return;
        }

        $payload = $refusal->getData();

        if (($payload->client_input_error ?? false) === true) {
            return;
        }

        $this->recordManagedSendFailure($box, $attempted, $sendUid, ConversationSendFailureReason::SendFailed);
    }

    /**
     * Never lets a bookkeeping failure of ITS OWN become customer-visible
     * (item 1 Class B, applied consistently to failure history too): a
     * send that genuinely failed is still reported as failed to the
     * caller even when recording that fact does not itself succeed.
     */
    private function recordManagedSendFailure(ChatBox $box, array $input, string $sendUid, ConversationSendFailureReason $reason): void
    {
        try {
            app(ConversationHistoryWriter::class)->recordManualSendFailure(
                $box->business,
                $box,
                (string) ($input['message'] ?? ''),
                isset($input['media_url']) && $input['media_url'] !== null && $input['media_url'] !== ''
                    ? [(string) $input['media_url']]
                    : [],
                (string) ($input['sms_type'] ?? 'plain'),
                $sendUid,
                $reason->value,
            );
        } catch (Throwable $exception) {
            Log::error('conversation_send_failure_not_recorded', [
                'business_id' => (int) $box->business_id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function owner(Business $business): User
    {
        $owner = User::find($business->customer_id);

        if ($owner === null || $owner->customer === null) {
            abort(404);
        }

        return $owner;
    }
}
