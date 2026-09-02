<?php

namespace App\Http\Controllers\Admin;

use App\Models\Campaigns;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AiAnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('chat_box');

        $campaignId = $request->get('campaign_id');

        $ownedCampaignId = null;
        if ($campaignId !== null && $campaignId !== '') {
            $isOwnedCampaign = Campaigns::where('id', $campaignId)
                ->where('user_id', Auth::id())
                ->exists();

            if ($isOwnedCampaign) {
                $ownedCampaignId = $campaignId;
            }
        }

        // ===================== STAGE COUNTS =====================
        $stageQuery = DB::table('chat_boxes as cb')
            ->leftJoin('ai_box_campaign_map as map', 'cb.id', '=', 'map.box_id')
            ->select('cb.ai_stage', DB::raw('COUNT(*) as count'))
            ->where('cb.user_id', Auth::id())
            ->where('cb.ai_stage', '>', 0);

        if ($ownedCampaignId !== null) {
            $stageQuery->where('map.campaign_id', $ownedCampaignId);
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
            ->where('cb.user_id', Auth::id())
            ->orderByDesc('cb.updated_at')
            ->limit(500);

        if ($ownedCampaignId !== null) {
            $recentQuery->where('map.campaign_id', $ownedCampaignId);
        }

        $recentBoxes = $recentQuery->get();

        // ===================== CAMPAIGNS =====================
        $campaigns = DB::table('campaigns')
            ->where('user_id', Auth::id())
            ->select('id', 'campaign_name as name')
            ->orderByDesc('id')
            ->get();

        return view('admin.ai_analytics', [
            'stageCounts' => $stageCountsArray,
            'recentBoxes' => $recentBoxes,
            'campaigns'   => $campaigns,
            'campaignId'  => $ownedCampaignId,
            'campaignFilterEnabled' => true
        ]);
    }



    public function markBooked($id)
{
    $this->authorize('chat_box');

    $updated = DB::table('chat_boxes')
        ->where('id', $id)
        ->where('user_id', Auth::id())
        ->update([
            'ai_stage' => 6,
            'followup_sent' => 1,
            'followup_at' => null,
            'reply_by_customer' => 0,
            'ai_replied' => 1,
            'updated_at' => now()
        ]);

    if (! $updated) {
        abort(404);
    }

    return redirect()->back()->with('success', 'Conversation marked as booked.');
}




}