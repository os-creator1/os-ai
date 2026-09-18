<?php

    namespace App\Http\Controllers\Customer;

    use App\Enums\Business\BusinessStatus;
    use App\Enums\Entitlement\PlatformFeature;
    use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
    use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
    use App\Http\Controllers\Controller;
    use App\Http\Requests\ChatBox\SentRequest;
    use App\Library\Conversations\ConversationContextReader;
    use App\Library\Conversations\ConversationHistoryWriter;
    use App\Library\Conversations\ConversationSendFailureReason;
    use App\Library\Entitlement\EntitlementManager;
    use App\Library\Navigation\CustomerContext;
    use App\Library\Timeline\ContactActivityTimeline;
    use App\Library\Tool;
    use App\Library\Workspace\BusinessRouteAccess;
    use App\Library\Workspace\LocationAccessGuard;
    use App\Library\Workspace\WorkspaceManager;
    use App\Models\Blacklists;
    use App\Models\Business;
    use App\Models\Campaigns;
    use App\Models\ChatBox;
    use App\Models\ChatBoxMessage;
    use App\Models\Contacts;
    use App\Models\Country;
    use App\Models\CustomerBasedPricingPlan;
    use App\Models\CustomerBasedSendingServer;
    use App\Models\PhoneNumbers;
    use App\Models\PlansCoverageCountries;
    use App\Models\Senderid;
    use App\Models\SendingServer;
    use App\Models\SpamWord;
    use App\Models\Templates;
    use App\Models\User;
    use App\Models\Workspace;
    use App\Repositories\Contracts\CampaignRepository;
    use App\Repositories\Contracts\WorkspaceRepository;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Http\UploadedFile;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Facades\Validator;
    use Illuminate\Support\Str;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumberUtil;
    use Throwable;

    /**
     * Customer Experience Redesign Slice 2B — Business-scoped Conversations.
     *
     * The same inbox Ultimate SMS always had, now answering to a Business
     * instead of to a login. Every action resolves, in this order (§9):
     *
     *   Workspace (route) → Business inside that Workspace → the actor's
     *   access to that Business → the chat_box permission → the
     *   `conversations` entitlement → the ChatBox by uid AND business_id.
     *
     * A failure at any tenancy link — wrong Workspace, wrong Business, a
     * foreign or NULL-business conversation, a nonexistent uid, a numeric id
     * in place of a uid — is the same 404. They are indistinguishable on
     * purpose: a probe must learn nothing from which link refused it.
     *
     * TWO IDENTITIES, never confused (§6):
     *   - the HTTP actor, who is authenticated and whose permissions gate the
     *     request — possibly a staff member or an agency acting for a client;
     *   - the persistence owner, the Business's owning customer, which every
     *     legacy-shaped `user_id` column receives. A staff member's own id is
     *     never written as the owner of a conversation, a blacklist entry or
     *     a send.
     */
    class ChatBoxController extends Controller
    {
        public function __construct(
            private readonly CampaignRepository $campaigns,
            private readonly WorkspaceRepository $workspaceRepository,
            private readonly WorkspaceManager $workspaceManager,
            private readonly EntitlementManager $entitlementManager,
        ) {
        }

        // =================================================================
        // §8 — the two retained bare GET entry points, as redirectors only
        // =================================================================

        /**
         * GET /chat-box — never data-serving; decides where the actor goes.
         */
        public function legacyIndex(): RedirectResponse
        {
            return $this->redirectToConversations('customer.workspaces.businesses.conversations.index');
        }

        /**
         * GET /chat-box/new — same rule, landing on the compose screen.
         */
        public function legacyNew(): RedirectResponse
        {
            return $this->redirectToConversations('customer.workspaces.businesses.conversations.new');
        }

        /**
         * Zero accessible Businesses: the existing create-your-first-Business
         * destination. Exactly one: that Business — deterministic, because
         * there is only one candidate. More than one: the Account frame's own
         * chooser. Never a primary-Business guess.
         */
        private function redirectToConversations(string $targetRoute): RedirectResponse
        {
            $accessible = $this->accessibleBusinesses();

            if (count($accessible) === 0) {
                return redirect()->route('customer.onboarding.show');
            }

            if (count($accessible) === 1) {
                [$workspace, $business] = $accessible[0];

                return redirect()->route($targetRoute, [$workspace->uid, $business->uid]);
            }

            return redirect()->route('customer.workspaces.index');
        }

        // =================================================================
        // §7 — the canonical Business route family
        // =================================================================

        /**
         * @throws AuthorizationException
         */
        public function index(string $workspaceUid, string $businessUid): View|Factory|Application|JsonResponse
        {
            $resolved = $this->resolveBusiness($workspaceUid, $businessUid);

            if ($resolved === null) {
                return $this->notFound();
            }

            [, $business] = $resolved;

            $pageConfigs = [
                'pageHeader'    => false,
                'contentLayout' => 'content-left-sidebar',
                'pageClass'     => 'chat-application font-small-3',
            ];

            // latestMessage, not the full history: a preview needs one row.
            $pinnedChats = ChatBox::query()
                ->where('business_id', $business->id)
                ->where('pinned', true)
                ->with('latestMessage')
                ->orderBy('updated_at', 'desc')
                ->get();

            $templates = Templates::where('business_id', $business->id)->where('status', true)->get();

            return view('customer.ChatBox.index', [
                'pageConfigs'     => $pageConfigs,
                'templates'       => $templates,
                'pinnedChats'     => $pinnedChats,
                'displayNames'    => ChatBox::displayNamesFor($business, $pinnedChats),
                'workspaceUid'    => $workspaceUid,
                'businessUid'     => $businessUid,
            ]);
        }

        /**
         * @throws AuthorizationException
         */
        public function new(string $workspaceUid, string $businessUid): View|Factory|RedirectResponse|Application|JsonResponse
        {
            $resolved = $this->resolveBusiness($workspaceUid, $businessUid);

            if ($resolved === null) {
                return $this->notFound();
            }

            [, $business] = $resolved;

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid]), 'name' => __('locale.menu.Chat Box')],
                ['name' => __('locale.labels.new_conversion')],
            ];

            // Legacy Subscription/Plan/coverage stays customer-level by design
            // — read from the Business's owning customer, never the actor.
            $activeSubscription = $business->customer?->activeSubscription();

            if (! $activeSubscription) {
                return redirect()->route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid])->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $phone_numbers = PhoneNumbers::where('business_id', $business->id)->where('status', 'assigned')->cursor();

            $coverage = CustomerBasedPricingPlan::where('user_id', $business->customer_id)->where('status', true)->cursor();
            if ($coverage->count() < 1) {
                $coverage = PlansCoverageCountries::where('plan_id', $activeSubscription->plan_id)->where('status', true)->cursor();
            }

            $sendingServers = CustomerBasedSendingServer::where('business_id', $business->id)->where('status', 1)->get();
            $templates      = Templates::where('business_id', $business->id)->where('status', true)->get();

            // RFC-005 Milestone 5 §6.1 — unchanged: a genuinely new compose
            // gets a fresh idempotency token; a 'retain' retry redirect
            // carrying ?m5_retry_token=<uuid> reuses that exact token.
            $retryToken = request()->query('m5_retry_token');
            $idempotencyToken = (is_string($retryToken) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $retryToken) === 1)
                ? $retryToken
                : (string) Str::uuid();

            return view('customer.ChatBox.new', compact(
                'breadcrumbs', 'phone_numbers', 'coverage', 'sendingServers', 'templates', 'idempotencyToken', 'workspaceUid', 'businessUid'
            ));
        }

        /**
         * Start a new conversation.
         *
         * @throws AuthorizationException|NumberParseException
         */
        public function sent(Campaigns $campaign, SentRequest $request, string $workspaceUid, string $businessUid): RedirectResponse|JsonResponse
        {
            $resolved = $this->resolveBusiness($workspaceUid, $businessUid);

            if ($resolved === null) {
                return $this->notFound();
            }

            [, $business] = $resolved;

            $back = fn (string $message) => redirect()
                ->route('customer.workspaces.businesses.conversations.new', [$workspaceUid, $businessUid])
                ->with(['status' => 'error', 'message' => $message]);

            if (config('app.stage') === 'demo') {
                return $back(__('locale.demo_mode_not_available'));
            }

            $owner = $this->owner($business);

            $businessHasSendingServers = CustomerBasedSendingServer::where('business_id', $business->id)->where('status', 1)->exists();

            if ($businessHasSendingServers && ! isset($request->sending_server)) {
                return $back('Please select your sending server');
            }

            $input    = $request->except('_token');
            $senderId = $request->input('sender_id');
            $sms_type = $request->input('sms_type');
            $country  = Country::find($request->input('country_code'));

            if (! $country) {
                return $back("Permission to send an SMS has not been enabled for the region indicated by the 'To' number: " . $input['recipient']);
            }

            $phoneNumberUtil   = PhoneNumberUtil::getInstance();
            $phoneNumberObject = $phoneNumberUtil->parse('+' . $country->country_code . $request->input('recipient'));
            $countryCode       = $phoneNumberObject->getCountryCode();
            $regionCode        = $phoneNumberUtil->getRegionCodeForNumber($phoneNumberObject);

            if (! $phoneNumberUtil->isPossibleNumber($phoneNumberObject) || empty($countryCode) || empty($regionCode)) {
                return $back(__('locale.customer.invalid_phone_number', ['phone' => $country->country_code . $request->input('recipient')]));
            }

            $phone = $phoneNumberObject->isItalianLeadingZero()
                ? '0' . preg_replace("/^$countryCode/", '', $phoneNumberObject->getNationalNumber())
                : preg_replace("/^$countryCode/", '', $phoneNumberObject->getNationalNumber());

            if ($owner->customer->getOption('send_spam_message') == 'no') {
                $spamWords = SpamWord::whereRaw("LOWER(?) LIKE CONCAT('%', LOWER(word), '%')", [$request->input('message')])->get();
                if ($spamWords->isNotEmpty()) {
                    return $back('Your message contains spam words.');
                }
            }

            $activeSubscription = $owner->customer->activeSubscription();

            if (! $activeSubscription) {
                return $back(__('locale.customer.no_active_subscription'));
            }

            $coverage = CustomerBasedPricingPlan::where('user_id', $owner->id)
                ->where('status', true)
                ->with('sendingServer')
                ->first();

            if (! $coverage) {
                $coverage = PlansCoverageCountries::where('plan_id', $activeSubscription->plan_id)
                    ->where('status', true)
                    ->with('sendingServer')
                    ->first();
            }

            if (! $coverage) {
                return $back('Price Plan unavailable');
            }

            // §6 — a submitted sending server is accepted only when it is
            // positively assigned to THIS Business. It used to be
            // SendingServer::find() on the raw id: any server id the form
            // submitted was trusted.
            if (isset($request->sending_server)) {
                $assigned = CustomerBasedSendingServer::where('business_id', $business->id)
                    ->where('sending_server', $request->sending_server)
                    ->where('status', 1)
                    ->exists();

                $sendingServer = $assigned
                    ? SendingServer::where('status', true)->find($request->sending_server)
                    : null;
            } else {
                $sendingServer = $coverage->sendingServer;
            }

            if (! $sendingServer) {
                return $back(__('locale.campaigns.sending_server_not_available'));
            }

            $db_sms_type = $sms_type == 'unicode' ? 'plain' : $sms_type;

            if (! $sendingServer->{$db_sms_type}) {
                return $back(__('locale.sending_servers.sending_server_sms_capabilities', ['type' => strtoupper($db_sms_type)]));
            }

            if ($sendingServer->settings === 'Whatsender' || $sendingServer->type === 'whatsapp') {
                $input['sms_type'] = 'whatsapp';
            }

            $capabilities_type = ($sms_type === 'plain' || $sms_type === 'unicode') ? 'sms' : $sms_type;

            // §6 — the sender identity must be one of THIS Business's own:
            // an active Sender ID, or an assigned number with the right
            // capability. The same rule B1 enforces for Business-aware sends
            // (EloquentCampaignRepository::validateQuickSendOriginatorValue),
            // mirrored rather than re-derived. Another Business's number or
            // Sender ID is refused even if the actor could compose there too.
            $ownNumber = PhoneNumbers::where('business_id', $business->id)
                ->where('number', $senderId)
                ->where('status', 'assigned')
                ->first();

            if ($ownNumber) {
                if (! str_contains((string) $ownNumber->capabilities, $capabilities_type)) {
                    return $back(__('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $senderId, 'type' => $db_sms_type]));
                }

                // A Business number is a two-way identity: this is what makes
                // the send start a conversation at all.
                $input['originator']   = 'phone_number';
                $input['phone_number'] = $senderId;
            } else {
                $ownSenderId = Senderid::where('business_id', $business->id)
                    ->where('sender_id', $senderId)
                    ->where('status', 'active')
                    ->exists();

                if (! $ownSenderId || $owner->customer->getOption('sender_id_verification') === 'yes') {
                    // Verification demands a verified number; and a sender the
                    // Business does not own is never accepted either way.
                    return $back(__('locale.sender_id.sender_id_invalid', ['sender_id' => $senderId]));
                }

                $input['originator'] = 'sender_id';
            }

            // `sending_server` stays exactly as submitted — already proven
            // assigned to this Business above — and is left UNSET when the
            // plan's server is used. Forcing the plan server in here would
            // make quickSend() apply its Business-assignment rule to a server
            // that is not assigned but legitimately covers the plan, and
            // refuse every such send.
            $input['country_code']      = $countryCode;
            $input['recipient']         = $phone;
            $input['region_code']       = $regionCode;
            $input['reply_by_customer'] = true;

            // §6 — the owner, not the actor; and the explicit Business, which
            // quickSend() writes onto the conversation and re-verifies against
            // the actor (assertSuppliedTenancyIsAuthorized).
            $input['user']        = $owner;
            $input['user_id']     = $owner->id;
            $input['business_id'] = $business->id;

            // As B1 does: every Reports/TrackingLog row this send creates
            // inherits the explicit Business from the Campaigns instance.
            $campaign->business_id = $business->id;

            $data = $this->campaigns->quickSend($campaign, $input, true);

            if (isset($data->getData()->status)) {
                // RFC-005 Milestone 5 §6.1 — 'retain' returns to compose with
                // the same token so a retry resolves against the same open
                // reservation; 'clear' (or none) goes to the inbox.
                if (($data->getData()->m5_token_action ?? 'clear') === 'retain') {
                    return redirect()
                        ->route('customer.workspaces.businesses.conversations.new', [$workspaceUid, $businessUid, 'm5_retry_token' => $input['idempotency_token'] ?? null])
                        ->withInput()
                        ->with([
                            'status'  => $data->getData()->status,
                            'message' => $data->getData()->message,
                        ]);
                }

                return redirect()->route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid])->with([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
                ]);
            }

            return $back(__('locale.exceptions.something_went_wrong'));
        }

        /**
         * The opened conversation's full thread.
         *
         * @throws AuthorizationException
         */
        public function messages(string $workspaceUid, string $businessUid, string $uid): JsonResponse
        {
            $box = $this->resolveConversation($workspaceUid, $businessUid, $uid);

            if ($box === null) {
                return $this->notFound();
            }

            // The same rows, order and fields as before, so the thread's JSON —
            // including how `created_at` serialises for the client — is
            // unchanged. Only the box it is scoped to changed: resolved
            // Business-first above. The columns are named so the send-provenance
            // references later added for conversation history stay out of it.
            $messages = \DB::table('chat_box_messages')
                ->where('box_id', $box->id)
                ->orderBy('created_at', 'asc')
                ->get(['id', 'box_id', 'message', 'media_url', 'sms_type', 'send_by', 'sending_server_id', 'created_at', 'updated_at', 'direction']);

            return response()->json([
                'status' => 'success',
                'data'   => $messages,
                'pinned' => $box->pinned ?? 0,
            ]);
        }

        /**
         * The open conversation as ONE timeline for the person — messages,
         * automation outcomes, opt-outs — and the contact panel beside it.
         *
         * Both are rendered here, on the server, so no message text is ever
         * assembled into markup in the browser. The same §9 chain as every
         * other action resolves the conversation first; `messages` keeps
         * serving the raw thread unchanged.
         *
         * @throws AuthorizationException
         */
        public function timeline(ContactActivityTimeline $timeline, ConversationContextReader $contextReader, string $workspaceUid, string $businessUid, string $uid): JsonResponse
        {
            $box = $this->resolveConversation($workspaceUid, $businessUid, $uid);

            if ($box === null) {
                return $this->notFound();
            }

            $business = $box->business;

            // Correction round 5 (state machine), item 3 — a tracked bubble
            // stuck 'sending' (a retry whose request died before finishing)
            // must recover WITHOUT the customer needing to press Retry, and
            // without opening this screen ever calling a provider itself
            // (invariant 8 — this is a DB-only reconciliation pass, exactly
            // ManagedSendStateMachine::reconcileSendingRow() retry() itself
            // uses, under the same per-row lock). Runs BEFORE the timeline
            // is built, so a just-reconciled row renders its resolved state
            // on this very load rather than the stale 'Sending…' label.
            $this->reconcileSendingRowsBeforeTimeline($box, $business);

            // Slice 2B §10: a Contact only when exactly one of this Business's
            // contacts has the number. Everything contact-keyed hangs off it.
            $contact = $box->resolveDisplayContact($business);
            $context = $contextReader->read($business, $box, $contact);

            return response()->json([
                'status'   => 'success',
                'pinned'   => $box->pinned ?? 0,
                'title'    => $context->title(),
                'timeline' => view('customer.ChatBox.partials._timeline', [
                    'page' => $timeline->forConversation($business, $box, $contact),
                ])->render(),
                'context'  => view('customer.ChatBox.partials._context', [
                    'context'    => $context,
                    'profileUrl' => $context->hasContact() && Gate::allows('view_contact')
                        ? route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $context->contactUid])
                        : null,
                ])->render(),
            ]);
        }

        /**
         * Correction round 5 (state machine), item 3 — reconciles every
         * tracked row of THIS conversation currently 'sending', one row per
         * short transaction under its own row lock (mirroring retry()'s own
         * claim locking exactly, so this can never race a concurrent
         * retry() claim). DB-only: never calls a provider, never mints a
         * new operation key (invariant 8) — it can only ever resolve a row
         * from durable local evidence that already exists, or leave a
         * genuinely fresh/unresolved claim untouched.
         */
        private function reconcileSendingRowsBeforeTimeline(ChatBox $box, Business $business): void
        {
            $sendingIds = ChatBoxMessage::query()
                ->where('box_id', $box->id)
                ->where('send_status', \App\Library\Conversations\ManagedSendStateMachine::SENDING)
                ->pluck('id');

            foreach ($sendingIds as $id) {
                DB::transaction(function () use ($id, $business): void {
                    $locked = ChatBoxMessage::whereKey($id)->lockForUpdate()->first();

                    if ($locked === null) {
                        return;
                    }

                    \App\Library\Conversations\ManagedSendStateMachine::reconcileSendingRow($locked, (int) $business->id);
                });
            }
        }

        /**
         * The newest message and the unread count, for the live notifier.
         *
         * @throws AuthorizationException
         */
        public function messagesWithNotification(string $workspaceUid, string $businessUid, string $uid): JsonResponse
        {
            $box = $this->resolveConversation($workspaceUid, $businessUid, $uid);

            if ($box === null) {
                return $this->notFound();
            }

            $latest = ChatBoxMessage::where('box_id', $box->id)
                ->select('message', 'direction', 'media_url', 'box_id', 'created_at')
                ->latest()
                ->first();

            return response()->json([
                'status'       => 'success',
                'data'         => $latest?->toJson(),
                'notification' => $box->notification,
            ]);
        }

        /**
         * Reply inside an existing conversation.
         *
         * @throws AuthorizationException
         * @throws NumberParseException
         */
        public function reply(Campaigns $campaign, Request $request, string $workspaceUid, string $businessUid, string $uid): JsonResponse
        {
            $box = $this->resolveConversation($workspaceUid, $businessUid, $uid);

            if ($box === null) {
                return $this->notFound();
            }

            $business = $box->business;

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if (empty($request->message)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.campaigns.insert_your_message'),
                ]);
            }

            // RFC-005 Milestone 5 §7 — unchanged, fail-closed: a missing or
            // invalid token never reaches quickSend() or the provider.
            //
            // Conversations failed-send/retry (item 2) — this token doubles
            // as the stable identity of this message's own bubble for a
            // managed Business: one logical message, one uid, for its whole
            // lifetime across however many attempts it takes.
            if (! $request->filled('idempotency_token') || ! Str::isUuid($request->input('idempotency_token'))) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ], 422);
            }

            // Correction round 2, item 2 — derived immediately after the
            // token is validated and BEFORE buildManualSendInput(), so a
            // managed Business's safe pre-provider refusal (spam, an
            // unparseable destination…) still has the bubble's stable
            // identity available to record against.
            $sendUid = (string) $request->input('idempotency_token');

            $owner = $this->owner($business);

            [$input, $refusal] = $this->buildManualSendInput(
                $box,
                $business,
                $owner,
                (string) $request->message,
                $request->hasFile('media_image') ? $request->file('media_image') : null,
            );

            $isManaged = \App\Library\Messaging\ManagedDispatchDelegate::isManaged((int) $business->id);

            if ($refusal !== null) {
                $this->recordPreparationFailureIfManaged($box, $refusal, $sendUid, $input, $isManaged);

                return $refusal;
            }

            $input['idempotency_token'] = $sendUid;
            $input['send_uid'] = $sendUid;

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

            $campaign->business_id = $business->id;

            return $this->attemptManagedSend($box, $business, $campaign, $input, $sendUid, $isManaged);
        }

        /**
         * Conversations failed-send/retry (item 4) — a deliberate new
         * provider attempt for a message that is currently showing Failed or
         * Delivery failed, re-checking every gate a first send goes through:
         * Business authorization and tenancy (resolveConversation() below,
         * exactly as reply() uses), messaging readiness and current
         * funding/balance (buildManualSendInput() + quickSend(), run fresh —
         * never assumed from the original attempt).
         *
         * The ORIGINAL text/media is reused verbatim from the stored bubble,
         * never re-taken from the request, and the SAME logical bubble is
         * updated in place — never a second one, whether this attempt
         * succeeds or fails again.
         *
         * @throws Throwable when quickSend() itself throws something other
         *                   than the one managed-specific exception this
         *                   already handles — the failed bubble is recorded
         *                   first, so a "sending…" state is never left
         *                   stranded, and then the error still surfaces
         *                   exactly as it always has for this controller.
         */
        public function retry(Request $request, string $workspaceUid, string $businessUid, string $uid): JsonResponse
        {
            $box = $this->resolveConversation($workspaceUid, $businessUid, $uid);

            if ($box === null) {
                return $this->notFound();
            }

            $business = $box->business;

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $sendUid = (string) $request->input('send_uid');

            if ($sendUid === '' || ! Str::isUuid($sendUid)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ], 422);
            }

            // Business isolation (item 7 G) falls straight out of this
            // query: $box is already resolved to THIS Business above, so a
            // send_uid belonging to another Business's, another
            // conversation's, or another contact's message is simply not
            // found here — never a foreign bubble retried by guessing a uid.
            //
            // Correction round 3, item 3 — this is ALSO what decides
            // whether retry() applies at all, instead of an early
            // ManagedDispatchDelegate::isManaged() check. A legacy/BYO
            // message never carries a send_uid at all (recordManualSendFailure()
            // / recordManagedOutbound() are the only writers of that
            // column, and neither is ever reached for a non-managed send),
            // so it is structurally unreachable here regardless of the
            // Business's CURRENT managed status. A historical PR-301 bubble
            // stays reachable even if the Business's managed identity is
            // later archived — whether a fresh attempt can actually be MADE
            // is re-checked below, fresh, not assumed from history.
            $message = ChatBoxMessage::where('box_id', $box->id)->where('send_uid', $sendUid)->first();

            if ($message === null) {
                return $this->notFound();
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

                $locked = \App\Library\Conversations\ManagedSendStateMachine::reconcileSendingRow($locked, (int) $business->id);

                if (! \App\Library\Conversations\ManagedSendStateMachine::isRetryEligible($locked->send_status)) {
                    return ['claimed' => null, 'current' => $locked];
                }

                $locked->update([
                    'send_status' => \App\Library\Conversations\ManagedSendStateMachine::SENDING,
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

                if ($current !== null && \App\Library\Conversations\ManagedSendStateMachine::isTerminalSuccess($current->send_status)) {
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
                if ($current !== null && $current->send_status === \App\Library\Conversations\ManagedSendStateMachine::AMBIGUOUS) {
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
                $this->recordPreparationFailureIfManaged($box, $refusal, $sendUid, $input, true);

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

        // Correction round 3, item 4; round 5 (state machine) — the "is a
        // 'sending' row actually stuck, and what really happened to it"
        // decision now lives in ONE place,
        // App\Library\Conversations\ManagedSendStateMachine::reconcileSendingRow(),
        // used both by retry()'s own claim transaction above (under its own
        // row lock) and by timeline()'s read-path reconciliation below —
        // see that class's docblock for the full transition table.

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

        /**
         * @throws AuthorizationException
         */
        public function delete(string $workspaceUid, string $businessUid, string $uid): JsonResponse
        {
            $box = $this->resolveConversation($workspaceUid, $businessUid, $uid);

            if ($box === null) {
                return $this->notFound();
            }

            // Unchanged semantics: the box is removed together with its
            // messages. Only how the box is found changed.
            $messages = ChatBoxMessage::where('box_id', $box->id)->delete();

            if ($messages) {
                $box->delete();

                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.campaigns.sms_was_successfully_deleted'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        /**
         * §11 — block the conversation's EXTERNAL party.
         *
         * The number blocked is `to`, always: under the domain orientation it
         * is the counterparty whether the conversation began with the
         * Business's send or with the contact's message. The Business's own
         * `from` number is never a blacklist target.
         *
         * @throws AuthorizationException
         */
        public function block(string $workspaceUid, string $businessUid, string $uid): JsonResponse
        {
            $box = $this->resolveConversation($workspaceUid, $businessUid, $uid);

            if ($box === null) {
                return $this->notFound();
            }

            $business = $box->business;

            $owner = $this->owner($business);

            Blacklists::create([
                'business_id' => $business->id,
                // The legacy user_id column records the Business's persistence
                // owner — never the staff member who pressed the button.
                'user_id'     => $owner->id,
                'number'      => $box->to,
                'reason'      => 'Blacklisted by ' . Auth::user()->displayName(),
            ]);

            // Every same-Business Contact on this number — duplicates inside
            // one Business are ordinary cleanup, and leaving half of them
            // subscribed would be its own defect. A Contact in another
            // Business is never touched, whatever its number.
            Contacts::query()
                ->where('business_id', $business->id)
                ->where('phone', \App\Library\Business\Migration\ChatBoxBusinessBackfillV1::normalizeCounterparty((string) $box->to))
                ->update(['status' => 'unsubscribe']);

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.blacklist.blacklist_successfully_added'),
            ]);
        }

        /**
         * @throws Throwable
         * @throws AuthorizationException
         */
        public function loadChatUsers(Request $request, string $workspaceUid, string $businessUid)
        {
            $resolved = $this->resolveBusiness($workspaceUid, $businessUid);

            if ($resolved === null) {
                return $this->notFound();
            }

            [, $business] = $resolved;

            $filter = $request->get('filter', 'recents');
            $search = (string) $request->get('search', '');
            $page   = $request->get('page', 1);

            // business_id = the selected Business, and nothing wider. A
            // NULL-business legacy conversation never appears here.
            $query = ChatBox::query()->where('business_id', $business->id)->where('pinned', false);

            switch ($filter) {
                case 'unread':
                    $query->where('notification', '!=', 0);
                    break;
                case 'read':
                    // A conversation that was never marked unread holds NULL,
                    // not 0 (the column has no default) — it is read too.
                    // Grouped, so the OR can never escape the business_id filter.
                    $query->where(function ($q) {
                        $q->where('notification', 0)->orWhereNull('notification');
                    });
                    break;
                case 'recents':
                    $query->orderBy('updated_at', 'desc');
                    break;
            }

            if ($search !== '') {
                // Grouped, so the OR can never escape the business_id filter.
                $query->where(function ($q) use ($search) {
                    $q->where('from', 'LIKE', "%{$search}%")
                        ->orWhere('to', 'LIKE', "%{$search}%");
                });
            }

            $chat_box = $query->with('latestMessage')->paginate(50, ['*'], 'page', $page);

            return view('customer.ChatBox.partials._chat_list', [
                'chat_box'        => $chat_box,
                'displayNames'    => ChatBox::displayNamesFor($business, $chat_box->getCollection()),
            ])->render();
        }

        /**
         * @throws AuthorizationException
         */
        public function pin(string $workspaceUid, string $businessUid, string $uid): JsonResponse
        {
            $box = $this->resolveConversation($workspaceUid, $businessUid, $uid);

            if ($box === null) {
                return $this->notFound();
            }

            $box->update(['pinned' => ! $box->pinned]);

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.labels.added_to_pinned'),
            ]);
        }

        // =================================================================
        // §9 — the authorization chain
        // =================================================================

        /**
         * Workspace → Business inside it → the actor's access → chat_box →
         * `conversations` entitlement. Tenancy failures are 404; the
         * chat_box permission keeps its existing meaning (an actor-level
         * category gate).
         *
         * @return array{0: Workspace, 1: Business}|null null on any tenancy failure
         *
         * @throws AuthorizationException
         */
        private function resolveBusiness(string $workspaceUid, string $businessUid): ?array
        {
            $workspace = $this->workspaceRepository->findByUid($workspaceUid);

            if ($workspace === null || ! $workspace->is_active) {
                return null;
            }

            $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

            // The canonical Business-route decision: ordinary tenancy, or the
            // exact currently-valid View-As target. An Agency actor viewing a
            // managed Client cross-Workspace is deliberately NOT an ordinary
            // tenant of that Client Workspace, so asking tenancy alone here
            // refused the reply and retry that View As is meant to allow.
            // BusinessRouteAccess is the one place that rule lives — shared
            // with ResolvesBusinessTenancy and the inbox channel callback.
            if ($business === null || ! app(BusinessRouteAccess::class)->actorMayUseBusinessRoute(Auth::user(), $workspace, $business)) {
                return null;
            }

            // Defence in depth for view-as. The middleware already refuses a
            // Business route whose pair is not the viewed one, and the decision
            // above independently admits a session only for its exact target;
            // this states the same rule once more where the Business is
            // resolved, so a future change to either cannot quietly let an
            // agency escape the client it is viewing.
            $context = app()->bound(CustomerContext::class) ? app(CustomerContext::class) : null;

            if ($context?->viewAs !== null
                && ($context->viewAs->businessUid !== $businessUid || $context->viewAs->workspaceUid !== $workspaceUid)) {
                return null;
            }

            $this->authorize('chat_box');

            if ($business->status !== BusinessStatus::Active) {
                return null;
            }

            try {
                // (int) Auth::id() is the audit argument decide() requires —
                // never a tenancy decision.
                $decision = $this->entitlementManager->decide($workspace, $business, PlatformFeature::Conversations->value, (int) Auth::id());
            } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
                return null;
            }

            if (! $decision->allowed) {
                return null;
            }

            return [$workspace, $business];
        }

        /**
         * The conversation, by its public uid AND the selected Business.
         *
         * A foreign conversation, a NULL-business legacy one and a uid that
         * does not exist are all the same 404. A numeric primary key cannot
         * stand in for the uid: it is compared against `uid` only.
         *
         * Implementation Contract 08B — Location ACL. A conversation with a
         * proven `location_id` (Contract 06) is additionally re-checked
         * against LocationAccessGuard, re-derived from persistence, never
         * from a route/client-supplied value. A NULL `location_id` is never
         * guessed and never gates access on its own — the actor's own
         * Business-level access, already confirmed by resolveBusiness(),
         * governs exactly as it did before this contract. A denial here
         * folds into the same null-return, single 404 shape as every other
         * tenancy failure in this chain.
         */
        private function resolveBusinessChatBox(Business $business, string $uid): ?ChatBox
        {
            $box = ChatBox::query()
                ->where('uid', $uid)
                ->where('business_id', $business->id)
                ->first();

            if ($box === null) {
                return null;
            }

            // The Business is already resolved and authorised; set it on the
            // relation so callers never re-read it by a second route.
            $box->setRelation('business', $business);

            if ($box->location_id !== null) {
                $location = $box->location;

                if ($location === null || ! app(LocationAccessGuard::class)->userCanAccessLocation((int) Auth::id(), $location)) {
                    return null;
                }
            }

            return $box;
        }

        /**
         * The whole §9 chain for a single-record action. null on any tenancy
         * failure; the chat_box permission still throws, as it always has.
         *
         * @throws AuthorizationException
         */
        private function resolveConversation(string $workspaceUid, string $businessUid, string $uid): ?ChatBox
        {
            $resolved = $this->resolveBusiness($workspaceUid, $businessUid);

            if ($resolved === null) {
                return null;
            }

            return $this->resolveBusinessChatBox($resolved[1], $uid);
        }

        /**
         * One denial for every tenancy failure — wrong Workspace, wrong
         * Business, no access, feature not entitled, a foreign or NULL-business
         * conversation, a nonexistent uid. Byte-identical in every case.
         *
         * Returned rather than thrown for JSON clients on purpose: this
         * application's exception handler renders ANY exception as HTTP 200
         * for a wantsJson() request, which would turn a 404 into a success
         * status. A page request still aborts to the ordinary 404 page.
         */
        private function notFound(): JsonResponse
        {
            if (request()->wantsJson()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Chat box not found.',
                ], 404);
            }

            abort(404);
        }

        /**
         * The Business's persistence owner. `businesses.customer_id` holds the
         * owning User id (Business::customer() joins it to customers.user_id).
         */
        private function owner(Business $business): User
        {
            $owner = User::find($business->customer_id);

            if ($owner === null || $owner->customer === null) {
                abort(404);
            }

            return $owner;
        }

        /**
         * @return array<int, array{0: Workspace, 1: Business}>
         */
        private function accessibleBusinesses(): array
        {
            $userId     = (int) Auth::id();
            $accessible = [];

            foreach ($this->workspaceRepository->allForUser($userId) as $workspace) {
                foreach ($this->workspaceRepository->businessesForWorkspace($workspace) as $business) {
                    if ($this->workspaceManager->userCanAccessBusiness($userId, $business)) {
                        $accessible[] = [$workspace, $business];
                    }
                }
            }

            return $accessible;
        }
    }
