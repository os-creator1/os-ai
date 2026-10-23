<?php

namespace App\Library\Business;

use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * The two customer-facing links an automation message may contain, resolved from the Business's own records only
 * (never a fabricated URL): the Google/other review link the owner saved for their primary Location, and the
 * public link of their event questionnaire form. Null means "not set up" — callers must not send a message that
 * promises a link they do not have.
 */
final class BusinessLinks
{
    public static function reviewLink(Business $business): ?string
    {
        $primary = $business->primaryLocation()->value('id');

        $query = DB::table('seo_location_review_links')->where('business_id', $business->id);
        if ($primary !== null) {
            $query->orderByRaw('business_location_id = ? desc', [$primary]);
        }
        $url = $query->orderBy('id')->value('review_url');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    /** The shareable page of the first active form whose name contains "questionnaire". */
    public static function questionnaireLink(Business $business): ?string
    {
        $uid = DB::table('form_deployments as d')
            ->join('forms as f', 'f.id', '=', 'd.form_id')
            ->where('f.business_id', $business->id)
            ->where('f.lifecycle_state', 'active')
            ->where('d.is_enabled', true)
            ->where('d.source', 'direct_link')
            ->whereRaw('lower(f.name) like ?', ['%questionnaire%'])
            ->orderBy('d.id')
            ->value('d.uid');

        return $uid === null ? null : route('public.forms.show', [$uid]);
    }
}
