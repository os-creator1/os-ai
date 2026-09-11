<?php

    namespace App\Http\Controllers\Customer;

    use App\Enums\Business\BusinessStatus;
    use App\Enums\Entitlement\PlatformFeature;
    use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
    use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
    use App\Http\Controllers\Controller;
    use App\Http\Requests\ChatBox\SentRequest;
    use App\Library\Entitlement\EntitlementManager;
    use App\Library\Navigation\CustomerContext;
    use App\Library\Tool;
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
    use Illuminate\Support\Facades\Auth;
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

            // The same query shape as before, so the thread's JSON — including
            // how `created_at` serialises for the client — is unchanged. Only
            // the box it is scoped to changed: resolved Business-first above.
            $messages = \DB::table('chat_box_messages')
                ->where('box_id', $box->id)
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data'   => $messages,
                'pinned' => $box->pinned ?? 0,
            ]);
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

            $owner = $this->owner($business);

            // The reply goes out from the conversation's own Business-side
            // number — `from` — to its external party, `to`. Never the other
            // way round.
            $sender_id = $box->from;

            if ($owner->customer->getOption('send_spam_message') == 'no') {
                $spamWords = SpamWord::whereRaw("LOWER(?) LIKE CONCAT('%', LOWER(word), '%')", [$request->input('message')])->get();
                if ($spamWords->isNotEmpty()) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Your message contains spam words.',
                    ]);
                }
            }

            $input = [
                'sender_id'    => $sender_id,
                'originator'   => 'phone_number',
                'sms_type'     => 'plain',
                'message'      => $request->message,
                'exist_c_code' => 'yes',
                'user'         => $owner,
                'user_id'      => $owner->id,
                'business_id'  => $business->id,
            ];

            // RFC-005 Milestone 5 §7 — unchanged, fail-closed: a missing or
            // invalid token never reaches quickSend() or the provider.
            if (! $request->filled('idempotency_token') || ! Str::isUuid($request->input('idempotency_token'))) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ], 422);
            }

            $input['idempotency_token'] = $request->input('idempotency_token');

            if ($request->hasFile('media_image')) {
                $v = Validator::make($request->all(), [
                    'media_image' => 'required|mimes:mp4,mov,ogg,qt,jpeg,png,jpg,gif,bmp,webp|max:20000',
                ]);

                if ($v->fails()) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => $v->errors()->first(),
                    ]);
                }

                $input['media_url'] = Tool::uploadImage($request->file('media_image'));
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

            if ($owner->customer->getOption('sender_id_verification') == 'yes') {
                $number = PhoneNumbers::where('business_id', $business->id)
                    ->where('number', $sender_id)
                    ->where('status', 'assigned')
                    ->first();

                if (! $number) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => $sender_id]),
                    ]);
                }

                if (! str_contains((string) $number->capabilities, 'sms')) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.sender_id.sender_id_sms_capabilities', ['sender_id' => $sender_id, 'type' => 'sms']),
                    ]);
                }

                $input['phone_number'] = $sender_id;
            }

            try {
                $phoneUtil         = PhoneNumberUtil::getInstance();
                $phoneNumberObject = $phoneUtil->parse('+' . ltrim((string) $box->to, '+'));
                $countryCode       = $phoneNumberObject->getCountryCode();
                $regionCode        = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);

                if (! $phoneUtil->isPossibleNumber($phoneNumberObject) || empty($countryCode) || empty($regionCode)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.customer.invalid_phone_number', ['phone' => $box->to]),
                    ]);
                }
            } catch (NumberParseException) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Invalid phone number parse',
                ]);
            }

            $input['country_code'] = $countryCode;
            $input['recipient']    = $phoneNumberObject->getNationalNumber();
            $input['region_code']  = $regionCode;

            $campaign->business_id = $business->id;

            $data = $this->campaigns->quickSend($campaign, $input, true);

            if (isset($data->getData()->status)) {
                if ($data->getData()->status == 'success') {
                    return response()->json([
                        'status'    => 'success',
                        'message'   => __('locale.campaigns.message_successfully_delivered'),
                        'media_url' => $data->getData()->data->media_url ?? null,
                    ]);
                }

                return response()->json([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
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
                    $query->where('notification', 0);
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

            if ($business === null || ! $this->workspaceManager->userCanAccessBusiness((int) Auth::id(), $business)) {
                return null;
            }

            // Defence in depth for view-as. The middleware already refuses a
            // Business route whose pair is not the viewed one; this states the
            // same rule where the Business is resolved, so a future change to
            // the middleware cannot quietly let an agency escape the client it
            // is viewing.
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
         */
        private function resolveBusinessChatBox(Business $business, string $uid): ?ChatBox
        {
            $box = ChatBox::query()
                ->where('uid', $uid)
                ->where('business_id', $business->id)
                ->first();

            // The Business is already resolved and authorised; set it on the
            // relation so callers never re-read it by a second route.
            $box?->setRelation('business', $business);

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
