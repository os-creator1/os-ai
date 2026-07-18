<?php

    namespace App\Http\Controllers\Admin;

    use App\Http\Requests\Customer\StoreAnnouncementRequest;
    use App\Http\Requests\Customer\UpdateAnnouncementRequest;
    use App\Library\Tool;
    use App\Models\Announcements;
    use App\Models\SendingServer;
    use App\Models\User;
    use App\Repositories\Contracts\AnnouncementsRepository;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\View\View;
    use JetBrains\PhpStorm\NoReturn;
    use Psr\Container\ContainerExceptionInterface;
    use Psr\Container\NotFoundExceptionInterface;

    class AnnouncementsController extends AdminBaseController
    {
        protected AnnouncementsRepository $announcements;

        /**
         * AnnouncementsController constructor.
         */
        public function __construct(AnnouncementsRepository $announcements)
        {
            $this->announcements = $announcements;
        }

        /**
         */
        public function index(): Factory|View|Application
        {

            $this->authorize('view announcement');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Customer')],
                ['name' => __('locale.menu.Announcements')],
            ];

            $tab = request()->input('tab') ?? 'announcements';

            return view('admin.Announcements.index', compact('breadcrumbs', 'tab'));
        }

        /**
         * @throws AuthorizationException
         */
        #[NoReturn]
        public function search(Request $request): void
        {

            $this->authorize('view announcement');

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'date',
                4 => 'title',
                5 => 'actions',
            ];

            $totalData = Announcements::count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $announcements = Announcements::offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $announcements = Announcements::whereLike(['uid', 'title'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = Announcements::whereLike(['uid', 'title'], $search)->count();
            }

            $data = [];
            if ( ! empty($announcements)) {
                foreach ($announcements as $announcement) {
                    $show = route('admin.announcements.show', $announcement->uid);

                    $edit   = null;
                    $delete = null;

                    if (Auth::user()->can('edit announcement')) {
                        $edit .= $show;
                    }

                    if (Auth::user()->can('delete announcement')) {
                        $delete .= $announcement->uid;
                    }

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $announcement->uid;
                    $nestedData['created_at']    = Tool::formatHumanTime($announcement->created_at);
                    $nestedData['title']         = $announcement->title;
                    $nestedData['edit']          = $edit;
                    $nestedData['delete']        = $delete;
                    $data[]                      = $nestedData;

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

        public function create(Request $request)
        {
            $this->authorize('create announcement');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/announcements'), 'name' => __('locale.menu.Announcements')],
                ['name' => __('locale.announcements.send_announcement')],
            ];

            $tab          = $request->get('tab');
            $getCustomers = User::where('status', 1)->where('is_customer', true)->select('id', 'first_name', 'last_name');

            $sendingServers = null;

            if ($tab == 'send_by_sms') {
                $sendingServers = SendingServer::where('status', 1)->where('plain', true)->select('id', 'name')->get();
                $getCustomers->whereHas('customer', function ($query) {
                    $query->where('phone', '!=', '');
                });
            }

            $customers = $getCustomers->get();

            return view('admin.Announcements.create', compact('breadcrumbs', 'customers', 'tab', 'sendingServers'));

        }

        public function store(StoreAnnouncementRequest $request)
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.announcements.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $input = $request->except('_token');

            $data = $this->announcements->store($input);

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return redirect()->route('admin.announcements.index')->with([
                        'status'  => 'success',
                        'message' => __('locale.announcements.announcement_sent_successfully'),
                    ]);
                } else {
                    return redirect()->route('admin.announcements.index')->withInput(['tab' => $request->get('send_by')])->with([
                        'status'  => 'error',
                        'message' => $data->getData()->message,
                    ]);
                }
            }

            return redirect()->route('admin.announcements.index')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        public function show(Announcements $announcement, Request $request)
        {

            $this->authorize('edit announcement');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/announcements'), 'name' => __('locale.menu.Announcements')],
                ['name' => __('locale.announcements.update_announcement')],
            ];

            $send_by = $announcement->type == 'email' ? 'send_by_email' : 'send_by_sms';

            $tab          = $request->get('tab') ?? $send_by;
            $getCustomers = User::where('status', 1)->where('is_customer', true)->select('id', 'first_name', 'last_name');

            $sendingServers = null;

            if ($tab == 'send_by_sms') {
                $sendingServers = SendingServer::where('status', 1)->where('plain', true)->select('id', 'name')->get();
                $getCustomers->whereHas('customer', function ($query) {
                    $query->where('phone', '!=', '');
                });
            }

            $customers = $getCustomers->get();

            return view('admin.Announcements.create', compact('breadcrumbs', 'customers', 'tab', 'sendingServers', 'announcement'));

        }

        public function update(Announcements $announcement, UpdateAnnouncementRequest $request)
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.announcements.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $input = $request->only('title', 'description');
            $data  = $this->announcements->update($announcement, $input);

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {
                    return redirect()->route('admin.announcements.index')->with([
                        'status'  => 'success',
                        'message' => __('locale.announcements.announcement_updated_successfully'),
                    ]);
                } else {
                    return redirect()->route('admin.announcements.index')->withInput(['tab' => $request->get('send_by')])->with([
                        'status'  => 'error',
                        'message' => $data->getData()->message,
                    ]);
                }
            }

            return redirect()->route('admin.announcements.index')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }

        /**
         * Destroy an announcement.
         *
         * @param Announcements $announcement description
         * @return JsonResponse
         */

        public function destroy(Announcements $announcement): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('delete announcement');

            $data = $this->announcements->destroy($announcement);


            if (isset($data->getData()->status)) {
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

            $this->authorize('delete announcement');

            $action = $request->get('action');
            $ids    = $request->get('ids');

            if ($action == 'destroy') {
                $data = $this->announcements->batchDestroy($ids);
                if (isset($data->getData()->status)) {
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

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
            ]);

        }

    }
