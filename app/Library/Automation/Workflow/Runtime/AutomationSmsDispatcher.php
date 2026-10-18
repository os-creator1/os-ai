<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\BusinessSmsSendingPath;
use App\Library\Messaging\DTO\LocationSendContext;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
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
 * Automations — the ONE way a workflow step sends a text.
 *
 * Send SMS, Send booking link, Send form and Send questionnaire all send through
 * here, so the rules below are written once and cannot drift between actions.
 *
 * THE SEND PATH IS NOT NEW. It reaches the provider through exactly one door, the
 * same one B1 Outreach and B4 Automations use:
 *
 *     CampaignRepository::checkQuickSendValidation($sendData)
 *     CampaignRepository::quickSend($campaign, $sendData)
 *
 * with `business_id` on the payload and on a transient Campaigns instance, so every
 * Reports/TrackingLog row the core writes inherits the Business. There is
 * deliberately no Telnyx/Twilio call, no second metering path and no `sms_unit`
 * arithmetic here: managed delegation, BYO dispatch, measurement, wallet
 * accounting and opt-out handling all happen once, in the core, and this class
 * inherits all of it.
 *
 * SENDER RESOLUTION happens HERE, at execution time, from the Business itself —
 * a sender is never read from node config (a definition that carries a
 * `sender_id` or `sending_server` key has it ignored):
 *
 *   TRANSPORT   managed when BusinessMessagingIdentityResolver resolves an active
 *               identity for this Business, in which case no legacy sending
 *               server is passed; otherwise the Business's active assigned BYO
 *               channel, whose underlying server must also be active.
 *   ORIGINATOR  the Business's own active SenderID, else its own assigned phone
 *               number with SMS capability.
 *
 * LOCATION. A journey is pinned to the ONE Location of its triggering fact. The
 * send must provably speak for it, so:
 *
 *   - a journey whose version is limited to Locations never texts a Contact who has
 *     left the pinned Location (PinnedRunLocation);
 *   - a MANAGED number is Location-aware: the Text messaging settings say which
 *     Locations it is used by (business_messaging_number_locations), and the
 *     dispatcher refuses a send the number cannot be shown to own
 *     (BusinessMessagingIdentityResolver::numberServes). It is checked here first so
 *     the step fails with a plain reason instead of an exception;
 *   - a BYO sender has no Location assignment, so it serves a Business of one
 *     Location, and a Business-wide workflow, but never a Location-limited workflow
 *     of a Business with several Locations.
 *
 * A Business-wide workflow of a Business that has never assigned its number keeps
 * working exactly as before.
 *
 * Side-effect class External for every caller: an interrupted send is never re-run
 * (WorkflowRecoveryService), and the advancer's claim means duplicate delivery of
 * the same job cannot produce a second message.
 */
class AutomationSmsDispatcher
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly AutomationSendContext $sendContext,
        private readonly BusinessSmsSendingPath $paths,
    ) {
    }

    /**
     * Send one text to the journey's Contact.
     *
     * @param string $message the already-rendered text
     */
    public function send(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
        string $message,
    ): NodeExecutionOutcome {
        // A contact who has left a Location-limited journey's Location is never
        // texted under it, and the action never uses the Contact's NEW Location.
        $violation = PinnedRunLocation::violation($enrollment, $contact);

        if ($violation !== null) {
            return NodeExecutionOutcome::skipped($violation);
        }

        // Consent at the ACTION boundary, never cached from claim time
        // (NodeExecutor rule 3 / Lane F §6.1). A contact who replied STOP between
        // enrollment and this instant must not be texted.
        if ($contact->status !== Contacts::STATUS_SUBSCRIBE) {
            return NodeExecutionOutcome::skipped('contact_unsubscribed');
        }

        $context = new LocationSendContext(
            PinnedRunLocation::pinned($enrollment),
            PinnedRunLocation::scopeOf($enrollment)->isBound(),
        );

        $path = $this->resolveSendingPath($business, $context);

        if (is_string($path)) {
            // Fail closed with a plain reason: no usable path, or no sender that can
            // be shown to belong to this journey's Location. Never guess, never
            // borrow another Business's — or another Location's — sender.
            return NodeExecutionOutcome::failed($path);
        }

        $phone = $this->resolvePhone($contact);

        if ($phone === null) {
            return NodeExecutionOutcome::skipped('contact_phone_invalid');
        }

        $sendData = [
            'business_id' => (int) $business->id,
            'user_id' => (int) $business->customer_id,
            'message' => $message,
            'sms_type' => 'plain',
            'originator' => 'sender_id',
            'sender_id' => $path['originator'],
            // The Location this text must provably speak for. Only the managed
            // transport reads it; every other caller of the core passes none.
            'location_send_context' => $context,
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
            // transaction — the advancer guarantees the second part, and this method
            // opens none of its own.
            //
            // Made inside this step run's send scope, so whichever transport branch
            // writes the Reports row stamps it with the step run. That durable mark
            // is what lets a reply to this message be recognised as a reply to
            // automation output, and stops this workflow re-triggering off its own
            // send (T-WF-25).
            $send = fn () => $this->campaigns->quickSend($campaign, $sendData);
            $stepRunId = ClaimedStepRun::idFor($node, $enrollment);

            $response = ($stepRunId === null ? $send() : $this->sendContext->during($stepRunId, $send))->getData();
        } catch (Throwable $exception) {
            // The outcome is unknown, so this is a failure and never a retry: the step
            // run is already claimed, and External steps are never re-executed by
            // recovery.
            return NodeExecutionOutcome::failed('send_exception: ' . class_basename($exception));
        }

        $status = $response->status ?? 'error';

        if (in_array($status, ['success', 'info'], true)) {
            return NodeExecutionOutcome::succeeded('Message sent to ' . $this->maskedPhone($contact));
        }

        return NodeExecutionOutcome::failed('send_failed');
    }

    /**
     * The Business's sending path for this journey's Location, resolved now and
     * never stored — or the plain reason there is none.
     *
     * @return array{originator: string, sending_server: int|null}|string
     */
    private function resolveSendingPath(Business $business, LocationSendContext $context): array|string
    {
        // The Location-aware rule now lives in the shared BusinessSmsSendingPath
        // (Calendar booking notifications use the very same one).
        return $this->paths->resolveForLocation($business, $context);
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

    private function maskedPhone(Contacts $contact): string
    {
        $digits = preg_replace('/\D/', '', (string) $contact->phone) ?? '';

        return strlen($digits) > 4 ? str_repeat('*', strlen($digits) - 4) . substr($digits, -4) : '****';
    }
}
