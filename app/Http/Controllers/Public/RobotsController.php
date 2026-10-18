<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\Website\Seo\WebsiteCrawlFiles;
use Illuminate\Http\Response;

/**
 * The platform host's robots.txt: everything allowed. It is a route (not a static file) so that a web
 * server which serves real files first can never shadow it, and a customer's own domain gets its OWN
 * robots.txt (with that site's Sitemap line) from ResolveCustomDomainWebsite. Both come from the one
 * generator, WebsiteCrawlFiles, so the platform and every custom domain agree on shape and line endings.
 */
class RobotsController extends Controller
{
    public function show(): Response
    {
        return response(WebsiteCrawlFiles::platformRobots(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
