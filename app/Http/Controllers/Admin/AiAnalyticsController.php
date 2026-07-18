<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class AiAnalyticsController extends Controller
{
    public function index(Request $request)
    {
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        $campaignId = $request->get('campaign_id');

        // ===================== STAGE COUNTS =====================
        $stageQuery = DB::table('chat_boxes as cb')
            ->leftJoin('ai_box_campaign_map as map', 'cb.id', '=', 'map.box_id')
            ->select('cb.ai_stage', DB::raw('COUNT(*) as count'))
            ->where('cb.ai_stage', '>', 0);

        if ($campaignId) {
    $stageQuery->where('map.campaign_id', $campaignId);
} else {
    $stageQuery->where('map.campaign_id', '>=', 430);
}

        $stageCounts = $stageQuery->groupBy('cb.ai_stage')->get();

      $stageCountsArray = [
    1 => 0,
    2 => 0,
    3 => 0,
    4 => 0,
    5 => 0,
    6 => 0, // BOOKED
    99 => 0
];

        foreach ($stageCounts as $row) {
            $stageCountsArray[$row->ai_stage] = $row->count;
        }

        // ===================== RECENT BOXES =====================
        $recentQuery = DB::table('chat_boxes as cb')
            ->leftJoin('ai_box_campaign_map as map', 'cb.id', '=', 'map.box_id')
           ->select('cb.id', 'cb.to', 'cb.ai_stage', 'cb.updated_at')
            ->orderByDesc('cb.updated_at')
            ->limit(500);

       if ($campaignId) {
    $recentQuery->where('map.campaign_id', $campaignId);
} else {
    $recentQuery->where('map.campaign_id', '>=', 425);
}

        $recentBoxes = $recentQuery->get();

        // ===================== CAMPAIGNS =====================
        $campaigns = DB::table('campaigns')
            ->select('id', 'campaign_name as name')
            ->orderByDesc('id')
            ->get();

        return view('admin.ai_analytics', [
            'stageCounts' => $stageCountsArray,
            'recentBoxes' => $recentBoxes,
            'campaigns'   => $campaigns,
            'campaignId'  => $campaignId,
            'campaignFilterEnabled' => true
        ]);
    }
    
    
    
    public function markBooked($id)
{
    DB::table('chat_boxes')
        ->where('id', $id)
        ->update([
            'ai_stage' => 6,
            'followup_sent' => 1,
            'followup_at' => null,
            'reply_by_customer' => 0,
            'ai_replied' => 1,
            'updated_at' => now()
        ]);

    return redirect()->back()->with('success', 'Conversation marked as booked.');
}
    
    
    
    
    
}