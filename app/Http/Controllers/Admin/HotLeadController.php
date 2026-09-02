<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarkHotLeadCalledRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HotLeadController extends Controller
{
    public function index()
    {
        $this->authorize('chat_box');

        $leads = DB::table('chat_boxes')
            ->where('user_id', Auth::id())
            ->where('ai_stage', 4)
            ->where('called', 0)
            ->orderByDesc('website_sent_at')
            ->get();

        return view('admin.hot_leads', compact('leads'));
    }

    public function markCalled(MarkHotLeadCalledRequest $request)
    {
        $this->authorize('chat_box');

        $updated = DB::table('chat_boxes')
            ->where('id', $request->id)
            ->where('user_id', Auth::id())
            ->update(['called' => 1]);

        if (! $updated) {
            abort(404);
        }

        return redirect()->back();
    }
}
