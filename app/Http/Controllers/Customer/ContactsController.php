<?php

    namespace App\Http\Controllers\Customer;

    use App\Http\Requests\Contacts\ImportContact;
    use App\Http\Requests\Contacts\NewContactGroup;
    use App\Http\Requests\Contacts\UpdateContactGroup;
    use App\Http\Requests\Contacts\UpdateContactGroupMessage;
    use App\Jobs\ImportContacts;
    use App\Jobs\ReplicateContacts;
    use App\Library\ContactGroupFieldMapping;
    use App\Library\StringHelper;
    use App\Library\Tool;
    use App\Models\Blacklists;
    use App\Models\ContactGroupFields;
    use App\Models\ContactGroups;
    use App\Models\ContactGroupsOptinKeywords;
    use App\Models\ContactGroupsOptoutKeywords;
    use App\Models\Contacts;
    use App\Models\ContactsCustomField;
    use App\Models\CsvData;
    use App\Models\CustomerBasedPricingPlan;
    use App\Models\FailedContactImport;
    use App\Models\Keywords;
    use App\Models\PhoneNumbers;
    use App\Models\PlansCoverageCountries;
    use App\Models\Senderid;
    use App\Models\TemplateTags;
    use App\Models\User;
    use App\Repositories\Contracts\ContactsRepository;
    use App\Rules\ExcelRule;
    use Exception;
    use Generator;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Database\Eloquent\ModelNotFoundException;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\File;
    use Illuminate\Support\Facades\Validator;
    use JetBrains\PhpStorm\NoReturn;
    use League\Csv\Writer;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumberUtil;
    use OpenSpout\Common\Exception\InvalidArgumentException;
    use OpenSpout\Common\Exception\IOException;
    use OpenSpout\Common\Exception\UnsupportedTypeException;
    use OpenSpout\Writer\Exception\WriterNotOpenedException;
    use Rap2hpoutre\FastExcel\FastExcel;
    use Symfony\Component\HttpFoundation\BinaryFileResponse;
    use Symfony\Component\HttpFoundation\StreamedResponse;
    use Throwable;

    class ContactsController extends CustomerBaseController
    {
        protected ContactsRepository $contactGroups;

        public function __construct(ContactsRepository $contactGroups)
        {
            $this->contactGroups = $contactGroups;
        }

        /**
         * view all contact list
         *
         * @throws AuthorizationException
         */
        public function index(): View|Factory|Application
        {

            $this->authorize('view_contact_group');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Contacts')],
                ['name' => __('locale.contacts.contact_groups')],
            ];

            return view('customer.contactGroups.index', compact('breadcrumbs'));
        }

        /**
         * search contact groups with given data
         *
         *
         * @throws AuthorizationException
         */
        #[NoReturn]
        public function search(Request $request): void
        {

            $this->authorize('view_contact_group');

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'name',
                4 => 'contacts',
                5 => 'created_at',
                6 => 'status',
                7 => 'action',
                8 => 'updated_at',
            ];

            $totalData = ContactGroups::where('customer_id', auth()->user()->id)->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $contact_groups = ContactGroups::where('customer_id', auth()->user()->id)->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $contact_groups = ContactGroups::where('customer_id', auth()->user()->id)->whereLike(['uid', 'name', 'status', 'created_at'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = ContactGroups::where('customer_id', auth()->user()->id)->whereLike(['uid', 'name', 'status', 'created_at'], $search)->count();
            }

            $data = [];
            if ( ! empty($contact_groups)) {
                foreach ($contact_groups as $group) {

                    if ($group->status === true) {
                        $status = 'checked';
                    } else {
                        $status = '';
                    }

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $group->uid;
                    $nestedData['name']          = $group->name;
                    $nestedData['contacts']      = Tool::number_with_delimiter($group->totalSubscribers($group->cache));
                    $nestedData['created_at']    = Tool::formatHumanTime($group->created_at);
                    $nestedData['updated_at']    = Tool::formatHumanTime($group->updated_at);
                    $nestedData['status']        = "<div class='form-check form-switch form-check-primary'>
                <input type='checkbox' class='form-check-input get_status' id='status_$group->uid' data-id='$group->uid' name='status' $status>
                <label class='form-check-label' for='status_$group->uid'>
                  <span class='switch-icon-left'><i data-feather='check'></i> </span>
                  <span class='switch-icon-right'><i data-feather='x'></i> </span>
                </label>
              </div>";

                    $nestedData['show']              = route('customer.contacts.show', $group->uid);
                    $nestedData['show_label']        = __('locale.buttons.edit');
                    $nestedData['new_contact']       = route('customer.contact.create', $group->uid);
                    $nestedData['new_contact_label'] = __('locale.contacts.new_contact');
                    $nestedData['copy']              = __('locale.buttons.copy');
                    $nestedData['delete']            = __('locale.buttons.delete');
                    $nestedData['can_create']        = Auth::user()->can('create_contact');
                    $nestedData['can_update']        = Auth::user()->can('update_contact_group');
                    $nestedData['can_delete']        = Auth::user()->can('delete_contact_group');

                    $data[] = $nestedData;

                }
            }

            $json_data = [
                'draw'            => intval($request->input('draw')),
                'recordsTotal'    => $totalData,
                'recordsFiltered' => $totalFiltered,
                'data'            => $data,
            ];

            echo json_encode($json_data);
            exit();

        }

        /**
         * create new contact group
         *
         * @throws AuthorizationException
         */
        public function create(): View|Factory|RedirectResponse|Application
        {
            if ( ! Auth::user()->customer->activeSubscription()) {
                return redirect()->route('customer.subscriptions.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.customer.no_active_subscription'),
                ]);
            }

            $this->authorize('create_contact_group');
            $totalData = ContactGroups::where('customer_id', auth()->user()->id)->count();
            $list_max  = Auth::user()->customer->getOption('list_max');

            if ($list_max != '-1' && $list_max < $totalData) {
                return redirect()->route('customer.contacts.index')->with([
                    'status'  => 'error',
                    'message' => __('locale.contacts.max_list_quota', ['max_list' => $list_max]),
                ]);
            }

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('contacts'), 'name' => __('locale.menu.Contacts')],
                ['name' => __('locale.contacts.new_contact_group')],
            ];

            return view('customer.contactGroups.create', compact('breadcrumbs'));
        }

        /**
         * store contact group
         */
        public function store(NewContactGroup $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contacts.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $group = $this->contactGroups->store($request->input());

            return redirect()->route('customer.contacts.show', $group->uid)->with([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_group_successfully_added'),
            ]);
        }

        /**
         * View contact group for edit
         *
         *
         *
         * @throws AuthorizationException
         */
        public function show(ContactGroups $contact): View|Factory|Application
        {
            $this->authorize('view_contact_group');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('contacts'), 'name' => __('locale.menu.Contacts')],
                ['name' => $contact->name],
            ];

            if (Auth::user()->customer->getOption('sender_id_verification') == 'yes') {
                $sender_ids    = Senderid::where('user_id', auth()->user()->id)->get();
                $phone_numbers = PhoneNumbers::where('user_id', auth()->user()->id)->get();
            } else {
                $sender_ids    = null;
                $phone_numbers = null;
            }
            $template_tags          = TemplateTags::get();
            $contact_groups         = ContactGroups::where('status', 1)->where('uid', '!=', $contact->uid)->where('customer_id', auth()->user()->id)->select('uid', 'name')->get();
            $opt_in_keywords        = ContactGroupsOptinKeywords::where('contact_group', $contact->id)->get();
            $existing_opt_in        = array_column($opt_in_keywords->toArray(), 'keyword');
            $remain_opt_in_keywords = Keywords::where('user_id', $contact->customer_id)->where('status', 'assigned')->whereNotIn('keyword_name', $existing_opt_in)->select('keyword_name')->get();

            $opt_out_keywords        = ContactGroupsOptoutKeywords::where('contact_group', $contact->id)->get();
            $existing_opt_out        = array_column($opt_out_keywords->toArray(), 'keyword');
            $remain_opt_out_keywords = Keywords::where('user_id', $contact->customer_id)->where('status', 'assigned')->whereNotIn('keyword_name', $existing_opt_out)->select('keyword_name')->get();

            $currentJob = $contact->importJobs()->first();

            $fields = $contact->getFields;

            // Fetch all jobs for the contact group, ordered by creation date
            $import_jobs = $contact->importJobs()->latest()->get();

            return view('customer.contactGroups.show', compact('breadcrumbs', 'contact', 'sender_ids', 'phone_numbers', 'contact_groups', 'template_tags', 'opt_in_keywords', 'opt_out_keywords', 'remain_opt_in_keywords', 'remain_opt_out_keywords', 'currentJob', 'fields', 'import_jobs'));
        }

        /**
         * change contact group status
         *
         *
         *
         * @throws AuthorizationException
         */
        public function activeToggle(ContactGroups $contact): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            try {
                $this->authorize('update_contact_group');

                if ($contact->update(['status' => ! $contact->status])) {
                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contact_group_successfully_change'),
                    ]);
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);

            } catch (ModelNotFoundException $exception) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }

        }

        /**
         * copy contact groups
         *
         *
         * @throws AuthorizationException
         */
        public function copy(ContactGroups $contact, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('create_contact_group');

            $totalData = ContactGroups::where('customer_id', auth()->user()->id)->count();
            $list_max  = Auth::user()->customer->getOption('list_max');

            if ($list_max != '-1' && $list_max < $totalData) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.max_list_quota', ['max_list' => $list_max]),
                ]);
            }

            $subscriber_per_list_max = Contacts::where('group_id', $contact->id)->count();

            if (Auth::user()->customer->getOption('subscriber_per_list_max') != '-1' && $subscriber_per_list_max > Auth::user()->customer->getOption('subscriber_per_list_max')) {
                $subscriber_max = Contacts::where('customer_id', Auth::user()->id)->count();
                if (Auth::user()->customer->getOption('subscriber_max') != '-1' && $subscriber_max > Auth::user()->customer->getOption('subscriber_max')) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.contacts.subscriber_max_quota', ['subscriber_max' => Auth::user()->customer->getOption('subscriber_max')]),
                    ]);
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.subscriber_per_list_max_quota', ['subscriber_per_list_max' => Auth::user()->customer->getOption('subscriber_per_list_max')]),
                ]);
            }

            $new_group       = $contact->replicate();
            $new_group->name = $request->input('group_name');
            $new_group->save();
            $new_group_id = $new_group->id;

            $opt_in_keyword = ContactGroupsOptinKeywords::where('contact_group', $contact->id)->get();
            foreach ($opt_in_keyword as $keyword) {
                $new_keyword                = $keyword->replicate();
                $new_keyword->uid           = uniqid();
                $new_keyword->contact_group = $new_group_id;
                $new_keyword->save();
            }

            $opt_out_keyword = ContactGroupsOptoutKeywords::where('contact_group', $contact->id)->get();
            foreach ($opt_out_keyword as $keyword) {
                $new_keyword                = $keyword->replicate();
                $new_keyword->uid           = uniqid();
                $new_keyword->contact_group = $new_group_id;
                $new_keyword->save();
            }

            $count = Contacts::where('group_id', $contact->id)->count();

            if ($count > 2000) {

                Tool::resetMaxExecutionTime();
                Contacts::where('group_id', $contact->id)->get()
                    ->chunk(5000)
                    ->each(function ($lines) use ($new_group_id, $new_group, &$batch_list, $contact, $count) {
                        $job = new ReplicateContacts($new_group_id, $lines, $count);
                        $contact->dispatchWithMonitor($job);
                        $new_group->updateCache();
                    });


                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.contacts.contact_successfully_imported_in_background'),
                ]);

            }

            Contacts::where('group_id', $contact->id)->get()->chunk('250')->each(function ($lines) use ($new_group) {

                foreach ($lines as $line) {
                    $new_contact             = $line->replicate();
                    $new_contact->uid        = uniqid();
                    $new_contact->group_id   = $new_group->id;
                    $new_contact->created_at = now()->toDateTimeString();
                    $new_contact->updated_at = now()->toDateTimeString();

                    $new_contact->save();

                    if ($line->value) {
                        ContactsCustomField::create([
                            'contact_id' => $new_contact->id,
                            'field_id'   => $line->field_id,
                            'value'      => $line->value,
                        ]);
                    }

                }


                $new_group->updateCache();
            });


            return response()->json([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_group_successfully_copied'),
            ]);
        }

        /**
         * update contact group settings
         */
        public function update(ContactGroups $contact, UpdateContactGroup $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contacts.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $group = $this->contactGroups->update($contact, $request->except('_method', '_token'));

            return redirect()->route('customer.contacts.show', $group->uid)->withInput(['tab' => 'settings'])->with([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_group_successfully_updated'),
            ]);
        }

        /**
         * delete contact group
         *
         *
         * @throws AuthorizationException
         */
        public function destroy(ContactGroups $contact): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('delete_contact_group');

            $this->contactGroups->destroy($contact);

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_group_successfully_deleted'),
            ]);
        }

        /**
         * Bulk Action with Enable, Disable and Delete
         *
         *
         * @throws AuthorizationException
         */
        public function batchAction(Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $action = $request->get('action');
            $ids    = $request->get('ids');

            switch ($action) {
                case 'destroy':
                    $this->authorize('delete_contact_group');

                    $this->contactGroups->batchDestroy($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contact_groups_deleted'),
                    ]);

                case 'enable':
                    $this->authorize('update_contact_group');

                    $this->contactGroups->batchActive($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contact_groups_enabled'),
                    ]);

                case 'disable':

                    $this->authorize('update_contact_group');

                    $this->contactGroups->batchDisable($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contact_groups_disabled'),
                    ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
            ]);

        }

        /**
         * search contact with given data
         *
         *
         * @throws AuthorizationException
         */
        #[NoReturn]
        public function searchContact(ContactGroups $contact, Request $request): void
        {

            $this->authorize('view_contact');

            $totalData = Contacts::where('customer_id', auth()->user()->id)->where('group_id', $contact->id)->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $contacts = Contacts::where('customer_id', auth()->user()->id)->where('group_id', $contact->id)
                    //                  ->with(['contactGroup', 'contactsFields'])
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy('updated_at', $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $contacts = Contacts::where('customer_id', auth()->user()->id)->where('group_id', $contact->id)->whereLike(['uid', 'phone', 'status', 'contactsFields.value'], $search)
                    ->offset($start)
//                    ->with(['contactGroup', 'contactsFields'])
                    ->limit($limit)
                    ->orderBy('updated_at', $dir)
                    ->get();

                $totalFiltered = Contacts::where('customer_id', auth()->user()->id)->where('group_id', $contact->id)->whereLike(['uid', 'phone', 'status', 'contactsFields.value'], $search)->count();
            }

            $can_delete = false;
            if (Auth::user()->can('delete_contact')) {
                $can_delete = true;
            }

            $data = [];
            if ( ! empty($contacts)) {
                foreach ($contacts as $singleContact) {

                    if ($singleContact->status == 'subscribe') {
                        $status = 'checked';
                    } else {
                        $status = '';
                    }


                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $singleContact->uid;
                    $nestedData['phone']         = $singleContact->phone;
                    $nestedData['updated_at']    = Tool::formatHumanTime($singleContact->updated_at);
                    $nestedData['status']        = "<div class='form-check form-switch form-check-primary form-switch-xl'>
                <input type='checkbox' class='form-check-input get_status' id='status_$singleContact->uid' data-id='$singleContact->uid' name='status' $status>
                <label class='form-check-label' for='status_$singleContact->uid'>
                  <span class='switch-text-left'>" . __('locale.labels.subscribe') . "</span>
                  <span class='switch-text-right'>" . __('locale.labels.unsubscribe') . '</span>
                </label>
              </div>';

                    $nestedData['show']             = route('customer.contact.edit', ['contact' => $contact->uid, 'contact_id' => $singleContact->uid]);
                    $nestedData['show_label']       = __('locale.buttons.edit');
                    $nestedData['conversion']       = route('customer.reports.all', ['recipient' => $singleContact->phone]);
                    $nestedData['conversion_label'] = __('locale.contacts.view_conversion');
                    $nestedData['send_sms']         = route('customer.sms.quick_send', ['recipient' => $singleContact->phone]);
                    $nestedData['send_sms_label']   = __('locale.contacts.send_message');
                    $nestedData['delete']           = __('locale.buttons.delete');
                    $nestedData['can_delete']       = $can_delete;


                    foreach ($contact->getFields as $field) {
                        if ($field->tag != "PHONE") {
                            $value                   = $singleContact->getValueByField($field);
                            $nestedData[$field->tag] = empty($value) ? "--" : $value;
                        }
                    }


                    $data[] = $nestedData;

                }
            }

            $json_data = [
                'draw'            => intval($request->input('draw')),
                'recordsTotal'    => $totalData,
                'recordsFiltered' => $totalFiltered,
                'data'            => $data,
            ];

            echo json_encode($json_data);
            exit();

        }

        /**
         * add new contact in a group
         *
         *
         * @throws AuthorizationException
         */
        public function createContact(ContactGroups $contact): View|Factory|RedirectResponse|Application
        {
            $this->authorize('create_contact');

            $subscriber_per_list_max = Contacts::where('group_id', $contact->id)->count();

            if (Auth::user()->customer->getOption('subscriber_per_list_max') != '-1' && $subscriber_per_list_max > Auth::user()->customer->getOption('subscriber_per_list_max')) {
                $subscriber_max = Contacts::where('customer_id', Auth::user()->id)->count();
                if (Auth::user()->customer->getOption('subscriber_max') != '-1' && $subscriber_max > Auth::user()->customer->getOption('subscriber_max')) {
                    return redirect()->route('customer.contacts.show', $contact->uid)->with([
                        'status'  => 'error',
                        'message' => __('locale.contacts.subscriber_max_quota', ['subscriber_max' => Auth::user()->customer->getOption('subscriber_max')]),
                    ]);
                }

                return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                    'status'  => 'error',
                    'message' => __('locale.contacts.subscriber_per_list_max_quota', ['subscriber_per_list_max' => Auth::user()->customer->getOption('subscriber_per_list_max')]),
                ]);
            }

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => route('customer.contacts.show', $contact->uid), 'name' => $contact->name],
                ['name' => __('locale.contacts.new_contact')],
            ];

            return view('customer.Contacts.create', compact('breadcrumbs', 'contact'));
        }

        /**
         * store new contact
         * @throws Throwable
         */
        public function storeContact(ContactGroups $contact, Request $request)
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('create_contact');

            [$validator, $subscriber] = $this->contactGroups->createContactFromRequest($contact, $request->all());

            if (is_null($subscriber)) {
                return back()->withInput()->withErrors($validator);
            }

            return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_successfully_added'),
            ]);
        }

        /**
         * update contact status
         *
         *
         * @throws AuthorizationException
         */
        public function updateContactStatus(ContactGroups $contact, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('update_contact');

            $check_contact = Contacts::where('group_id', $contact->id)->where('uid', $request->input('id'))->first();
            $blacklist     = Blacklists::where('user_id', Auth::user()->id)->where('number', $check_contact->phone)->first();

            if ($blacklist && $check_contact->status == 'unsubscribe') {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.blacklist.phone_was_blacklisted'),
                ]);
            }

            $status = $this->contactGroups->updateContactStatus($contact, $request->only('id'));
            if ($status) {
                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.contacts.contact_successfully_change'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        /**
         * delete single contact from group
         *
         *
         * @throws AuthorizationException
         */
        public function deleteContact(ContactGroups $contact, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('delete_contact');

            $status = $this->contactGroups->contactDestroy($contact, $request->input('id'));

            if ($status) {
                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.contacts.contact_successfully_deleted'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }

        /**
         * view single contact for edit
         *
         *
         * @throws AuthorizationException
         */
        public function editContact(ContactGroups $contact, Request $request): View|Factory|RedirectResponse|Application
        {

            $this->authorize('update_contact');

            $subscriber = Contacts::where('group_id', $contact->id)->where('customer_id', Auth::user()->id)->where('uid', $request->input('contact_id'))->first();
            if ($subscriber) {

                $breadcrumbs = [
                    ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                    ['link' => route('customer.contacts.show', $contact->uid), 'name' => $contact->name],
                    ['name' => __('locale.contacts.update_contact')],
                ];


                $values = [];

                foreach ($contact->getFields as $field) {
                    $values[$field->tag] = $subscriber->getValueByField($field);
                }
                if (null !== $request->old()) {
                    foreach ($request->old() as $key => $value) {
                        if (is_array($value)) {
                            $values[str_replace('[]', '', $key)] = implode(',', $value);
                        } else {
                            $values[$key] = $value;
                        }
                    }
                }


                return view('customer.Contacts.show', compact('breadcrumbs', 'contact', 'subscriber', 'values'));
            }

            return redirect()->route('customer.contacts.show', $contact->uid)->with([
                'status'  => 'error',
                'message' => __('locale.contacts.contact_not_found'),
            ]);
        }

        /**
         * update single contact information
         */
        public function updateContact(ContactGroups $contact, Request $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('update_contact');

            $subscriber = Contacts::where('group_id', $contact->id)->where('customer_id', Auth::user()->id)->where('uid', $request->input('contact_id'))->first();

            if ( ! $subscriber) {
                return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => __('locale.contacts.contact_not_found'),
                ]);
            }


            $this->validate($request, $subscriber->getRules());

            $subscriber->updateFields($request->all());


            return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_successfully_updated'),
            ]);

        }

        /**
         * import contact using csv or excel
         *
         *
         * @throws AuthorizationException
         */
        public function importContact(ContactGroups $contact): View|Factory|Application
        {

            $this->authorize('view_contact_group');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('contacts'), 'name' => __('locale.menu.Contacts')],
                ['name' => $contact->name],
            ];

            $tab        = request()->input('tab') ?? 'import_file';
            $currentJob = $contact->importJobs()->first();

            return view('customer.Contacts.import', compact('breadcrumbs', 'contact', 'tab', 'currentJob'));
        }

        /**
         * working with import contacts
         */
        public function storeImportContact(ContactGroups $contact, ImportContact $request): View|Factory|RedirectResponse|Application
        {
            if (isset($request->recipients) && $request->recipients != null) {

                if (config('app.stage') == 'demo') {
                    return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                        'status'  => 'error',
                        'message' => 'Sorry! This option is not available in demo mode',
                    ]);
                }

                $delimiters = [';' => ';', ',' => ',', '|' => '|', 'tab' => ' ', 'new_line' => "\n"];
                $delimiter  = $request->input('delimiter');

                if ( ! isset($delimiters[$delimiter])) {
                    return redirect()->route('customer.contacts.show', $contact->uid)
                        ->withInput(['tab' => 'contact'])
                        ->withErrors(['message' => __('locale.labels.invalid_delimiter')]);
                }

                $recipients = explode($delimiters[$delimiter], $request->recipients);
                $total      = count($recipients);

                if ($total > 1000) {
                    return redirect()->route('customer.contacts.show', $contact->uid)
                        ->withInput(['tab' => 'contact'])
                        ->withErrors(['message' => __('locale.contacts.upload_maximum_1000_rows')]);
                }

                $phone_numbers = Contacts::where('group_id', $contact->id)
                    ->where('customer_id', Auth::user()->id)
                    ->pluck('phone')
                    ->toArray();
                $blacklists    = Blacklists::where('user_id', Auth::user()->id)
                    ->pluck('number')
                    ->toArray();
                $processed     = 0;

                collect($recipients)->unique()
                    ->chunk('250')
                    ->each(function ($lines) use ($contact, $phone_numbers, $blacklists, &$processed) {
                        $list = [];
                        foreach ($lines as $line) {
                            $phone = str_replace(['(', ')', '+', '-', ' '], '', $line);

                            try {
                                $phoneUtil         = PhoneNumberUtil::getInstance();
                                $phoneNumberObject = $phoneUtil->parse('+' . $phone);
                                $countryCode       = $phoneNumberObject->getCountryCode();
                                $isoCode           = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);

                                if ( ! $phoneUtil->isPossibleNumber($phoneNumberObject) || empty($countryCode) || empty($isoCode)) {
                                    continue;
                                }

                                if ( ! in_array($phone, $phone_numbers) && ! in_array($phone, $blacklists)) {
                                    $processed++;
                                    $list[] = [
                                        'uid'         => uniqid(),
                                        'customer_id' => Auth::user()->id,
                                        'group_id'    => $contact->id,
                                        'status'      => 'subscribe',
                                        'phone'       => $phone,
                                        'created_at'  => now()->toDateTimeString(),
                                        'updated_at'  => now()->toDateTimeString(),
                                    ];
                                }
                            } catch (NumberParseException) {
                                continue;
                            }
                        }
                        Contacts::insert($list);
                    });

                $failed = $total - $processed;
                $contact->updateCache();

                return redirect()->route('customer.contacts.show', $contact->uid)
                    ->withInput(['tab' => 'contact'])
                    ->with([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contact_successfully_imported') . ' ' . sprintf('Processed: %s/%s, Skipped: %s', $processed, $total, $failed),
                    ]);

            } else {
                return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'contact'])->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.invalid_action'),
                ]);
            }
        }

        /**
         * import process data
         */
        public function importProcessData(ContactGroups $contact, Request $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contact.import', $contact->uid)->withInput(['tab' => 'import_file'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $data = CsvData::find($request->input('csv_data_file_id'));

            if (empty($data)) {
                return redirect()->route('customer.contact.import', $contact->uid)->withInput(['tab' => 'import_file'])->with([
                    'status'  => 'error',
                    'message' => 'No data to import',
                ]);
            }


            $csv_data = json_decode($data->csv_data, true);

            if (empty($csv_data)) {
                return redirect()->route('customer.contact.import', $contact->uid)->withInput(['tab' => 'import_file'])->with([
                    'status'  => 'error',
                    'message' => 'No data to import',
                ]);
            }

            $db_fields = $request->input('fields');

            if (empty($db_fields)) {
                return redirect()->route('customer.contact.import', $contact->uid)->withInput(['tab' => 'import_file'])->with([
                    'status'  => 'error',
                    'message' => 'No data to import',
                ]);
            }


            if (is_array($db_fields) && ! in_array('phone', $db_fields)) {
                return redirect()->route('customer.contact.import', $contact->uid)->withInput(['tab' => 'import_file'])->with([
                    'status'  => 'error',
                    'message' => __('locale.filezone.phone_number_column_require'),
                ]);
            }

            $collection = collect($csv_data)->skip($data->csv_header)->unique(array_keys($db_fields, 'phone'));

            if ($collection->isEmpty()) {
                return redirect()->route('customer.contact.import', $contact->uid)->withInput(['tab' => 'import_file'])->with([
                    'status'  => 'error',
                    'message' => 'No data to import',
                ]);
            }

            $total = $collection->count();

            Tool::resetMaxExecutionTime();
            $collection->chunk(5000)
                ->each(function ($lines) use ($contact, $db_fields, $total) {
                    $job = new ImportContacts(Auth::user()->id, $contact->id, $lines, $db_fields, $total);
                    $contact->dispatchWithMonitor($job);
                });

            $data->delete();

            return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'import_history'])->with([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_successfully_imported_in_background'),
            ]);
        }

        /**
         * Bulk Action with subscribe, unsubscribe and Delete contacts
         *
         *
         * @throws AuthorizationException
         */
        public function batchActionContact(ContactGroups $contact, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $action = $request->get('action');
            $ids    = $request->get('ids');

            switch ($action) {
                case 'destroy':

                    $this->authorize('delete_contact');

                    $this->contactGroups->batchContactDestroy($contact, $ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contacts_deleted'),
                    ]);

                case 'subscribe':

                    $this->authorize('update_contact');

                    $this->contactGroups->batchContactSubscribe($contact, $ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contacts_subscribe'),
                    ]);

                case 'unsubscribe':

                    $this->authorize('update_contact');

                    $this->contactGroups->batchContactUnsubscribe($contact, $ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contacts_unsubscribe'),
                    ]);

                case 'copy':

                    $this->authorize('update_contact');

                    $target_group = $request->get('target_group');
                    $group        = ContactGroups::where('uid', $target_group)->first();

                    if ( ! $group) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.contacts.contact_group_not_found'),
                        ]);
                    }

                    $input = [
                        'ids'          => $ids,
                        'target_group' => $group->id,
                    ];

                    $this->contactGroups->batchContactCopy($contact, $input);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contacts_copy'),
                    ]);

                case 'move':

                    $this->authorize('update_contact');

                    $target_group = $request->get('target_group');
                    $group        = ContactGroups::where('uid', $target_group)->first();

                    if ( ! $group) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.contacts.contact_group_not_found'),
                        ]);
                    }

                    $input = [
                        'ids'          => $ids,
                        'target_group' => $group->id,
                    ];

                    $this->contactGroups->batchContactMove($contact, $input);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.contacts.contacts_moved'),
                    ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
            ]);

        }


        /**
         * @throws Exception
         */


        public function contactsGenerator($group_id, $headers = null): string
        {
            // Step 1: Fetch the group (if not found, throw an exception)
            $group = ContactGroups::findOrFail($group_id);

            // Step 2: Prepare file path
            $file = $group->getExportFilePath();

            // Step 3: If file exists, delete it
            if (file_exists($file)) {
                File::delete($file);
            }

            // Step 4: Initialize CSV Writer
            $writer = Writer::createFromPath($file, 'w+');

            // Step 5: Prepare headers for CSV file
            if ( ! $headers) {
                // If headers are not provided, get them from the group's fields
                $headers = $group->getFields()->pluck('label')->toArray();
            }

            // Add additional attributes if not already in headers
            if ( ! in_array('uid', $headers)) {
                $headers[] = 'uid';
            }
            if ( ! in_array('status', $headers)) {
                $headers[] = 'status';
            }
            if ( ! in_array('created_at', $headers)) {
                $headers[] = 'created_at';
            }
            if ( ! in_array('updated_at', $headers)) {
                $headers[] = 'updated_at';
            }

            // Insert headers into the CSV
            $writer->insertOne($headers);

            // Step 6: Process subscribers in chunks to reduce memory usage
            Contacts::where('group_id', $group_id)
                ->chunk(1000, function ($subscribers) use ($writer, $group, $headers) {
                    // Prepare records for insertion
                    $records = [];
                    foreach ($subscribers as $subscriber) {
                        // Prepare attributes for each subscriber, respecting the $headers array
                        $attributes = [];
                        foreach ($headers as $header) {

                            switch ($header) {
                                case 'uid':
                                    $attributes['uid'] = $subscriber->uid;
                                    break;
                                case 'status':
                                    $attributes['status'] = $subscriber->status;
                                    break;
                                case 'created_at':
                                    $attributes['created_at'] = $subscriber->created_at->toDateTimeString();
                                    break;
                                case 'updated_at':
                                    $attributes['updated_at'] = $subscriber->updated_at->toDateTimeString();
                                    break;
                                default:
                                    // Assume this header corresponds to a custom field
                                    $field = $group->getFields()->where('tag', $header)->first();
                                    if ($field) {
                                        $attributes[$header] = $subscriber->getValueByField($field);
                                    } else {
                                        $attributes[$header] = ''; // Add an empty value if the field doesn't exist
                                    }
                                    break;
                            }
                        }
                        $records[] = $attributes;
                    }

                    // Insert records into the CSV file
                    $writer->insertAll($records);
                });

            // Step 8: Return the path to the generated CSV file
            return $file;
        }

        /**
         * @throws AuthorizationException
         */
        public function exportContact(ContactGroups $contact, Request $request): BinaryFileResponse|RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! $request->get('contact_fields') || ! is_array($request->get('contact_fields')) || count($request->get('contact_fields')) == 0) {
                return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => __('locale.contacts.contact_fields_not_found'),
                ]);
            }

            $this->authorize('view_contact');

            try {
                $file_name = $this->contactsGenerator($contact->id, $request->get('contact_fields'));

                return response()->download($file_name);
            } catch (IOException|InvalidArgumentException|UnsupportedTypeException|WriterNotOpenedException|Exception $e) {
                return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

        }

        public function subscribeURL(ContactGroups $contact): View|Factory|Application
        {
            $pageConfigs = [
                'bodyClass' => 'bg-full-screen-image',
                'blankPage' => true,
            ];

            $user     = User::find($contact->customer_id);
            $coverage = null;
            if ($user) {
                $plan_id = $user->customer->activeSubscription()->plan_id;

                $coverage = CustomerBasedPricingPlan::where('user_id', $user->id)->where('status', true)->get();
                if ($coverage->count() < 1) {
                    $coverage = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->get();
                }

                return view('customer.Contacts.subscribe_form', compact('contact', 'pageConfigs', 'coverage'));
            }

            return view('customer.Contacts.subscribe_form', compact('contact', 'pageConfigs', 'coverage'));
        }

        /**
         * insert contact by subscription form
         * @throws Throwable
         */
        public function insertContactBySubscriptionForm(ContactGroups $contact, Request $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('contacts.subscribe_url', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $rules = [];

            if (config('no-captcha.registration')) {
                $rules['g-recaptcha-response'] = 'required|recaptchav3:subscribe,0.5';
            }

            $messages = [
                'g-recaptcha-response.required'    => __('locale.auth.recaptcha_required'),
                'g-recaptcha-response.recaptchav3' => __('locale.auth.recaptcha_required'),
            ];

            $validation = Validator::make($request->all(), $rules, $messages);

            if ($validation->fails()) {
                return redirect()->route('contacts.subscribe_url', $contact->uid)->withInput($request->all())->withErrors($validation->errors());
            }

            [$validator, $subscriber] = $this->contactGroups->createContactFromRequest($contact, $request->all());

            if (is_null($subscriber)) {
                return back()->withInput()->withErrors($validator);
            }

            return redirect()->route('contacts.subscribe_url', $contact->uid)->with([
                'status'  => 'success',
                'message' => __('locale.contacts.you_have_successfully_subscribe', ['contact_group' => $contact->name]),
            ]);
        }

        /**
         * return sms form data
         */
        public function getMessageForm(ContactGroups $contact, Request $request): JsonResponse
        {
            return response()->json([
                'status'  => 'success',
                'message' => $contact->{$request->input('sms_form')},
            ]);
        }

        /**
         * update contact groups message
         */
        public function message(ContactGroups $contact, UpdateContactGroupMessage $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $contact->{$request->input('message_form')} = $request->input('message');
            $contact->save();

            return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'message'])->with([
                'status'  => 'success',
                'message' => __('locale.contacts.contact_groups_message_information', ['message_from' => ucfirst(str_replace('_', ' ', $request->input('message_form')))]),
            ]);
        }

        /**
         * add opt in keyword
         */
        public function optInKeyword(ContactGroups $contact, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }
            $keyword_name = $request->input('keyword_name');
            if ($keyword_name) {
                $keyword = Keywords::where('user_id', Auth::user()->id)->where('keyword_name', $keyword_name)->first();
                if ($keyword) {
                    $status = ContactGroupsOptinKeywords::create([
                        'contact_group' => $contact->id,
                        'keyword'       => $keyword_name,
                    ]);

                    if ($status) {
                        return response()->json([
                            'status'  => 'success',
                            'message' => __('locale.contacts.optin_keyword_successfully_added'),
                        ]);
                    }

                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

                }

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.keyword_info_not_found'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.labels.at_least_one_data'),
            ]);
        }

        /**
         * add opt in keyword
         */
        public function optOutKeyword(ContactGroups $contact, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $keyword_name = $request->input('keyword_name');
            if ($keyword_name) {
                $keyword = Keywords::where('user_id', Auth::user()->id)->where('keyword_name', $keyword_name)->first();
                if ($keyword) {

                    $status = ContactGroupsOptoutKeywords::create([
                        'contact_group' => $contact->id,
                        'keyword'       => $keyword_name,
                    ]);

                    if ($status) {
                        return response()->json([
                            'status'  => 'success',
                            'message' => __('locale.contacts.optout_keyword_successfully_added'),
                        ]);
                    }

                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

                }

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.contacts.keyword_info_not_found'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.labels.at_least_one_data'),
            ]);
        }

        /**
         * delete opt in keyword
         */
        public function deleteOptInKeyword(ContactGroups $contact, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }
            $keyword_id    = $request->input('id');
            $optin_keyword = ContactGroupsOptinKeywords::where('contact_group', $contact->id)->where('uid', $keyword_id)->delete();
            if ($optin_keyword) {
                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.contacts.optin_keyword_successfully_deleted'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.contacts.keyword_info_not_found'),
            ]);
        }

        /**
         * delete opt out keyword
         */
        public function deleteOptOutKeyword(ContactGroups $contact, Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $keyword_id     = $request->input('id');
            $optout_keyword = ContactGroupsOptoutKeywords::where('contact_group', $contact->id)->where('uid', $keyword_id)->delete();
            if ($optout_keyword) {
                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.contacts.optout_keyword_successfully_deleted'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.contacts.keyword_info_not_found'),
            ]);
        }

        public function contactGroupsGenerator(): Generator
        {
            foreach (ContactGroups::where('customer_id', Auth::user()->id)->get() as $contactGroup) {
                yield $contactGroup;
            }
        }

        /**
         * @throws AuthorizationException
         */
        public function export(): BinaryFileResponse|RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contacts.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('view_contact');

            try {
                $file_name = (new FastExcel($this->contactGroupsGenerator()))->export(storage_path('ContactGroups_' . time() . '.xlsx'));

                return response()->download($file_name);
            } catch (IOException|InvalidArgumentException|UnsupportedTypeException|WriterNotOpenedException $e) {
                return redirect()->route('customer.contacts.index')->with([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Version 3.9
        |--------------------------------------------------------------------------
        |
        | Modify full contact groups
        |
        */

        /**
         * create database with the following structure
         *
         *contact_group_fields table (fields)
         *
         *$table->uuid('uid');
         * $table->integer('contact_group_id')->unsigned();
         * $table->string('label');
         * $table->string('type');
         * $table->string('tag');
         * $table->string('default_value')->nullable();
         * $table->boolean('visible')->default(true);
         * $table->boolean('required')->default(false);
         *
         * $table->timestamps();
         *
         * $table->foreign('contact_group_id')->references('id')->on('contact_groups')->onDelete('cascade');
         *
         *==========================================================================
         * contact_group_field_options table (field_options)
         *
         * $table->uuid('uid');
         * $table->integer('field_id')->unsigned();
         * $table->string('label');
         * $table->string('value');
         *
         * $table->timestamps();
         *
         * // foreign
         * $table->foreign('field_id')->references('id')->on('contact_group_fields')->onDelete('cascade');
         *
         *==========================================================================
         *
         * Update contacts table (subscribers)
         *
         * Update contacts_custom_field table (subscriber_fields)
         *
         *==========================================================================
         *
         * create segments table
         *
         * $table->uuid('uid');
         * $table->integer('contact_group_id')->unsigned();
         * $table->string('name');
         * $table->string('matching');
         *
         * $table->timestamps();
         *
         * // foreign
         * $table->foreign('contact_group_id')->references('id')->on('contact_groups')->onDelete('cascade');
         *==========================================================================
         *
         * create segment_conditions table
         *
         * $table->uuid('uid');
         * $table->integer('segment_id')->unsigned();
         * $table->integer('field_id')->unsigned()->nullable();
         * $table->string('operator');
         * $table->string('value')->nullable();
         *
         * $table->timestamps();
         *
         * // foreign
         * $table->foreign('segment_id')->references('id')->on('segments')->onDelete('cascade');
         * $table->foreign('field_id')->references('id')->on('contact_group_fields')->onDelete('cascade');
         */

        /**
         * Generates the function comment for the given function body.
         *
         * @param ContactGroups $contact description
         * @param Request       $request description
         */
        public function contactSampleField(ContactGroups $contact, Request $request)
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contacts.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('view_contact');

            return view('customer.contactGroups._form_fields', [
                'list' => $contact,
                'type' => $request->input('type'),
            ]);

        }

        /**
         * Deletes a contact field.
         *
         * @param ContactGroups      $contact The contact group.
         * @param ContactGroupFields $field_id The field ID.
         * @return JsonResponse
         */
        public function deleteContactField(ContactGroups $contact, ContactGroupFields $field_id)
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('view_contact');

            $field = ContactGroupFields::where('contact_group_id', $contact->id)->find($field_id->id);

            if ($field->tag != 'PHONE') {

                $field->delete();

                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.fields.field_has_been_successfully_deleted'),
                ]);

            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.fields.phone_field_cannot_be_deleted'),
            ]);

        }

        public function storeContactField(ContactGroups $contact, Request $request)
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'fields'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('view_contact');

            $validator = $this->contactGroups->updateOrCreateFieldsFromRequest($contact, $request->all());

            if ($validator->fails()) {

                return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'fields'])->withErrors($validator);
            }

            return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'fields'])->with([
                'status'  => 'success',
                'message' => __('locale.fields.created'),
            ]);

        }

        /*Version 3.9*/

        public function pasteText(ContactGroups $contact, Request $request)
        {

            $this->authorize('view_contact');

            $tab = $request->input('tab') ?? 'paste_text';

            return view('customer.Contacts.paste_text', compact('contact', 'tab'));

        }

        /**
         * @throws Exception
         */
        public function storeImportFile(ContactGroups $contact, Request $request)
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('customer.contacts.show', $contact->uid)->withInput(['tab' => 'fields'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('view_contact');

            $validator = Validator::make($request->all(), [
                'import_file' => ['required', 'file', 'max:' . (config('app.stage') == 'demo' ? 5 : 500000), new ExcelRule($request->file('import_file'))],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $validator->errors()->first(),
                ]);
            }

            $filepath = $contact->uploadCsv($request->file('import_file'));

            // Redirect to my lists page
            return response()->json([
                'status'     => 'success',
                'message'    => __('locale.filezone.csv_uploaded'),
                'mappingUrl' => route('customer.contacts.import-mapping', [
                    'contact'  => $contact->uid,
                    'filepath' => $filepath,
                ]),
            ]);

        }


        public function importMapping(ContactGroups $contact, Request $request)
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $filepath = $request->filepath;

            // 🧼 Force BOM cleaning before reading
            StringHelper::checkAndRemoveUTF8BOM($filepath);

            try {
                [$headers, $total, $results] = $contact->readCsv($filepath);
            } catch (Throwable $e) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 404);
            }

            $view = \Illuminate\Support\Facades\View::make('customer.Contacts.import.mapping', [
                'list'     => $contact,
                'headers'  => $headers,
                'filepath' => $filepath,
            ])->render();

            return response()->json(['html' => $view]);
        }


        public function importRun(ContactGroups $contact, Request $request)
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $job = $contact->dispatchImportJob($request->filepath, $request->input('mapping'));

            return response()->json([
                'status'      => 'success',
                'job_uid'     => $job->uid,
                'redirectUrl' => route('customer.contacts.show', ['contact' => $contact->uid, 'tab' => 'import_history']),
                'message'     => __('locale.contacts.contact_successfully_imported_in_background'),
            ]);
        }


        public function importValidate(ContactGroups $contact, Request $request)
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            try {
                ContactGroupFieldMapping::parse($request->mapping, $contact);

                return response()->json([
                    'message' => 'success',
                ]);
            } catch (Throwable $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 401);
            }
        }

        public function unsubscribeURL(ContactGroups $contact)
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('contacts.unsubscribe_url', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $pageConfigs = [
                'bodyClass' => 'bg-full-screen-image',
                'blankPage' => true,
            ];


            return view('customer.Contacts.unsubscribe_form', compact('contact', 'pageConfigs'));

        }


        public function postUnsubscribeURL(ContactGroups $contact, Request $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('contacts.unsubscribe_url', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $rules = [];

            if (config('no-captcha.registration')) {
                $rules['g-recaptcha-response'] = 'required|recaptchav3:unsubscribe,0.5';
            }

            $messages = [
                'g-recaptcha-response.required'    => __('locale.auth.recaptcha_required'),
                'g-recaptcha-response.recaptchav3' => __('locale.auth.recaptcha_required'),
            ];

            $validation = Validator::make($request->all(), $rules, $messages);

            if ($validation->fails()) {
                return redirect()->route('contacts.unsubscribe_url', $contact->uid)->withInput($request->all())->withErrors($validation->errors());
            }

            $phone = $request->input('phone');

            $checkExist = Contacts::where('group_id', $contact->id)->where('phone', $phone)->first();

            if ( ! $checkExist) {

                return redirect()->route('contacts.unsubscribe_url', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => __('locale.contacts.contact_not_found'),
                ]);
            }

            $checkExist->update(['status' => 'unsubscribe']);

            $contact->updateCache();

            return redirect()->route('contacts.unsubscribe_url', $contact->uid)->with([
                'status'  => 'success',
                'message' => __('locale.contacts.you_have_successfully_unsubscribe', ['contact_group' => $contact->name]),
            ]);
        }


        public function countContacts(Request $request)
        {
            $contactGroupIds = $request->input('contact_group_ids');

            if (is_null($contactGroupIds))
                return response()->json('No contact groups selected', 404);

            $total = Contacts::whereIn('group_id', $contactGroupIds)->where('status', Contacts::STATUS_SUBSCRIBE)->count();

            if ($total)
                return $total;

            return 0;
        }

        /**
         * Downloads a CSV containing failed records for a given import job.
         *
         * @param ContactGroups $contact
         * @param int           $jobId
         * @return RedirectResponse|StreamedResponse
         */
        public function downloadFailedContacts(ContactGroups $contact, int $jobId)
        {
            // Check if any failed records exist first
            $exists = FailedContactImport::where('contact_group_id', $contact->id)
                ->where('user_id', $contact->customer_id)
                ->where('job_id', $jobId)
                ->exists();

            if ( ! $exists) {
                return redirect()->route('customer.contacts.show', $contact->uid)->with([
                    'status'  => 'error',
                    'message' => __('locale.contacts.contact_not_found'),
                ]);
            }

            $headers = [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="failed_records_' . $contact->uid . '_' . $contact->name . '.csv"',
            ];

            $callback = function () use ($contact, $jobId) {
                $query = FailedContactImport::where('contact_group_id', $contact->id)
                    ->where('job_id', $jobId)
                    ->where('user_id', $contact->customer_id);

                $file = fopen('php://output', 'w');

                $headerWritten = false;

                foreach ($query->cursor() as $record) {
                    if ( ! $headerWritten) {
                        $columnHeaders   = array_keys($record->record_data ?? []);
                        $columnHeaders[] = 'reason';
                        fputcsv($file, $columnHeaders);
                        $headerWritten = true;
                    }

                    $rowData   = array_values($record->record_data ?? []);
                    $rowData[] = $record->reason;
                    fputcsv($file, $rowData);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        }


    }
