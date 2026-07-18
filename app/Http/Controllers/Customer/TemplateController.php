<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\GeneralException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\StoreTemplate;
use App\Http\Requests\Templates\UpdateTemplate;
use App\Models\Senderid;
use App\Models\Templates;
use App\Models\TemplateTags;
use App\Repositories\Contracts\TemplatesRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use JetBrains\PhpStorm\NoReturn;

class TemplateController extends Controller
{

    protected $templates;


    /**
     * TemplateController constructor.
     *
     * @param  TemplatesRepository  $templates
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
        $this->authorize('sms_template');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => __('locale.menu.Sending')],
                ['name' => __('locale.menu.SMS Template')],
        ];

        return view('customer.Templates.index', compact('breadcrumbs'));
    }


    /**
     * @param  Request  $request
     *
     * @return void
     * @throws AuthorizationException
     */
    #[NoReturn] public function search(Request $request)
    {

        $this->authorize('sms_template');

        $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'name',
                4 => 'message',
                5 => 'status',
                6 => 'action',
                7 => 'approved',
        ];

        $totalData = Templates::where('user_id', Auth::user()->id)->count();

        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir   = $request->input('order.0.dir');

        if (empty($request->input('search.value'))) {
            $templates = Templates::where('user_id', Auth::user()->id)->offset($start)
                                  ->limit($limit)
                                  ->orderBy($order, $dir)
                                  ->get();
        } else {
            $search = $request->input('search.value');

            $templates = Templates::where('user_id', Auth::user()->id)->whereLike(['uid', 'name', 'message', 'approved', 'dlt_template_id'], $search)
                                  ->offset($start)
                                  ->limit($limit)
                                  ->orderBy($order, $dir)
                                  ->get();

            $totalFiltered = Templates::where('user_id', Auth::user()->id)->whereLike(['uid', 'name', 'message', 'approved', 'dlt_template_id'], $search)->count();
        }


        $is_dlt = false;
        if (config('app.trai_dlt') && Auth::user()->customer->activeSubscription() !== null && Auth::user()->customer->activeSubscription()->plan->is_dlt) {
            $is_dlt = true;
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

                if ($template->approved == 'approved') {
                    $approved = '<span class="badge bg-success text-uppercase">'.__('locale.labels.approved').'</span>';
                } elseif ($template->approved == 'in_review') {
                    $approved = '<span class="badge bg-primary text-uppercase">'.__('locale.labels.in_review').'</span>';
                } else {
                    $approved = '<span class="badge bg-danger text-uppercase">'.__('locale.labels.block').'</span>';
                }

                $nestedData['responsive_id'] = '';
                $nestedData['uid']           = $template->uid;
                $nestedData['name']          = $template->name;
                $nestedData['is_dlt']        = $is_dlt;
                $nestedData['approved']      = $approved;
                $nestedData['message']       = $message;
                $nestedData['status']        = "<div class='form-check form-switch form-check-primary'>
                <input type='checkbox' class='form-check-input get_status' id='status_$template->uid' data-id='$template->uid' name='status' $status>
                <label class='form-check-label' for='status_$template->uid'>
                  <span class='switch-icon-left'><i data-feather='check'></i> </span>
                  <span class='switch-icon-right'><i data-feather='x'></i> </span>
                </label>
              </div>";

                $nestedData['edit'] = route('customer.templates.show', $template->uid);
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
        $this->authorize('sms_template');

        $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('templates'), 'name' => __('locale.menu.SMS Template')],
                ['name' => __('locale.templates.add_template')],
        ];

        $template_tags = TemplateTags::cursor();
        $sender_ids    = Senderid::where('status', 'active')->where('user_id', Auth::user()->id)->cursor();

        return view('customer.Templates.create', compact('breadcrumbs', 'template_tags', 'sender_ids'));
    }


    /**
     * View template for edit
     *
     * @param  Templates  $template
     *
     * @return Application|Factory|View
     *
     * @throws AuthorizationException
     */

    public function show(Templates $template)
    {
        $this->authorize('sms_template');

        $breadcrumbs   = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('templates'), 'name' => __('locale.menu.SMS Template')],
                ['name' => __('locale.templates.update_template')],
        ];
        $template_tags = TemplateTags::cursor();

        return view('customer.Templates.create', compact('breadcrumbs', 'template', 'template_tags'));
    }


    /**
     * store new template
     *
     * @param  StoreTemplate  $request
     *
     * @return RedirectResponse
     */
    public function store(StoreTemplate $request): RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.templates.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        if (config('app.trai_dlt') && Auth::user()->customer->activeSubscription()->plan->is_dlt) {
            $validator = Validator::make($request->all(), [
                    'dlt_template_id' => 'required',
                    'dlt_category'    => 'required',
                    'sender_id'       => 'required|exists:senderid,id',
            ]);

            if ($validator->fails()) {
                return redirect()->route('customer.templates.create')->withErrors($validator->errors());
            }
        }

        $this->templates->store($request->input());

        return redirect()->route('customer.templates.index')->with([
                'status'  => 'success',
                'message' => __('locale.templates.template_successfully_added'),
        ]);

    }


    /**
     * update template
     *
     * @param  Templates  $template
     * @param  UpdateTemplate  $request
     *
     * @return RedirectResponse
     */

    public function update(Templates $template, UpdateTemplate $request): RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->route('customer.templates.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }


        if (config('app.trai_dlt') && Auth::user()->customer->activeSubscription()->plan->is_dlt) {
            return redirect()->route('customer.templates.create')->withInput(['template' => $template])->with([
                    'status'  => 'error',
                    'message' => 'Template update is forbidden in TRAI DLT mode',
            ]);

        }

        $this->templates->update($template, $request->input());

        return redirect()->route('customer.templates.index')->with([
                'status'  => 'success',
                'message' => __('locale.templates.template_successfully_updated'),
        ]);
    }

    /**
     * remove existing template
     *
     * @param  Templates  $template
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

        $this->authorize('sms_template');

        $this->templates->destroy($template);

        return response()->json([
                'status'  => 'success',
                'message' => __('locale.templates.template_successfully_deleted'),
        ]);

    }

    /**
     * change template status
     *
     * @param  Templates  $template
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
            $this->authorize('sms_template');

            if (config('app.trai_dlt') && Auth::user()->customer->activeSubscription()->plan->is_dlt) {
                if ($template->approved == 'in_review' && ! $template->status) {
                    return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.templates.template_is_under_review'),
                    ]);
                } elseif ($template->approved == 'block' && ! $template->status) {
                    return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.templates.template_was_blocked'),
                    ]);
                } else {
                    if ($template->update(['status' => ! $template->status])) {
                        return response()->json([
                                'status'  => 'success',
                                'message' => __('locale.templates.template_successfully_change'),
                        ]);
                    }
                }

            } else {
                if ($template->update(['status' => ! $template->status])) {
                    return response()->json([
                            'status'  => 'success',
                            'message' => __('locale.templates.template_successfully_change'),
                    ]);
                }
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
     * @param  Request  $request
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

        $this->authorize('sms_template');

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
