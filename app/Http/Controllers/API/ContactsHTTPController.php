<?php


    namespace App\Http\Controllers\API;

    use App\Http\Controllers\Controller;
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

    class ContactsHTTPController extends Controller
    {
        use ApiResponser;

        /**
         * @var ContactsRepository $contactGroups
         */
        protected ContactsRepository $contactGroups;

        public function __construct(ContactsRepository $contactGroups)
        {
            $this->contactGroups = $contactGroups;
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

            [$validator, $subscriber] = $this->contactGroups->createContactFromRequest($group_id, $request->all());

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


            $this->contactGroups->destroy($contact);

            return $this->success(null, __('locale.contacts.contact_group_successfully_deleted'));
        }

    }
