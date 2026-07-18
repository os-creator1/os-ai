<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HotLeadController extends Controller
{
    public function index()
    {
        $leads = DB::table('chat_boxes')
            ->where('ai_stage', 4)
            ->where('called', 0)
            ->orderByDesc('website_sent_at')
            ->get();

        return view('admin.hot_leads', compact('leads'));
    }

    public function markCalled(Request $request)
    {
        DB::table('chat_boxes')
            ->where('id', $request->id)
            ->update(['called' => 1]);

        return redirect()->back();
    }
}
