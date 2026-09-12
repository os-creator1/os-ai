<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\Contacts;
use App\Models\Country;
use App\Models\CustomerBasedSendingServer;
use App\Models\PhoneNumbers;
use App\Models\Senderid;
use App\Models\User;
use App\Repositories\Contracts\CampaignRepository;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * Automations V2 §10.1 — the Send SMS action.
 *
 * THE SEND PATH IS NOT NEW. This executor reaches the provider through exactly
 * one door, the same one B1 Outreach and B4 Automations use:
 *
 *     CampaignRepository::checkQuickSendValidation($sendData)
 *     CampaignRepository::quickSend($campaign, $sendData)
 *
 * with `business_id` on the payload and on a transient Campaigns instance, so
 * every Reports/TrackingLog row the core writes inherits the Business. There is
 * deliberately no Telnyx/Twilio call, no second metering path and no `sms_unit`
 * arithmetic here: whatever quickSend() does for managed delegation, BYO
 * dispatch, measurement and wallet accounting happens once, in its own code,
 * and this class inherits all of it (§10.1 "billing is inherited, not built").
 *
 * WHAT IS NEW is sender resolution, and only because v2 refuses to persist a
 * sender. B4 stores `sender_id` and `sending_server` in its action config, which
 * is how a channel id goes stale, or survives a Business reassignment and points
 * somewhere it should not. A v2 `send_sms` node stores one thing — the message
 * body (NodeTypeRegistry::validateSendSms) — and the sending path is resolved
 * HERE, at execution time, from the Business itself:
 *
 *   TRANSPORT   managed when BusinessMessagingIdentityResolver resolves an
 *               active identity for this Business, in which case no legacy
 *               sending server is required or passed — quickSend() already
 *               skips its legacy-gateway guards for a managed Business
 *               (Slice 3 §4.5/§4.7) and ManagedDispatchDelegate sends from the
 *               identity's own primary number. Otherwise the Business's active
 *               assigned BYO channel, whose underlying server must also be
 *               active.
 *   ORIGINATOR  the Business's own active SenderID, else its own assigned
 *               phone number with SMS capability. Both are Business-scoped
 *               columns, and checkQuickSendValidation() re-authorizes the value
 *               against this Business anyway, so a foreign originator cannot
 *               survive even if this resolution were wrong.
 *
 * Neither is ever read from the node config. A definition that carries a
 * `sender_id` or `sending_server` key — hand-written, or left over from a B4
 * import — is ignored outright rather than honoured.
 *
 * THE SLICE 6 HANDOFF. CX Slice 6 owns default-sender selection and its UI. It
 * had not merged when this slice was written, so §10.1's fallback applies:
 * carry the minimum, build no second sender-selection architecture, and record
 * the handoff. When Slice 6 lands, `resolveSendingPath()` is the one method that
 * should be replaced by its resolver — nothing else here knows how a sender is
 * chosen.
 *
 * Side-effect class External: an interrupted send is never re-run
 * (WorkflowRecoveryService), and the advancer's claim means duplicate delivery
 * of the same job cannot produce a second message.
 */
class SendSmsNodeExecutor implements NodeExecutor
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly BusinessMessagingIdentityResolver $identities,
    ) {
    }

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::SendSms;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];
        $body = is_string($config['body'] ?? null) ? trim((string) $config['body']) : '';

        if ($body === '') {
            return NodeExecutionOutcome::skipped('send_config_invalid');
        }

        // Consent at the ACTION boundary, never cached from claim time
        // (NodeExecutor rule 3 / Lane F §6.1). A contact who replied STOP
        // between enrollment and this instant must not be texted.
        if ($contact->status !== Contacts::STATUS_SUBSCRIBE) {
            return NodeExecutionOutcome::skipped('contact_unsubscribed');
        }

        $path = $this->resolveSendingPath($business);

        if ($path === null) {
            // Fail closed: no managed identity and no usable BYO channel, or no
            // originator this Business owns. Never guess, never borrow another
            // Business's sender.
            return NodeExecutionOutcome::failed('no_business_sending_path');
        }

        $phone = $this->resolvePhone($contact);

        if ($phone === null) {
            return NodeExecutionOutcome::skipped('contact_phone_invalid');
        }

        $sendData = [
            'business_id' => (int) $business->id,
            'user_id' => (int) $business->customer_id,
            'message' => $this->renderBody($body, $contact),
            'sms_type' => 'plain',
            'originator' => 'sender_id',
            'sender_id' => $path['originator'],
        ];

        // A managed Business has no legacy gateway, and passing one would send
        // quickSend() down its BYO branch. Only a BYO send carries this key.
        if ($path['sending_server'] !== null) {
            $sendData['sending_server'] = $path['sending_server'];
        }

        try {
            // The core authorizes the originator against THIS Business
            // (Senderid/PhoneNumbers scoped by business_id), so a sender that
            // belongs to another Business is refused here, at send time.
            $validation = $this->campaigns->checkQuickSendValidation($sendData)->getData();

            if (($validation->status ?? 'error') !== 'success') {
                return NodeExecutionOutcome::skipped('sender_rejected');
            }

            $sendData['sender_id'] = $validation->sender_id;
            $sendData['sms_type'] = $validation->sms_type;
            $sendData['user'] = User::query()->find($validation->user_id);
            $sendData['country_code'] = $phone['country_code'];
            $sendData['recipient'] = $phone['recipient'];
            $sendData['region_code'] = $phone['region_code'];

            $campaign = new Campaigns();
            $campaign->business_id = (int) $business->id;

            // THE provider call. Exactly one per claimed step, outside every
            // transaction — the advancer guarantees the second part, and this
            // method opens none of its own.
            $response = $this->campaigns->quickSend($campaign, $sendData)->getData();
        } catch (Throwable $exception) {
            // The outcome is unknown, so this is a failure and never a retry:
            // the step run is already claimed, and External steps are never
            // re-executed by recovery.
            return NodeExecutionOutcome::failed('send_exception: ' . class_basename($exception));
        }

        $status = $response->status ?? 'error';

        if (in_array($status, ['success', 'info'], true)) {
            return NodeExecutionOutcome::succeeded('Message sent to ' . $this->maskedPhone($contact));
        }

        return NodeExecutionOutcome::failed('send_failed');
    }

    /**
     * The Business's sending path, resolved now and never stored.
     *
     * @return array{originator: string, sending_server: int|null}|null null when
     *         this Business has no usable sending path at all
     */
    private function resolveSendingPath(Business $business): ?array
    {
        $originator = $this->resolveOriginator($business);

        if ($originator === null) {
            return null;
        }

        // 1. Managed sending identity, when one is active. quickSend() detects
        //    this itself and delegates; it must NOT be handed a legacy server.
        if ($this->identities->resolveForBusiness($business) !== null) {
            return ['originator' => $originator, 'sending_server' => null];
        }

        // 2. Otherwise the Business's own active assigned BYO channel, whose
        //    underlying sending server must itself still be active (B4 §7.A).
        $assignment = CustomerBasedSendingServer::query()
            ->where('business_id', (int) $business->id)
            ->where('status', 1)
            ->with('sendingServer')
            ->orderBy('id')
            ->get()
            ->first(fn ($row): bool => $row->sendingServer !== null && (bool) $row->sendingServer->status);

        if ($assignment === null) {
            // 3. Neither path exists: fail closed.
            return null;
        }

        return ['originator' => $originator, 'sending_server' => (int) $assignment->sending_server];
    }

    /**
     * The Business's own canonical originator: an active SenderID first, then an
     * assigned phone number that can carry SMS.
     *
     * Deterministic by id so the same Business always sends from the same
     * identity, rather than from whatever the database happened to return first.
     * Choosing BETWEEN several is a product decision that belongs to CX Slice 6,
     * not here — this is only enough to send at all.
     */
    private function resolveOriginator(Business $business): ?string
    {
        $senderId = Senderid::query()
            ->where('business_id', (int) $business->id)
            ->where('status', Senderid::STATUS_ACTIVE)
            ->orderBy('id')
            ->value('sender_id');

        if (is_string($senderId) && trim($senderId) !== '') {
            return $senderId;
        }

        $number = PhoneNumbers::query()
            ->where('business_id', (int) $business->id)
            ->where('status', 'assigned')
            ->orderBy('id')
            ->get(['number', 'capabilities'])
            ->first(fn ($row): bool => str_contains((string) $row->capabilities, 'sms'));

        return $number === null ? null : (string) $number->number;
    }

    /**
     * @return array{country_code: int, region_code: string, recipient: string}|null
     */
    private function resolvePhone(Contacts $contact): ?array
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $parsed = $util->parse('+' . preg_replace('/\D/', '', (string) $contact->phone));
            $regionCode = $util->getRegionCodeForNumber($parsed);
            $countryCode = $parsed->getCountryCode();

            if (! $util->isPossibleNumber($parsed) || empty($countryCode) || empty($regionCode)) {
                return null;
            }

            $national = $parsed->isItalianLeadingZero()
                ? '0' . $parsed->getNationalNumber()
                : (string) $parsed->getNationalNumber();

            // The country must be one the platform sends to at all; quickSend()
            // checks coverage itself, so this only avoids handing it a region it
            // cannot parse.
            if (! Country::query()->where('country_code', $countryCode)->where('iso_code', $regionCode)->exists()) {
                return null;
            }

            return [
                'country_code' => $countryCode,
                'region_code' => $regionCode,
                'recipient' => $national,
            ];
        } catch (NumberParseException) {
            return null;
        }
    }

    /**
     * B4's {TAG} substitution, unchanged: plain replacement from the contact's
     * own group fields, never template evaluation and never arbitrary code.
     */
    private function renderBody(string $body, Contacts $contact): string
    {
        $group = $contact->contactGroup;

        if ($group === null) {
            return $body;
        }

        $replacements = [];

        foreach ($group->getFields()->get() as $field) {
            $replacements['{' . $field->tag . '}'] = (string) $contact->getValueByField($field);
        }

        return strtr($body, $replacements);
    }

    private function maskedPhone(Contacts $contact): string
    {
        $digits = preg_replace('/\D/', '', (string) $contact->phone) ?? '';

        return strlen($digits) > 4 ? str_repeat('*', strlen($digits) - 4) . substr($digits, -4) : '****';
    }
}
