<?php

    namespace App\Http\Controllers\Admin;


    use App\Http\Requests\SenderID\StoreBlockSenderIDRequest;
    use App\Models\BlockSenderId;
    use App\Repositories\Contracts\BlockSenderIDdRepository;
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

    class BlockSenderIdController extends AdminBaseController
    {

        protected BlockSenderIDdRepository $sender_id;

        /**
         * BlockSenderIDController constructor.
         */
        public function __construct(BlockSenderIDdRepository $sender_id)
        {
            $this->sender_id = $sender_id;
        }

        /**
         * @throws AuthorizationException
         */
        public function index(): Factory|View|Application
        {

            $this->authorize('view block_senderid');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Security')],
                ['name' => __('locale.menu.Block Sender ID')],
            ];

            return view('admin.BlockSenderID.index', compact('breadcrumbs'));
        }

        /**
         * @throws AuthorizationException
         */
        #[NoReturn]
        public function search(Request $request): void
        {

            $this->authorize('view block_senderid');

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'sender_id',
                5 => 'reason',
                6 => 'actions',
            ];

            $totalData = BlockSenderID::count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $sender_ids = BlockSenderID::offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $sender_ids = BlockSenderID::whereLike(['uid', 'sender_id', 'reason'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = BlockSenderID::whereLike(['uid', 'sender_id', 'reason'], $search)->count();

            }

            $data = [];
            if ( ! empty($sender_ids)) {
                foreach ($sender_ids as $sender_id) {

                    if ($sender_id->reason) {
                        $reason = $sender_id->reason;
                    } else {
                        $reason = '--';
                    }

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $sender_id->uid;
                    $nestedData['sender_id']     = $sender_id->sender_id;
                    $nestedData['reason']        = $reason;
                    $nestedData['delete']        = $sender_id->uid;
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

        /**
         * @throws AuthorizationException
         */
        public function create(): Factory|View|Application
        {
            $this->authorize('create block_senderid');

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . '/block-senderid'), 'name' => __('locale.menu.Block Sender ID')],
                ['name' => __('locale.block_senderid.block_new')],
            ];

            return view('admin.BlockSenderID.create', compact('breadcrumbs'));
        }

        public function store(StoreBlockSenderIDRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.block-senderid.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->sender_id->store($request->input());

            return redirect()->route('admin.block-senderid.index')->with([
                'status'  => 'success',
                'message' => __('locale.block_senderid.blocked_success'),
            ]);

        }

        /**
         * @throws AuthorizationException
         */
        public function destroy(BlockSenderID $block_senderid): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }
            $this->authorize('delete block_senderid');

            $this->sender_id->destroy($block_senderid);

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.block_senderid.deleted_success'),
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

            if ($action == 'destroy') {
                $this->authorize('delete block_senderid');

                $this->sender_id->batchDestroy($ids);

                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.block_senderid.bulk_delete_success'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
            ]);

        }

        /**
         * @throws AuthorizationException
         */
        public function export(): BinaryFileResponse|RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('admin.senderId.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $this->authorize('view block_senderid');

            try {
                $file_name = (new FastExcel($this->blockSenderID()))->export(storage_path('block_senderId_' . time() . '.xlsx'));

                return response()->download($file_name);
            } catch (IOException|InvalidArgumentException|UnsupportedTypeException|WriterNotOpenedException $e) {
                return redirect()->route('admin.block-senderid.index')->with([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        public function blockSenderID(): Generator
        {
            foreach (BlockSenderID::cursor() as $sender_id) {
                yield $sender_id;
            }
        }

    }
