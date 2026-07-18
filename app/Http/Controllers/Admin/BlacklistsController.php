<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Blacklists\StoreBlacklist;
use App\Models\Blacklists;
use App\Repositories\Contracts\BlacklistsRepository;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use JetBrains\PhpStorm\NoReturn;
use OpenSpout\Common\Exception\InvalidArgumentException;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Common\Exception\UnsupportedTypeException;
use OpenSpout\Writer\Exception\WriterNotOpenedException;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BlacklistsController extends AdminBaseController
{
    protected BlacklistsRepository $blacklists;

    /**
     * BlacklistsController constructor.
     */
    public function __construct(BlacklistsRepository $blacklists)
    {
        $this->blacklists = $blacklists;
    }

    /**
     * @throws AuthorizationException
     */
    public function index(): Factory|View|Application
    {

        $this->authorize('view blacklist');

        $breadcrumbs = [
            ['link' => url(config('app.admin_path').'/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => url(config('app.admin_path').'/dashboard'), 'name' => __('locale.menu.Security')],
            ['name' => __('locale.menu.Blacklist')],
        ];

        return view('admin.Blacklists.index', compact('breadcrumbs'));
    }

    /**
     * @throws AuthorizationException
     */
    #[NoReturn]
    public function search(Request $request): void
    {

        $this->authorize('view blacklist');

        $columns = [
            0 => 'responsive_id',
            1 => 'uid',
            2 => 'uid',
            3 => 'number',
            4 => 'user_id',
            5 => 'reason',
            6 => 'actions',
        ];

        $totalData = Blacklists::count();

        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir = $request->input('order.0.dir');

        if (empty($request->input('search.value'))) {
            $blacklists = Blacklists::offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();
        } else {
            $search = $request->input('search.value');

            $blacklists = Blacklists::whereLike(['uid', 'number', 'reason', 'user.first_name', 'user.last_name'], $search)
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = Blacklists::whereLike(['uid', 'number', 'reason', 'user.first_name', 'user.last_name'], $search)->count();

        }

        $data = [];
        if (! empty($blacklists)) {
            foreach ($blacklists as $blacklist) {

                if ($blacklist->reason) {
                    $reason = $blacklist->reason;
                } else {
                    $reason = '--';
                }

                if ($blacklist->user->is_admin) {
                    $assign_to = $blacklist->user->displayName();
                } else {

                    $customer_profile = route('admin.customers.show', $blacklist->user->uid);
                    $customer_name = $blacklist->user->displayName();
                    $assign_to = "<a href='$customer_profile' class='text-primary mr-1'>$customer_name</a>";
                }

                $nestedData['responsive_id'] = '';
                $nestedData['uid'] = $blacklist->uid;
                $nestedData['number'] = $blacklist->number;
                $nestedData['user_id'] = $assign_to;
                $nestedData['reason'] = $reason;
                $nestedData['delete'] = $blacklist->uid;
                $data[] = $nestedData;

            }
        }

        $json_data = [
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $totalData,
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ];

        echo json_encode($json_data);
        exit();

    }

    /**
     * @throws AuthorizationException
     */
    public function create(): Factory|View|Application
    {
        $this->authorize('create blacklist');

        $breadcrumbs = [
            ['link' => url(config('app.admin_path').'/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => url(config('app.admin_path').'/blacklists'), 'name' => __('locale.menu.Blacklist')],
            ['name' => __('locale.blacklist.add_new_blacklist')],
        ];

        return view('admin.Blacklists.create', compact('breadcrumbs'));
    }

    public function store(StoreBlacklist $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('admin.blacklists.index')->with([
                'status' => 'error',
                'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $this->blacklists->store($request->input());

        return redirect()->route('admin.blacklists.index')->with([
            'status' => 'success',
            'message' => __('locale.blacklist.blacklist_successfully_added'),
        ]);

    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Blacklists $blacklist): JsonResponse
    {
        if (config('app.stage') == 'demo') {
            return response()->json([
                'status' => 'error',
                'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }
        $this->authorize('delete blacklist');

        $this->blacklists->destroy($blacklist);

        return response()->json([
            'status' => 'success',
            'message' => __('locale.blacklist.blacklist_successfully_deleted'),
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
                'status' => 'error',
                'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $action = $request->get('action');
        $ids = $request->get('ids');

        if ($action == 'destroy') {
            $this->authorize('delete blacklist');

            $this->blacklists->batchDestroy($ids);

            return response()->json([
                'status' => 'success',
                'message' => __('locale.blacklist.blacklists_deleted'),
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => __('locale.exceptions.invalid_action'),
        ]);

    }

    /**
     * @throws AuthorizationException
     */
    public function export(): BinaryFileResponse|RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('admin.blacklists.index')->with([
                'status' => 'error',
                'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $this->authorize('view blacklist');

        try {
            $file_name = (new FastExcel($this->blacklistsGenerator()))->export(storage_path('blacklists_'.time().'.xlsx'));

            return response()->download($file_name);
        } catch (IOException|InvalidArgumentException|UnsupportedTypeException|WriterNotOpenedException $e) {
            return redirect()->route('admin.blacklists.index')->with([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function blacklistsGenerator(): Generator
    {
        foreach (Blacklists::cursor() as $blacklist) {
            yield $blacklist;
        }
    }
}
