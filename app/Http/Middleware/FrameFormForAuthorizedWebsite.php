<?php

namespace App\Http\Middleware;

use App\Library\Website\Forms\WebsiteFormFrameAncestors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A public form's own link is framed by the customer's website (public.forms._embed), so its responses must say
 * WHICH sites may frame it: `frame-ancestors 'self'` plus the exact Active custom-domain origins of the Business's
 * published Website — and only for a Website-source deployment (WebsiteFormFrameAncestors). Every other response, including
 * an unknown or disabled link, stays framable by MotionGrove alone. Applied to the public form routes ONLY; login,
 * admin, CRM and every other surface keep their existing framing protection.
 *
 * Modern browsers let `frame-ancestors` supersede `X-Frame-Options`, so an `X-Frame-Options` header this app sets is
 * dropped from the response when an external origin is authorised. A header added by the web server in front of the
 * app is outside this middleware's reach (see docs/product/DEPLOYMENT-READINESS.md §6.6).
 */
class FrameFormForAuthorizedWebsite
{
    public function __construct(private readonly WebsiteFormFrameAncestors $ancestors)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $uid = (string) $request->route('deploymentUid');
        $policy = $this->ancestors->policyFor($uid);

        $response->headers->set('Content-Security-Policy', $policy);

        if ($policy !== "frame-ancestors 'self'") {
            $response->headers->remove('X-Frame-Options');
        }

        return $response;
    }
}
