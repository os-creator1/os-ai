<?php


    namespace App\Http\Controllers\API;

    use App\Http\Requests\Contacts\NewContactGroup;
    use App\Http\Requests\Contacts\StoreContact;
    use App\Http\Requests\Contacts\UpdateContactGroup;
    use App\Models\ContactGroups;
    use App\Models\Contacts;
    use App\Models\Traits\ApiResponser;
    use App\Http\Controllers\Controller;
    use App\Repositories\Contracts\ContactsRepository;
    use Illuminate\Http\JsonResponse;

    class ContactsController extends Controller
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
         * @param StoreContact  $request
         *
         * @return JsonResponse
         */
        public function storeContact(ContactGroups $group_id, StoreContact $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            if ( ! request()->user()->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            $exist = Contacts::where('group_id', $group_id->id)->where('phone', $request->input('phone'))->first();

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

            foreach ($group_id->getFields as $key => $field) {
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
         *
         * @return JsonResponse
         */
        public function searchContact(ContactGroups $group_id, Contacts $uid): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            if ( ! request()->user()->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            if (request()->user()->tokenCan('view_contact')) {

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
         * @param StoreContact  $request
         *
         * @return JsonResponse
         */
        public function updateContact(ContactGroups $group_id, Contacts $uid, StoreContact $request): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! request()->user()->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
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
         *
         * @return JsonResponse
         */
        public function deleteContact(ContactGroups $group_id, Contacts $uid): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! request()->user()->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            if (request()->user()->tokenCan('delete_contact')) {

                $status = $this->contactGroups->contactDestroy($group_id, $uid->uid);

                if ($status) {
                    return $this->success(null, __('locale.contacts.contact_successfully_deleted'));
                }

                return $this->error(__('locale.exceptions.something_went_wrong'));
            }

            return $this->error(__('locale.http.403.description'), 403);
        }


        /**
         * get all contacts from a group
         *
         * @param ContactGroups $group_id
         *
         * @return JsonResponse
         */
        public function allContact(ContactGroups $group_id): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! request()->user()->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            if (request()->user()->tokenCan('view_contact')) {
                $data = Contacts::where('group_id', $group_id->id)->select('uid', 'phone', 'first_name', 'last_name')->paginate(25);

                return $this->success($data);
            }

            return $this->error(__('locale.http.403.description'), 403);
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
         * @return JsonResponse
         */
        public function index(): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! request()->user()->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            if (request()->user()->tokenCan('view_contact_group')) {

                $data = ContactGroups::where('customer_id', request()->user()->id)->select('uid', 'name')->paginate(25);

                return $this->success($data);
            }

            return $this->error(__('locale.http.403.description'), 403);
        }


        /**
         * store contact group
         *
         * @param NewContactGroup $request
         *
         * @return JsonResponse
         */

        public function store(NewContactGroup $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! request()->user()->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            $group = $this->contactGroups->store($request->input());

            return $this->success($group->select('name', 'uid')->find($group->id), __('locale.contacts.contact_group_successfully_added'));

        }


        /**
         * view a group
         *
         * @param ContactGroups $group_id
         *
         * @return JsonResponse
         */
        public function show(ContactGroups $group_id): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! request()->user()->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            if (request()->user()->tokenCan('view_contact_group')) {
                $data = ContactGroups::select('uid', 'name')->find($group_id->id);

                return $this->success($data);
            }

            return $this->error(__('locale.http.403.description'), 403);
        }


        /**
         * update contact group
         *
         * @param ContactGroups      $contact
         * @param UpdateContactGroup $request
         *
         * @return JsonResponse
         */

        public function update(ContactGroups $contact, UpdateContactGroup $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! request()->user()->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            $group = $this->contactGroups->update($contact, $request->input());

            return $this->success($group->select('name', 'uid')->find($contact->id), __('locale.contacts.contact_group_successfully_updated'));

        }

        /**
         * delete contact group
         *
         * @param ContactGroups $contact
         *
         * @return JsonResponse
         */
        public function destroy(ContactGroups $contact): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! request()->user()->can('developers')) {
                return $this->error('You do not have permission to access API', 403);
            }

            if (request()->user()->tokenCan('delete_contact_group')) {

                $this->contactGroups->destroy($contact);

                return $this->success(null, __('locale.contacts.contact_group_successfully_deleted'));

            }

            return $this->error(__('locale.http.403.description'), 403);
        }

    }
