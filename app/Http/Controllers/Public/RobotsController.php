<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * The platform host's robots.txt: everything allowed — byte for byte what the static public/robots.txt
 * said. It is a route (not a file) so that a web server which serves real files first can never shadow
 * it: a customer's own domain gets its OWN robots.txt (with that site's Sitemap line) from
 * ResolveCustomDomainWebsite, which only runs when the request reaches Laravel at all.
 */
class RobotsController extends Controller
{
    public function show(): Response
    {
        return response("User-agent: *\nDisallow:\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
