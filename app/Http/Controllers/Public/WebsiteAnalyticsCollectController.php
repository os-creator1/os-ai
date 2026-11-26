<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\WebsiteAnalytics\AnalyticsRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The single public collection endpoint for native website analytics (Agency landing pages and Growth websites alike).
 *
 * Anonymous and unauthenticated by design: the only thing the browser names is the opaque property key. Tenant,
 * ownership, liveness and which calendar belongs to the site are all resolved server-side (AnalyticsRecorder).
 * Always answers 204, whatever happened, so the endpoint reveals nothing about which keys or submissions exist.
 * Cross-origin calls from a customer's own domain are text/plain simple requests; the response allows them but is empty.
 */
class WebsiteAnalyticsCollectController extends Controller
{
    private const MAX_BODY = 2048;

    public function __construct(private readonly AnalyticsRecorder $recorder) {}

    public function collect(Request $request): Response
    {
        $reply = response('', 204)->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'no-store');

        $body = (string) $request->getContent();

        if ($body === '' || strlen($body) > self::MAX_BODY || AnalyticsRecorder::ignoredClient($request)) {
            return $reply;
        }

        $payload = json_decode($body, true);

        if (is_array($payload)) {
            try {
                $this->recorder->recordBeacon($payload, $request);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $reply;
    }
}
