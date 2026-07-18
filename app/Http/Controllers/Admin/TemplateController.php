<?php

    namespace App\Http\Controllers\Admin;

    use App\Exceptions\GeneralException;
    use App\Http\Controllers\Controller;
    use App\Http\Requests\Templates\StoreTemplate;
    use App\Http\Requests\Templates\UpdateTemplate;
    use App\Library\Tool;
    use App\Models\Senderid;
    use App\Models\Templates;
    use App\Models\TemplateTags;
    use App\Models\User;
    use App\Repositories\Contracts\TemplatesRepository;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Database\Eloquent\ModelNotFoundException;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Validator;
    use Illuminate\View\View;
    use JetBrains\PhpStorm\NoReturn;

    class TemplateController extends Controller
    {


        protected TemplatesRepository $templates;


        /**
         * TemplateController constructor.
         *
         * @param TemplatesRepository $templates
         */

        public function __construct(TemplatesRepository $templates)
        {
            $this->templates = $templates;
        }

        /**
         * view all templates
         *
         * @return Application|Factory|View
         * @throws AuthorizationException
         */

        public function index()
        {
            $this->authorize('view templates');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Sending')],
                ['name' => __('locale.menu.SMS Template')],
            ];

            return view('admin.Templates.index', compact('breadcrumbs'));
        }


        /**
         * @param Request $request
         *
         * @return void
         * @throws AuthorizationException
         */
        #[NoReturn] public function search(Request $request)
        {

            $this->authorize('view templates');

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'name',
                4 => 'message',
                5 => 'status',
                6 => 'action',
                7 => 'user_id',
            ];

            $totalData = Templates::count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $templates = Templates::offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $templates = Templates::whereLike(['uid', 'name', 'message', 'user.first_name', 'user.last_name'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = Templates::whereLike(['uid', 'name', 'message', 'user.first_name', 'user.last_name'], $search)->count();
            }

            $data = [];
            if ( ! empty($templates)) {
                foreach ($templates as $template) {

                    if ($template->status === true) {
                        $status = 'checked';
                    } else {
                        $status = '';
                    }

                    if (strlen($template->message) > 100) {
                        $message = str_limit($template->message);
                    } else {
                        $message = $template->message;
                    }


                    $customer_profile = route('admin.customers.show', $template->user->uid);
                    $customer_name    = $template->user->displayName();
                    $user_id          = "<a href='$customer_profile' class='text-primary mr-1'>$customer_name</a>";

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $template->uid;
                    $nestedData['name']          = $template->name;
                    $nestedData['message']       = $message;
                    $nestedData['avatar']        = route('admin.customers.avatar', $template->user->uid);
                    $nestedData['created_at']    = __('locale.labels.created_at') . ': ' . Tool::formatDateTime($template->created_at);
                    $nestedData['email']         = $template->user->email;
                    $nestedData['user_id']       = $user_id;
                    $nestedData['status']        = "<div class='form-check form-switch form-check-primary'>
                <input type='checkbox' class='form-check-input get_status' id='status_$template->uid' data-id='$template->uid' name='status' $status>
                <label class='form-check-label' for='status_$template->uid'>
                  <span class='switch-icon-left'><i data-feather='check'></i> </span>
                  <span class='switch-icon-right'><i data-feather='x'></i> </span>
                </label>
              </div>";

                    $nestedData['edit'] = route('admin.templates.show', $template->uid);
                    $data[]             = $nestedData;

                }
            }

            $json_data = [
                "draw"            => intval($request->input('draw')),
                "recordsTotal"    => $totalData,
                "recordsFiltered" => $totalFiltered,
                "data"            => $data,
            ];

            echo json_encode($json_data);
            exit();

        }


        /**
         * create new template
         *
         * @return Application|Factory|View
         * @throws AuthorizationException
         */

        public function create()
        {
            $this->authorize('create templates');

            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('templates'), 'name' => __('locale.menu.SMS Template')],
                ['name' => __('locale.templates.add_template')],
            ];

            $template_tags = TemplateTags::cursor();
            $customers     = User::where('status', true)->where('is_customer', true)->cursor();
            $sender_ids    = Senderid::where('status', 'active')->cursor();

            return view('admin.Templates.create', compact('breadcrumbs', 'template_tags', 'customers', 'sender_ids'));
        }


        /**
         * View template for edit
         *
         * @param Templates $template
         *
         * @return Application|Factory|View
         *
         * @throws AuthorizationException
         */

        public function show(Templates $template)
        {
            $this->authorize('edit templates');

            $breadcrumbs   = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('templates'), 'name' => __('locale.menu.SMS Template')],
                ['name' => __('locale.templates.update_template')],
            ];
            $template_tags = TemplateTags::cursor();
            $customers     = User::where('status', true)->where('is_customer', true)->cursor();
            $sender_ids    = Senderid::where('status', 'active')->cursor();

            return view('admin.Templates.create', compact('breadcrumbs', 'template', 'template_tags', 'customers', 'sender_ids'));
        }


        /**
         * store new template
         *
         * @param StoreTemplate $request
         *
         * @return RedirectResponse
         */
        public function store(StoreTemplate $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.templates.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if (config('app.trai_dlt') && $request->input('is_dlt') == 'on') {
                $validator = Validator::make($request->all(), [
                    'dlt_template_id' => 'required',
                    'dlt_category'    => 'required',
                    'sender_id'       => 'required|exists:senderid,id',
                ]);

                if ($validator->fails()) {
                    return redirect()->route('admin.templates.create')->withErrors($validator->errors());
                }
            }

            $this->templates->store($request->input());

            return redirect()->route('admin.templates.index')->with([
                'status'  => 'success',
                'message' => __('locale.templates.template_successfully_added'),
            ]);

        }


        /**
         * update template
         *
         * @param Templates      $template
         * @param UpdateTemplate $request
         *
         * @return RedirectResponse
         */

        public function update(Templates $template, UpdateTemplate $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.templates.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if (config('app.trai_dlt') && $request->input('is_dlt') == 'on') {
                $validator = Validator::make($request->all(), [
                    'dlt_template_id' => 'required',
                    'dlt_category'    => 'required',
                    'sender_id'       => 'required|exists:senderid,id',
                    'approved'        => 'required',
                ]);

                if ($validator->fails()) {
                    return redirect()->route('admin.templates.create')->withInput([
                        'template' => $template,
                    ])->withErrors($validator->errors());
                }
            }

            $this->templates->update($template, $request->input());

            return redirect()->route('admin.templates.index')->with([
                'status'  => 'success',
                'message' => __('locale.templates.template_successfully_updated'),
            ]);
        }

        /**
         * remove existing template
         *
         * @param Templates $template
         *
         * @return JsonResponse
         * @throws AuthorizationException
         */
        public function destroy(Templates $template): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('delete templates');

            $this->templates->destroy($template);

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.templates.template_successfully_deleted'),
            ]);

        }

        /**
         * change template status
         *
         * @param Templates $template
         *
         * @return JsonResponse
         *
         * @throws AuthorizationException
         * @throws GeneralException
         */
        public function activeToggle(Templates $template): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }
            try {
                $this->authorize('view templates');

                if ($template->update(['status' => ! $template->status])) {
                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.templates.template_successfully_change'),
                    ]);
                }

                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            } catch (ModelNotFoundException $exception) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }


        /**
         * Bulk Action with Enable, Disable and Delete
         *
         * @param Request $request
         *
         * @return JsonResponse
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

            $this->authorize('delete templates');

            $action = $request->get('action');
            $ids    = $request->get('ids');

            switch ($action) {
                case 'destroy':

                    $this->templates->batchDestroy($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.templates.templates_deleted'),
                    ]);

                case 'enable':

                    $this->templates->batchActive($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.templates.templates_enabled'),
                    ]);

                case 'disable':

                    $this->templates->batchDisable($ids);

                    return response()->json([
                        'status'  => 'success',
                        'message' => __('locale.templates.templates_disabled'),
                    ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
            ]);

        }

    }
