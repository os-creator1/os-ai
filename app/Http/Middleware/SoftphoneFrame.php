<?php

namespace App\Http\Middleware;

use App\Library\Voice\ConversationCalling;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Call continuity across page navigation.
 *
 * A WebRTC call lives in the JavaScript realm of the page that placed it, and a normal navigation destroys that
 * realm. So that the approved floating call popup (and the call behind it) can stay up while the person moves
 * between pages, an eligible user's top-level page request is answered with a thin SHELL document: it owns the
 * softphone, the Telnyx client and the call, and shows the requested page in a full-viewport same-origin frame.
 * Every navigation inside the app then happens inside the frame, so the shell, the call and the popup are never
 * unloaded. Nothing is simulated: closing or reloading the shell itself still ends the call, because that really
 * destroys the audio.
 *
 * Applies only to a top-level HTML GET (Sec-Fetch-Dest: document) of a Business-scoped page, for a user whose
 * browser calling is available there. Every other request — XHR, forms, downloads, an old browser that sends no
 * fetch metadata, a user who cannot call — is served exactly as before.
 *
 * A framed response that would leave the app (an OAuth redirect to Google/Microsoft, the login page after the
 * session expired) breaks out of the frame instead of rendering inside it, since those pages refuse framing.
 */
class SoftphoneFrame
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly ConversationCalling $calling,
        private readonly BusinessRouteAccess $access,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $destination = strtolower((string) $request->header('Sec-Fetch-Dest', ''));

        if ($destination === 'iframe') {
            return $this->breakOutOfFrameWhenLeavingTheApp($request, $next($request));
        }

        if ($destination !== 'document'
            || ! $request->isMethod('GET')
            || strtolower((string) $request->header('Sec-Fetch-Mode', '')) !== 'navigate'
            || $request->ajax()
            || $request->expectsJson()
            || $request->query('mgframe') === '0') {
            return $next($request);
        }

        $workspaceUid = $request->route('workspaceUid');
        $businessUid = $request->route('businessUid');
        $user = $request->user();

        if (! is_string($workspaceUid) || ! is_string($businessUid) || $user === null) {
            return $next($request);
        }

        $workspace = $this->workspaces->findByUid($workspaceUid);
        $business = $workspace instanceof Workspace ? $this->workspaces->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid) : null;

        // Anything the shell cannot prove is the normal page's job to answer (and to refuse with its own 404).
        if ($business === null || ! $this->access->actorMayUseBusinessRoute($user, $workspace, $business)) {
            return $next($request);
        }

        $config = $this->calling->config($workspace, $business);

        if ($config === null) {
            return $next($request);
        }

        return response()
            ->view('customer.softphone-shell', ['src' => $request->getRequestUri(), 'browserCalling' => $config])
            ->header('Cache-Control', 'no-store, private')
            ->header('Vary', 'Sec-Fetch-Dest');
    }

    private function breakOutOfFrameWhenLeavingTheApp(Request $request, Response $response): Response
    {
        if (! $response->isRedirection()) {
            return $response;
        }

        $target = (string) $response->headers->get('Location', '');
        $parts = parse_url($target) ?: [];
        $sameHost = ! isset($parts['host']) || strcasecmp((string) $parts['host'], $request->getHost()) === 0;

        if ($sameHost && preg_match('#^/workspaces/[^/]+/businesses/#', (string) ($parts['path'] ?? '')) === 1) {
            return $response;
        }

        $safe = e($target);
        $js = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return response('<!doctype html><meta charset="utf-8"><title>Redirecting…</title><p><a href="' . $safe . '" target="_top">Continue</a></p><script>window.top.location.href = ' . $js . ';</script>')
            ->header('Cache-Control', 'no-store, private')
            ->header('Vary', 'Sec-Fetch-Dest');
    }
}
