<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\SpamWord\StoreWord;
use App\Models\SpamWord;
use App\Repositories\Contracts\SpamWordRepository;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JetBrains\PhpStorm\NoReturn;
use OpenSpout\Common\Exception\InvalidArgumentException;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Common\Exception\UnsupportedTypeException;
use OpenSpout\Writer\Exception\WriterNotOpenedException;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SpamWordController extends AdminBaseController
{
    protected SpamWordRepository $spam_words;

    /**
     * SpamWordController constructor.
     *
     * @param  SpamWordRepository  $spam_words
     */

    public function __construct(SpamWordRepository $spam_words)
    {
        $this->spam_words = $spam_words;
    }


    /**
     * view all spam words
     *
     * @return Application|Factory|View
     * @throws AuthorizationException
     */

    public function index(): View|Factory|Application
    {

        $this->authorize('view spam_word');

        $breadcrumbs = [
                ['link' => url(config('app.admin_path')."/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path')."/dashboard"), 'name' => __('locale.menu.Security')],
                ['name' => __('locale.menu.Spam Word')],
        ];

        return view('admin.SpamWord.index', compact('breadcrumbs'));
    }


    /**
     * @param  Request  $request
     *
     * @return void
     * @throws AuthorizationException
     */
    #[NoReturn] public function search(Request $request): void
    {

        $this->authorize('view spam_word');

        $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'word',
                4 => 'action',
        ];

        $totalData = SpamWord::count();

        $totalFiltered = $totalData;

        $limit = $request->input('length');
        $start = $request->input('start');
        $order = $columns[$request->input('order.0.column')];
        $dir   = $request->input('order.0.dir');

        if (empty($request->input('search.value'))) {
            $spam_word = SpamWord::offset($start)
                                 ->limit($limit)
                                 ->orderBy($order, $dir)
                                 ->get();
        } else {
            $search = $request->input('search.value');

            $spam_word = SpamWord::whereLike(['uid', 'word'], $search)
                                 ->offset($start)
                                 ->limit($limit)
                                 ->orderBy($order, $dir)
                                 ->get();

            $totalFiltered = SpamWord::whereLike(['uid', 'word'], $search)->count();

        }

        $data = [];
        if ( ! empty($spam_word)) {
            foreach ($spam_word as $word) {

                $nestedData['responsive_id'] = '';
                $nestedData['uid']           = $word->uid;
                $nestedData['word']          = $word->word;
                $data[]                      = $nestedData;

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
     * @return Application|Factory|View
     * @throws AuthorizationException
     */

    public function create(): View|Factory|Application
    {
        $this->authorize('create spam_word');

        $breadcrumbs = [
                ['link' => url(config('app.admin_path')."/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path')."/spam-word"), 'name' => __('locale.menu.Spam Word')],
                ['name' => __('locale.spam_word.add_new_word')],
        ];

        return view('admin.SpamWord.create', compact('breadcrumbs'));
    }


    /**
     * store new spam word
     *
     * @param  StoreWord  $request
     *
     * @return RedirectResponse
     */

    public function store(StoreWord $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('admin.spam-word.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $this->spam_words->store($request->input());

        return redirect()->route('admin.spam-word.index')->with([
                'status'  => 'success',
                'message' => __('locale.spam_word.word_successfully_added'),
        ]);

    }


    /**
     * delete spam word
     *
     * @param  SpamWord  $spam_word
     *
     * @return JsonResponse
     * @throws AuthorizationException
     *
     */

    public function destroy(SpamWord $spam_word): JsonResponse
    {

        if (config('app.stage') == 'demo') {
            return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $this->authorize('delete spam_word');

        $this->spam_words->destroy($spam_word);

        return response()->json([
                'status'  => 'success',
                'message' => __('locale.spam_word.word_successfully_deleted'),
        ]);

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

        $action = $request->get('action');
        $ids    = $request->get('ids');

        if ($action == 'destroy') {
            $this->authorize('delete spam_word');

            $this->spam_words->batchDestroy($ids);

            return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.spam_word.words_deleted'),
            ]);
        }

        return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
        ]);

    }


    /**
     * yield spam word generator
     *
     * @return Generator
     */

    public function SpamWordGenerator(): Generator
    {
        foreach (SpamWord::cursor() as $blacklist) {
            yield $blacklist;
        }
    }

    /**
     * @return RedirectResponse|BinaryFileResponse
     * @throws AuthorizationException
     */
    public function export(): BinaryFileResponse|RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('admin.spam-word.index')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $this->authorize('view spam_word');

        try {
            $file_name = (new FastExcel($this->SpamWordGenerator()))->export(storage_path('spam_word_'.time().'.xlsx'));

            return response()->download($file_name);

        } catch (IOException|InvalidArgumentException|UnsupportedTypeException|WriterNotOpenedException $e) {
            return redirect()->route('admin.spam-word.index')->with([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
            ]);
        }


    }

}
