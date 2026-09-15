<?php


    namespace App\Http\Controllers\API;

    use App\Enums\Automation\Workflow\ContactCreationSource;
    use App\Http\Controllers\Controller;
    use App\Library\Entitlement\CustomerAccountAccessGuard;
    use App\Models\Business;
    use App\Models\ContactGroups;
    use App\Models\Contacts;
    use App\Models\Traits\ApiResponser;
    use App\Models\User;
    use App\Repositories\Contracts\ContactsRepository;
    use App\Rules\Phone;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Validator;
    use Illuminate\Validation\Rule;

    /**
     * PR #302 correction 3, finding A. This legacy `/api/http` surface
     * authenticates via a request-supplied `api_token` looked up inside
     * each method, never through Sanctum/`$request->user()` — so no
     * middleware upstream of these controllers can truthfully resolve the
     * actor, let alone the target resource, ahead of time. The account-lock
     * check is therefore inline here, right after the token resolves a real
     * User (identity known) and, for every resource-addressed method,
     * before any write that method makes — mirroring exactly where the
     * pre-existing `can('developers')` check already sits.
     *
     * Reuses CustomerAccountAccessGuard — the same shared seam
     * CustomerAccountAccessApiGate (the Sanctum /api/v3 counterpart) and
     * the legacy web ContactsController now call too — never a second
     * policy.
     */
    class ContactsHTTPController extends Controller
    {
        use ApiResponser;

        /**
         * @var ContactsRepository $contactGroups
         */
        protected ContactsRepository $contactGroups;

        public function __construct(
            ContactsRepository $contactGroups,
            private readonly CustomerAccountAccessGuard $accessGuard,
        ) {
            $this->contactGroups = $contactGroups;
        }

        /**
         * PR #302 correction 3, finding A — the target ContactGroups' own
         * Business decides, never the actor's primary Business. Returns
         * the exact response to send back when locked, or null when the
         * request may proceed.
         */
        private function lockedResponseForBusiness(?int $businessId): ?JsonResponse
        {
            $business = $businessId !== null ? Business::find($businessId) : null;
            $decision = $this->accessGuard->decisionForBusiness($business);

            return $decision->isLocked() ? $this->accessGuard->jsonError($decision) : null;
        }

        /**
         * No target resource on this request (creating a brand-new contact
         * group) — the actor's own single deterministic Business decides,
         * failing closed rather than guessing when that is itself
         * ambiguous.
         */
        private function lockedResponseForActor(int $userId): ?JsonResponse
        {
            $decision = $this->accessGuard->decisionForActor($userId);

            if ($decision === null) {
                return $this->accessGuard->ambiguousJsonError();
            }

            return $decision->isLocked() ? $this->accessGuard->jsonError($decision) : null;
        }

        /**
         * invalid api endpoint request
         *
         * @return JsonResponse
         */
        public function contacts(): JsonResponse
        {
            return $this->error(__('locale.exceptions.invalid_action'), 403);
        }

        /*
        |--------------------------------------------------------------------------
        | contact module
        |--------------------------------------------------------------------------
        |
        |
        |
        */


        /**
         * store new contact
         *
         * @param ContactGroups $group_id
         * @param Request       $request
         *
         * @return JsonResponse
         */
        public function storeContact(ContactGroups $group_id, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $user = User::where('api_token', $request->input('api_token'))->first();
            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            if ($locked = $this->lockedResponseForBusiness($group_id->business_id)) {
                return $locked;
            }

            $validator = Validator::make($request->all(), [
                'PHONE' => ['required', new Phone($request->input('PHONE'))],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $validator->errors()->first(),
                ]);
            }

            $exist = Contacts::where('group_id', $group_id->id)->where('customer_id', $user->id)->where('phone', $request->input('phone'))->first();

            if ($exist) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.you_have_already_subscribed', ['contact_group' => $group_id->name]),
                ]);
            }

            [$validator, $subscriber] = $this->contactGroups->createContactFromRequest($group_id, $request->all(), ContactCreationSource::Api);

            if (is_null($subscriber)) {
                return $this->error($validator->errors()->first(), 422);
            }


            $output = $subscriber->only('uid', 'phone', 'status');

            $values = [];

            foreach ($group_id->getFields as $field) {
                if ($field->tag != 'PHONE') {
                    $values[$field->tag] = $subscriber->getValueByField($field);
                }
            }

            $output['custom_fields'] = $values;


            return $this->success($output, __('locale.contacts.contact_successfully_added'));
        }


        /**
         * view a contact
         *
         * @param ContactGroups $group_id
         * @param Contacts      $uid
         * @param Request       $request
         *
         * @return JsonResponse
         */
        public function searchContact(ContactGroups $group_id, Contacts $uid, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $user = User::where('api_token', $request->input('api_token'))->first();
            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            if ($user->tokenCan('view_contact')) {

                $subscriber = Contacts::where('group_id', $group_id->id)->where('uid', $uid->uid)->first();

                if ( ! $subscriber) {
                    return $this->error(__('locale.http.404.description'));
                }

                $output = $subscriber->only('uid', 'phone', 'status');

                $values = [];

                foreach ($group_id->getFields as $field) {
                    if ($field->tag != 'PHONE') {
                        $values[$field->tag] = $subscriber->getValueByField($field);
                    }
                }

                $output['custom_fields'] = $values;

                return $this->success($output, __('locale.contacts.contact_successfully_retrieved'));
            }

            return $this->error(__('locale.http.403.description'), 403);
        }

        /**
         * update a contact
         *
         * @param ContactGroups $group_id
         * @param Contacts      $uid
         * @param Request       $request
         *
         * @return JsonResponse
         */
        public function updateContact(ContactGroups $group_id, Contacts $uid, Request $request): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $user = User::where('api_token', $request->input('api_token'))->first();
            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            // PR #302 correction 4 — $uid is bound purely by its own uid,
            // independent of $group_id; nothing above this line has proven
            // it actually belongs to the routed group. updateFields() below
            // writes $uid directly, so a contact from a DIFFERENT group (in
            // a different Business entirely) must never reach it just
            // because the supplied group_id happens to be one this actor
            // can reach. Ordinary not-found semantics, checked before the
            // lock so a relationship mismatch never discloses either
            // Business's plan state.
            if ((int) $uid->group_id !== (int) $group_id->id) {
                return $this->error(__('locale.http.404.description'));
            }

            // A valid group_id relationship does not guarantee the two
            // independently-tracked business_id columns agree (Contacts'
            // own is a Pass 1, additive-only backfill). When they genuinely
            // disagree this is the same fail-closed, non-disclosing case
            // CustomerAccountAccessApiGate's own conflict detection applies
            // for /api/v3 -- neither candidate's plan state is evaluated or
            // returned. When they agree (or only one is set), that single
            // Business -- the one the row actually being written to
            // belongs to -- decides normally.
            $groupBusinessId = $group_id->business_id;
            $contactBusinessId = $uid->business_id;

            if ($groupBusinessId !== null && $contactBusinessId !== null && $groupBusinessId !== $contactBusinessId) {
                return $this->accessGuard->mismatchJsonError();
            }

            if ($locked = $this->lockedResponseForBusiness($contactBusinessId ?? $groupBusinessId)) {
                return $locked;
            }


            $validator = Validator::make($request->all(), [
                'phone' => ['required', new Phone($request->input('phone'))],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $validator->errors()->first(),
                ]);
            }


            $this->validate($request, $uid->getRules());

            $uid->updateFields($request->all());

            $output = $uid->only('uid', 'phone', 'status');

            $values = [];

            foreach ($group_id->getFields as $field) {
                if ($field->tag != 'PHONE') {
                    $values[$field->tag] = $uid->getValueByField($field);
                }
            }

            $output['custom_fields'] = $values;

            return $this->success($output, __('locale.contacts.contact_successfully_updated'));

        }

        /**
         * delete contact
         *
         * @param ContactGroups $group_id
         * @param Contacts      $uid
         * @param Request       $request
         *
         * @return JsonResponse
         */
        public function deleteContact(ContactGroups $group_id, Contacts $uid, Request $request): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $user = User::where('api_token', $request->input('api_token'))->first();
            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            // contactDestroy() below re-queries by group_id itself, so a
            // mismatched pair was never actually deletable -- but this
            // still refuses it explicitly and consistently with
            // updateContact() above, rather than leaving delete correct
            // only as a side effect of the repository's own query shape.
            if ($group_id->business_id !== null && $uid->business_id !== null && $group_id->business_id !== $uid->business_id) {
                return $this->accessGuard->mismatchJsonError();
            }

            if ($locked = $this->lockedResponseForBusiness($group_id->business_id)) {
                return $locked;
            }


            $status = $this->contactGroups->contactDestroy($group_id, $uid->uid);

            if ($status) {
                return $this->success(null, __('locale.contacts.contact_successfully_deleted'));
            }

            return $this->error(__('locale.exceptions.something_went_wrong'));
        }


        /**
         * get all contacts from a group
         *
         * @param ContactGroups $group_id
         * @param Request       $request
         *
         * @return JsonResponse
         */
        public function allContact(ContactGroups $group_id, Request $request): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $user = User::where('api_token', $request->input('api_token'))->first();
            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            $data = Contacts::where('group_id', $group_id->id)->select('uid', 'phone', 'first_name', 'last_name')->paginate(25);

            return $this->success($data);
        }


        /*
        |--------------------------------------------------------------------------
        | contact group module
        |--------------------------------------------------------------------------
        |
        |
        |
        */

        /**
         * view all contact groups
         *
         * @param Request $request
         *
         * @return JsonResponse
         */
        public function index(Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }
            $user = User::where('api_token', $request->input('api_token'))->first();
            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            $data = ContactGroups::where('customer_id', $user->id)->select('uid', 'name')->paginate(25);

            return $this->success($data);

        }


        /**
         * store contact group
         *
         *
         * @param Request $request
         *
         * @return JsonResponse
         */

        public function store(Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $user = User::where('api_token', $request->input('api_token'))->first();
            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            if ($locked = $this->lockedResponseForActor((int) $user->id)) {
                return $locked;
            }

            $customer_id      = $user->id;
            $name             = $request->input('name');
            $input            = $request->all();
            $input['user_id'] = $customer_id;

            $validator = Validator::make($request->all(), [
                'name' => ['required',
                    Rule::unique('contact_groups')->where(function ($query) use ($customer_id, $name) {
                        return $query->where('customer_id', $customer_id)->where('name', $name);
                    })],
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'status'  => 'error',
                    'message' => $validator->errors()->first(),
                ]);
            }

            $group = $this->contactGroups->store($input);

            return $this->success($group->select('name', 'uid')->find($group->id), __('locale.contacts.contact_group_successfully_added'));
        }


        /**
         * view a group
         *
         * @param ContactGroups $group_id
         * @param Request       $request
         *
         * @return JsonResponse
         */
        public function show(ContactGroups $group_id, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $user = User::where('api_token', $request->input('api_token'))->first();
            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            $data = ContactGroups::select('uid', 'name')->find($group_id->id);

            return $this->success($data);
        }


        /**
         * update contact group
         *
         * @param ContactGroups $contact
         * @param Request       $request
         *
         * @return JsonResponse
         */

        public function update(ContactGroups $contact, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $user = User::where('api_token', $request->input('api_token'))->first();

            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }


            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            if ($locked = $this->lockedResponseForBusiness($contact->business_id)) {
                return $locked;
            }


            $id          = $contact->id;
            $customer_id = $user->id;
            $name        = $request->input('name');

            $validator = Validator::make($request->all(), [
                'name' => ['required',
                    Rule::unique('contact_groups')->where(function ($query) use ($customer_id, $name) {
                        return $query->where('customer_id', $customer_id)->where('name', $name);
                    })->ignore($id)],
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'status'  => 'error',
                    'message' => $validator->errors()->first(),
                ]);
            }

            $group = $this->contactGroups->update($contact, $request->all());

            return $this->success($group->select('name', 'uid')->find($contact->id), __('locale.contacts.contact_group_successfully_updated'));

        }

        /**
         * delete contact group
         *
         * @param ContactGroups $contact
         * @param Request       $request
         *
         * @return JsonResponse
         */
        public function destroy(ContactGroups $contact, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $user = User::where('api_token', $request->input('api_token'))->first();

            if ( ! $user) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }


            if ( ! $user->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            if ($locked = $this->lockedResponseForBusiness($contact->business_id)) {
                return $locked;
            }


            $this->contactGroups->destroy($contact);

            return $this->success(null, __('locale.contacts.contact_group_successfully_deleted'));
        }

    }
