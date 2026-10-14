<?php

namespace App\Library\ExternalSite\Audit;

/**
 * The CLOSED, deterministic rule catalogue of the external-website audit (rule set
 * version 2): for every rule, what it is called, which part of the report it
 * belongs to, how serious it is for each kind of page, and every sentence the
 * owner reads about it.
 *
 * THE ONE PLACE THE WORDS COME FROM. A finding stores a rule key and bounded
 * evidence (URLs and measured values); everything a person reads — what the
 * problem is, why it matters, how to fix it, how MotionGrove will check the fix —
 * is composed from this catalogue. There is no AI text and no free text from the
 * crawled site, so a hostile page cannot put words in the report.
 *
 * FOUR KINDS, kept apart on purpose:
 *   problem      a confirmed technical problem the crawler observed (broken links,
 *                a noindex on an important page, an invalid structured-data block ...)
 *   improvement  a search-engine improvement (titles, descriptions, headings, alt text ...)
 *   opportunity  a growth opportunity: structure and local-business signals that
 *                could help, which are suggestions rather than defects
 *   info         an observation: what the crawler could not do or was not asked to
 *
 * SEVERITY IS A TABLE, NOT A GUESS. `priority` maps a page IMPORTANCE (home, key,
 * standard, utility: see ExternalPageImportance) to critical / high / medium / low /
 * info, so a missing title on the home page and on a privacy policy are not the same
 * problem. `*` is the default. Evaluator adjustments (for example a broken link
 * FROM the home page) are made in the evaluator, never here.
 *
 * Nothing here promises a ranking, traffic or revenue change. MotionGrove cannot
 * edit an externally hosted site, so every fix is written for the owner's own
 * editor or developer.
 */
final class ExternalAuditCatalog
{
    /** Bump only when a rule's meaning changes; stored on every crawl. */
    public const VERSION = 2;

    public const CATEGORIES = [
        'crawl' => 'Crawlability & Indexing',
        'metadata' => 'Metadata',
        'headings' => 'Headings & Content Structure',
        'links' => 'Internal Links',
        'images' => 'Images',
        'structured' => 'Structured Data',
        'local' => 'Local SEO',
        'performance' => 'Performance / Not Measured',
    ];

    public const KINDS = [
        'problem' => 'Confirmed technical problem',
        'improvement' => 'SEO improvement',
        'opportunity' => 'Growth opportunity',
        'info' => 'Observation',
    ];

    public const PRIORITIES = ['critical', 'high', 'medium', 'low', 'info'];

    public const PRIORITY_LABELS = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'info' => 'Info'];

    private const EDITOR = 'Open the page in your website editor (or ask whoever looks after your site).';

    /**
     * priority: importance => priority; `*` = any other importance.
     * confidence: confirmed = observed directly; heuristic = our interpretation of an observation.
     * effort: quick (minutes, in the editor) | moderate (needs some care) | technical (usually a developer or hosting settings).
     *
     * @var array<string, array<string, mixed>>
     */
    private const RULES = [
        // ------------------------------------------------------------ crawlability & indexing
        'home_page_error' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'technical',
            'priority' => ['*' => 'critical'],
            'title' => 'Home page returns an error',
            'what' => 'When our crawler asked for your home page, your website answered with an error instead of the page.',
            'why' => 'The home page is the first page most visitors and search engines reach. A page that answers with an error cannot be shown to anyone who asks for it the same way.',
            'proposal' => 'Make the home page load normally again (or point the address to the right page).',
            'steps' => ['Open your website address in a browser to see what visitors see.', 'If it is also broken for you, contact your hosting provider or whoever built the site, and give them the status code shown above.', 'If it loads fine for you, the error may depend on location or timing: ask your host to check the server logs for that status code.'],
            'verify' => 'On the next check MotionGrove requests the home page again; the finding disappears when it answers with a normal page.',
        ],
        'home_page_unreachable' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'heuristic', 'effort' => 'technical',
            'priority' => ['*' => 'high'],
            'title' => 'Home page could not be reached from our servers',
            'what' => 'Our crawler could not complete a connection to your home page (the name did not resolve, the connection timed out, or the secure-connection handshake failed), even after retrying.',
            'why' => 'This does not prove visitors cannot reach your site: the problem could be temporary or specific to our servers. If it is real, nobody can open the site.',
            'proposal' => 'Confirm the website opens from a different network, then check the domain, hosting and certificate settings.',
            'steps' => ['Open your address from your phone on mobile data and from a computer; note whether it loads.', 'If it fails there too, check that the domain has not expired and that its DNS records point at your host.', 'For a certificate error, renew or reinstall the SSL certificate with your host.'],
            'verify' => 'The next check retries the connection; the finding disappears once the home page can be fetched.',
        ],
        'crawler_blocked' => [
            'category' => 'crawl', 'kind' => 'info', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'technical',
            'priority' => ['*' => 'info'],
            'title' => 'Pages our crawler was not allowed to check',
            'what' => 'A firewall, bot protection or rate limit (for example HTTP 403 or 429, or a "checking your browser" page) turned our crawler away from these pages.',
            'why' => 'This says nothing about whether the pages work for people, so MotionGrove does not report them as broken and does not judge their content. They are simply not audited.',
            'proposal' => 'If you want these pages audited, allow the MotionGrove audit crawler in your firewall or CDN settings.',
            'steps' => ['In your CDN or security plugin (for example Cloudflare, Sucuri or Wordfence), look at the blocked-requests log for the user agent "MotionGroveSiteAudit".', 'Add an allow rule for that user agent, or lower the rate limit for it.', 'Do not disable your protection: an allow rule for this one crawler is enough.'],
            'verify' => 'The next check requests the same pages again; they move into the audited list once they answer normally.',
        ],
        'robots_blocks_pages' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'moderate',
            'priority' => ['home' => 'critical', 'key' => 'high', 'standard' => 'low', 'utility' => 'info'],
            'title' => 'robots.txt asks crawlers to stay away from these pages',
            'what' => 'Your robots.txt file asks crawlers like ours not to fetch this page, although the page is linked from your site. MotionGrove obeyed it and did not fetch the page.',
            'why' => 'robots.txt controls crawling. A page that crawlers are told to avoid is often missing from search results, or shown without a description. If you meant to keep it out (a members area, say), nothing needs to change.',
            'proposal' => 'If the page should be found, remove or narrow the Disallow rule that matches it in robots.txt.',
            'steps' => ['Open yourdomain.com/robots.txt in a browser and find the Disallow line that matches the page\'s address.', 'If the page should appear in search, remove or narrow that rule. Keep Disallow rules for private areas such as admin and checkout.', 'Many SEO plugins edit robots.txt for you under their "Tools" or "Crawl settings" screen.'],
            'verify' => 'The next check reads robots.txt again; the finding disappears when the page is no longer disallowed for our crawler.',
        ],
        'robots_unreadable' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => true, 'confidence' => 'confirmed', 'effort' => 'technical',
            'priority' => ['*' => 'medium'],
            'title' => 'robots.txt could not be read',
            'what' => 'We asked for your robots.txt file and your website answered with an error (or refused the request) instead of the file. A site with no robots.txt at all (a plain 404) is fine and is not reported.',
            'why' => 'Search engines may treat a robots.txt that errors as an instruction to stay away for a while, and a firewall that blocks that file may be blocking crawlers generally.',
            'proposal' => 'Make /robots.txt return the file (or a clean 404 if you do not have one).',
            'steps' => ['Open yourdomain.com/robots.txt in a browser. If you see an error page, ask your host or developer why that address fails.', 'If a security plugin or CDN is blocking it, allow requests for /robots.txt.'],
            'verify' => 'The next check requests robots.txt again; the finding disappears when it answers with the file or a plain 404.',
        ],
        'site_not_https' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => true, 'confidence' => 'confirmed', 'effort' => 'technical',
            'priority' => ['*' => 'high'],
            'title' => 'Your home page is served over insecure HTTP',
            'what' => 'The home page loaded over http:// without being moved to a secure https:// address.',
            'why' => 'Browsers label http:// pages as "Not secure", especially on pages with forms, and visitors may leave. Search engines prefer https:// addresses.',
            'proposal' => 'Install an SSL certificate and redirect every http:// address to its https:// version.',
            'steps' => ['Ask your host to enable a free SSL certificate (most hosts offer this in one click).', 'Turn on "force HTTPS" or add a site-wide redirect from http:// to https://.', 'Update the website address in your site settings to start with https://.'],
            'verify' => 'The next check fetches the http:// address and confirms it ends on https://.',
        ],
        'http_not_redirected' => [
            'category' => 'crawl', 'kind' => 'improvement', 'site_level' => true, 'confidence' => 'confirmed', 'effort' => 'technical',
            'priority' => ['*' => 'medium'],
            'title' => 'The http:// version of your site does not redirect to https://',
            'what' => 'Your secure https:// site works, but the same page is also served over plain http:// instead of being redirected.',
            'why' => 'Two copies of every page exist, one of them marked "Not secure". Redirecting concentrates visitors and search engines on the secure version.',
            'proposal' => 'Add a permanent (301) redirect from http:// to https://.',
            'steps' => ['In your hosting panel or CMS, switch on "Force HTTPS" / "Redirect HTTP to HTTPS".', 'If you use a CDN such as Cloudflare, enable "Always use HTTPS" there.'],
            'verify' => 'The next check requests http://yourdomain/ and expects a redirect to https://.',
        ],
        'noindex_page' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'critical', 'key' => 'high', 'standard' => 'medium', 'utility' => 'info'],
            'title' => 'Pages that ask search engines not to list them (noindex)',
            'what' => 'These pages carry a noindex instruction (in the page itself or in an X-Robots-Tag header), which asks search engines to keep them out of results.',
            'why' => 'That is correct for private pages such as thank-you pages. On a page you want customers to find, it keeps the page out of search results.',
            'proposal' => 'Remove the noindex instruction from pages that should be found; keep it on pages that should stay private.',
            'steps' => [self::EDITOR, 'Look for a setting such as "Discourage search engines from indexing this page" or "Hide from search engines" and switch it off. In WordPress also check Settings > Reading for the site-wide option.', 'Save and publish. If the instruction comes from a header (X-Robots-Tag), ask your developer or host to remove it for this page.'],
            'verify' => 'The next check reads the page again; the finding disappears when neither the meta robots tag nor the X-Robots-Tag header contains noindex.',
        ],
        'canonical_conflict' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'moderate',
            'priority' => ['home' => 'high', 'key' => 'high', 'standard' => 'medium', 'utility' => 'low'],
            'title' => 'A page declares more than one preferred address (canonical)',
            'what' => 'The page contains more than one canonical link, and they point to different addresses.',
            'why' => 'Search engines may ignore conflicting canonical instructions, so the page can be treated as a duplicate of something else.',
            'proposal' => 'Keep exactly one canonical link per page, pointing at the address you want listed.',
            'steps' => ['This usually happens when a theme and an SEO plugin both add a canonical tag. Disable the canonical output of one of them.', 'View the page source and search for rel="canonical" to confirm that only one remains.'],
            'verify' => 'The next check counts canonical links on the page; the finding disappears when there is one.',
        ],
        'canonical_target_problem' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'moderate',
            'priority' => ['home' => 'critical', 'key' => 'high', 'standard' => 'high', 'utility' => 'low'],
            'title' => 'A page\'s preferred address (canonical) leads to a broken, redirected or hidden page',
            'what' => 'The canonical address this page declares answers with an error, redirects somewhere else, or is itself marked noindex.',
            'why' => 'A canonical that points to a page that does not work tells search engines to prefer an address that cannot be shown.',
            'proposal' => 'Point the canonical at the working, final address of the page.',
            'steps' => [self::EDITOR, 'Find the "Canonical URL" field in the page\'s SEO settings and replace it with the page\'s own, final address (the one that opens without redirecting).'],
            'verify' => 'The next check re-reads the canonical and the page it points to.',
        ],
        'canonical_off_site' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'moderate',
            'priority' => ['home' => 'high', 'key' => 'high', 'standard' => 'medium', 'utility' => 'low'],
            'title' => 'A page names another website as its preferred address (canonical)',
            'what' => 'The page\'s canonical link points to an address on a different website.',
            'why' => 'That tells search engines your page is a copy of someone else\'s. After a site move or from a copied template this is often a leftover mistake.',
            'proposal' => 'Point the canonical at the page\'s own address on your site, unless the page is intentionally syndicated.',
            'steps' => [self::EDITOR, 'Find the "Canonical URL" field in the SEO settings and replace the other website\'s address with this page\'s own address.'],
            'verify' => 'The next check reads the canonical again.',
        ],
        'redirect_loop' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'technical',
            'priority' => ['home' => 'critical', 'key' => 'critical', 'standard' => 'high', 'utility' => 'medium'],
            'title' => 'Addresses that redirect in a loop',
            'what' => 'Requesting these addresses sends the crawler round in a circle of redirects (or through too many redirects) instead of reaching a page.',
            'why' => 'Browsers show an error such as "too many redirects", so nobody can open the page.',
            'proposal' => 'Fix the redirect rule so the address ends on one final page.',
            'steps' => ['Look at the redirect rules (in your CMS, a redirect plugin, .htaccess or your CDN) that match this address.', 'A common cause is www / non-www or http / https rules that point at each other: keep a single rule that goes to the preferred version.'],
            'verify' => 'The next check follows the redirects again and expects a normal page at the end.',
        ],
        'redirect_chain' => [
            'category' => 'crawl', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'technical',
            'priority' => ['*' => 'low'],
            'title' => 'Addresses that redirect more than once',
            'what' => 'These addresses pass through two or more redirects before reaching the page.',
            'why' => 'Every extra redirect slows the visit slightly and gives search engines more to follow.',
            'proposal' => 'Redirect the first address straight to the final page.',
            'steps' => ['Change the redirect rule so the first address points directly to the last address shown in the chain.', 'Update menu and page links to use the final address.'],
            'verify' => 'The next check counts the redirects again; the finding disappears when there is at most one.',
        ],
        'broken_internal_link' => [
            'category' => 'links', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'high', 'key' => 'high', 'standard' => 'medium', 'utility' => 'low'],
            'title' => 'Links to pages that do not work',
            'what' => 'These links on your pages lead to addresses on your own site that answered with an error (such as 404 Not Found).',
            'why' => 'A visitor who clicks lands on an error page. When the broken link is in a menu or leads to a page where people contact or book you, it can cost enquiries.',
            'proposal' => 'Update each link to the right page, or remove it; restore or redirect the missing page if it should exist.',
            'steps' => ['For each affected page listed above, open the source page in your editor and find the link to the destination address.', 'Point the link at the correct page, or remove it. If the destination page was deleted by mistake, restore it or add a redirect to the best replacement.'],
            'verify' => 'The next check re-reads the source pages and the destinations; each row disappears when the link works or is gone.',
        ],
        'internal_link_to_redirect' => [
            'category' => 'links', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'Links that go through a redirect',
            'what' => 'These links point at an address that redirects to a different final address.',
            'why' => 'The link still works, but it takes a detour. Linking straight to the final address is cleaner and quicker.',
            'proposal' => 'Change each link to the final address shown.',
            'steps' => ['For each row, open the source page in your editor and replace the link with the final address shown as the destination.'],
            'verify' => 'The next check re-reads the source pages; each row disappears when the link points at the final address.',
        ],
        'link_to_canonicalized_page' => [
            'category' => 'links', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'Links to a page that names a different page as its preferred version',
            'what' => 'These links point at a page whose canonical tag says another address is the preferred version.',
            'why' => 'Linking to the preferred address keeps your internal links consistent with what you want search engines to list.',
            'proposal' => 'Link to the preferred address instead.',
            'steps' => ['Open the source page in your editor and replace the link with the canonical (preferred) address shown.'],
            'verify' => 'The next check re-reads the source pages.',
        ],
        'anchor_empty' => [
            'category' => 'links', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'Links with no readable text',
            'what' => 'These links have no visible text, image description or label, so neither visitors using a screen reader nor search engines can tell where they lead.',
            'why' => 'Descriptive link text helps people with assistive technology and gives search engines context about the destination.',
            'proposal' => 'Give each link readable text, or an alt description on the image inside it.',
            'steps' => ['In your editor, select the link and add visible text, or add an "alt" description to the image the link wraps.', 'For icon-only links (a phone icon, for example) add a label such as "Call us".'],
            'verify' => 'The next check re-reads the page; the row disappears when the link has text, alt text or a label.',
        ],
        'orphan_candidate' => [
            'category' => 'links', 'kind' => 'opportunity', 'site_level' => false, 'confidence' => 'heuristic', 'effort' => 'quick',
            'priority' => ['key' => 'medium', 'standard' => 'low', 'utility' => 'info', 'home' => 'info'],
            'title' => 'Pages in your sitemap that no checked page links to (orphan candidates)',
            'what' => 'These pages are listed in your sitemap, but none of the pages we checked links to them.',
            'why' => 'Visitors can only reach a page nobody links to if they know its address, and search engines get less signal that it matters. This is a candidate only: a link could exist on a page we did not check.',
            'proposal' => 'Link to the page from a relevant page or menu, or remove it from the sitemap if it is no longer needed.',
            'steps' => ['Decide where visitors would expect to find this page and add a link from there (a menu, a service page, a footer).', 'If the page is obsolete, remove it from the sitemap and consider redirecting it.'],
            'verify' => 'The next check records the links between pages; the row disappears when a checked page links to it.',
        ],
        'deep_page' => [
            'category' => 'links', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'moderate',
            'priority' => ['key' => 'medium', 'standard' => 'low', 'utility' => 'info', 'home' => 'info'],
            'title' => 'Pages many clicks away from the home page',
            'what' => 'These pages can only be reached by following many links from the home page (the number of clicks is shown).',
            'why' => 'Pages that are far from the home page are harder for visitors to find. This is an advisory, not an error.',
            'proposal' => 'Link to important pages from the menu, the home page or a closer page.',
            'steps' => ['Add a link to the page from the main menu, the home page or a page closer to the home page.'],
            'verify' => 'The next check measures the number of clicks from the home page again.',
        ],

        // ------------------------------------------------------------ sitemap
        'sitemap_missing' => [
            'category' => 'crawl', 'kind' => 'improvement', 'site_level' => true, 'confidence' => 'heuristic', 'effort' => 'moderate',
            'priority' => ['*' => 'low'],
            'title' => 'No sitemap found',
            'what' => 'We could not find a sitemap at the addresses your robots.txt names or at the usual locations (sitemap.xml, sitemap_index.xml, wp-sitemap.xml).',
            'why' => 'A sitemap helps search engines discover all your pages. Small sites are usually found through links anyway, so this is an improvement rather than a fault. A sitemap at an unusual address would not be seen by this check.',
            'proposal' => 'Create an XML sitemap and name it in robots.txt.',
            'steps' => ['Most website platforms and SEO plugins generate a sitemap automatically: switch the sitemap feature on.', 'Add the line "Sitemap: https://yourdomain.com/sitemap.xml" to robots.txt.'],
            'verify' => 'The next check looks for the sitemap again.',
        ],
        'sitemap_invalid' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => true, 'confidence' => 'confirmed', 'effort' => 'moderate',
            'priority' => ['*' => 'medium'],
            'title' => 'The sitemap could not be read',
            'what' => 'A sitemap file was found but is not valid sitemap XML (or could not be loaded).',
            'why' => 'Search engines cannot use a sitemap they cannot parse, so it does not help them find your pages.',
            'proposal' => 'Re-create the sitemap and check that it opens as XML.',
            'steps' => ['Open the sitemap address in a browser; you should see a list of URLs or other sitemaps.', 'If you see an error or a web page, re-create the sitemap in your SEO plugin or ask your developer.'],
            'verify' => 'The next check parses the sitemap again.',
        ],
        'sitemap_url_error' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'critical', 'key' => 'high', 'standard' => 'medium', 'utility' => 'low'],
            'title' => 'Sitemap lists pages that return an error',
            'what' => 'These addresses are listed in your sitemap, but they answered with an error status.',
            'why' => 'A sitemap should list only pages that work. Error pages in it waste search engines\' visits and signal a neglected site.',
            'proposal' => 'Remove the addresses from the sitemap, or restore the pages.',
            'steps' => ['If the pages were deleted on purpose, rebuild the sitemap so they drop out (or remove them in your SEO plugin).', 'If the pages should exist, restore them.'],
            'verify' => 'The next check reads the sitemap and requests its pages again.',
        ],
        'sitemap_url_redirects' => [
            'category' => 'crawl', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'Sitemap lists addresses that redirect',
            'what' => 'These addresses are in your sitemap but redirect to a different address.',
            'why' => 'A sitemap should list the final address of each page.',
            'proposal' => 'List the final address in the sitemap instead.',
            'steps' => ['Re-create the sitemap so it lists the final addresses, or update the entries in your SEO plugin.'],
            'verify' => 'The next check reads the sitemap and requests its addresses again.',
        ],
        'sitemap_url_noindex' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'high', 'key' => 'high', 'standard' => 'medium', 'utility' => 'low'],
            'title' => 'Sitemap lists pages marked noindex',
            'what' => 'These pages are listed in your sitemap but also tell search engines not to list them.',
            'why' => 'The two instructions contradict each other. Either the page should be found (remove the noindex) or it should not be in the sitemap.',
            'proposal' => 'Decide per page: remove the noindex, or remove the page from the sitemap.',
            'steps' => ['If the page should be found, remove its noindex setting in the page\'s SEO settings.', 'If it should stay hidden, exclude it from the sitemap in your SEO plugin.'],
            'verify' => 'The next check re-reads the page and the sitemap.',
        ],
        'sitemap_url_blocked_by_robots' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'high', 'key' => 'high', 'standard' => 'medium', 'utility' => 'low'],
            'title' => 'Sitemap lists pages that robots.txt blocks',
            'what' => 'These pages are in your sitemap, but your robots.txt asks crawlers not to fetch them.',
            'why' => 'The sitemap invites search engines to visit pages that robots.txt tells them to avoid.',
            'proposal' => 'Make robots.txt and the sitemap agree: allow the pages, or take them out of the sitemap.',
            'steps' => ['If the pages should be found, remove the matching Disallow rule from robots.txt.', 'If they should stay private, exclude them from the sitemap.'],
            'verify' => 'The next check reads robots.txt and the sitemap again.',
        ],

        // ------------------------------------------------------------ metadata
        'title_missing' => [
            'category' => 'metadata', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'high', 'key' => 'high', 'standard' => 'medium', 'utility' => 'low'],
            'title' => 'Pages with no page title',
            'what' => 'These successfully loaded pages have no (or an empty) title tag.',
            'why' => 'The title is the clickable headline in search results and the browser tab. Without one, search engines make up a headline from the page, which may not say what you want.',
            'proposal' => 'Write a short, specific title for each page that says what the page offers.',
            'steps' => [self::EDITOR, 'Find the page\'s search settings (often called "SEO", "Page title" or "Meta title") and add a clear title of roughly 30 to 60 characters. Put what the page is about first and your business name last.', 'Save and publish.'],
            'verify' => 'The next check reads each page\'s title tag; the row disappears when it is non-empty.',
        ],
        'title_multiple' => [
            'category' => 'metadata', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'moderate',
            'priority' => ['*' => 'low'],
            'title' => 'Pages with more than one title tag',
            'what' => 'These pages contain more than one title tag in their markup.',
            'why' => 'Search engines pick one of them, which may not be the one you intended. This is usually a theme plus a plugin both outputting a title.',
            'proposal' => 'Keep one title tag per page.',
            'steps' => ['Disable the title output of either your theme or your SEO plugin, so only one title tag remains.'],
            'verify' => 'The next check counts title tags on each page.',
        ],
        'title_too_long' => [
            'category' => 'metadata', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'heuristic', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'Page titles that may be cut off in search results',
            'what' => 'These titles are longer than roughly 60 characters.',
            'why' => 'Search engines show a limited width of the title (the exact limit depends on the letters used), so long titles are often shortened with "...". This is guidance, not a ranking rule.',
            'proposal' => 'Shorten the title and put the most important words first.',
            'steps' => [self::EDITOR, 'Edit the page title in its search settings so the key words come first, aiming for around 60 characters.'],
            'verify' => 'The next check measures the title again.',
        ],
        'title_too_short' => [
            'category' => 'metadata', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'heuristic', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'Very short page titles',
            'what' => 'These titles are only a few characters long (for example just "Home").',
            'why' => 'A very short title wastes the chance to say what the page offers and who you are.',
            'proposal' => 'Expand the title to describe the page and your business.',
            'steps' => [self::EDITOR, 'Edit the page title so it says what the page offers, for example "Wedding photography in Austin | Studio Name".'],
            'verify' => 'The next check measures the title again.',
        ],
        'title_duplicate' => [
            'category' => 'metadata', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'medium', 'key' => 'medium', 'standard' => 'low', 'utility' => 'info'],
            'title' => 'The same title used on several pages',
            'what' => 'Each group below is a set of pages that share exactly the same title.',
            'why' => 'Search engines and visitors cannot tell the pages apart in results. Distinct titles describe each page more accurately.',
            'proposal' => 'Give every page in the group its own title that says how it differs.',
            'steps' => [self::EDITOR, 'Edit the title in each page\'s search settings so it names what is unique about that page (the service, the place, the product).'],
            'verify' => 'The next check compares titles across the audited pages again.',
        ],
        'description_missing' => [
            'category' => 'metadata', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'medium', 'key' => 'medium', 'standard' => 'low', 'utility' => 'info'],
            'title' => 'Pages with no meta description',
            'what' => 'These successfully fetched pages did not declare a non-empty meta description.',
            'why' => 'The description can be shown under the title in search results, so it is your chance to invite the click. Google may choose a different snippet anyway.',
            'proposal' => 'Add a unique, relevant description to each page in the website\'s CMS.',
            'steps' => [self::EDITOR, 'Find the page\'s search settings and write a description of one or two sentences (roughly 70 to 155 characters) saying what visitors will find and why to choose you.', 'Save and publish.'],
            'verify' => 'The next check reads each page\'s meta description; the row disappears when it is non-empty.',
        ],
        'description_too_short' => [
            'category' => 'metadata', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'heuristic', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'Very short meta descriptions',
            'what' => 'These descriptions are shorter than roughly 70 characters.',
            'why' => 'A fuller description gives searchers more reason to click. This is guidance, not a rule.',
            'proposal' => 'Expand the description to one or two clear sentences.',
            'steps' => [self::EDITOR, 'Rewrite the description in the page\'s search settings so it is about 70 to 155 characters.'],
            'verify' => 'The next check measures the description again.',
        ],
        'description_too_long' => [
            'category' => 'metadata', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'heuristic', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'Meta descriptions that may be cut off',
            'what' => 'These descriptions are longer than roughly 160 characters.',
            'why' => 'Search engines usually show only part of a long description. This is guidance, not a rule.',
            'proposal' => 'Tighten the description so the key message comes first.',
            'steps' => [self::EDITOR, 'Shorten the description in the page\'s search settings to about 155 characters.'],
            'verify' => 'The next check measures the description again.',
        ],
        'description_duplicate' => [
            'category' => 'metadata', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'low', 'key' => 'low', 'standard' => 'low', 'utility' => 'info'],
            'title' => 'The same meta description used on several pages',
            'what' => 'Each group below is a set of pages that share exactly the same meta description.',
            'why' => 'A description written for each page describes it more accurately in search results.',
            'proposal' => 'Write a description specific to each page.',
            'steps' => [self::EDITOR, 'Rewrite the description in each page\'s search settings so it describes that page.'],
            'verify' => 'The next check compares descriptions across the audited pages again.',
        ],
        'viewport_missing' => [
            'category' => 'metadata', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'heuristic', 'effort' => 'technical',
            'priority' => ['home' => 'medium', 'key' => 'medium', 'standard' => 'low', 'utility' => 'info'],
            'title' => 'Pages without a mobile viewport setting',
            'what' => 'These pages have no viewport meta tag. We checked only this tag; we did not test how the page looks on a phone.',
            'why' => 'Without it, phones often show a zoomed-out desktop layout that is hard to read and tap.',
            'proposal' => 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> to the page head, or switch to a responsive theme.',
            'steps' => ['Most modern themes add this automatically; if yours does not, ask your developer to add the viewport tag to the shared page header.'],
            'verify' => 'The next check looks for the viewport tag on each page.',
        ],
        'mixed_content' => [
            'category' => 'crawl', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'moderate',
            'priority' => ['home' => 'high', 'key' => 'high', 'standard' => 'medium', 'utility' => 'low'],
            'title' => 'Secure pages that load insecure (http://) files',
            'what' => 'These https:// pages reference images, scripts or stylesheets at http:// addresses.',
            'why' => 'Browsers may block insecure scripts and styles or show a "Not secure" warning, which can break the page or worry visitors.',
            'proposal' => 'Change each http:// resource address to https://.',
            'steps' => ['Find the file addresses listed above in your page or theme and change http:// to https://. Many sites fix this site-wide with a "search and replace" plugin or by re-saving the media settings.'],
            'verify' => 'The next check re-reads each page and lists any remaining http:// resources.',
        ],
        'og_missing' => [
            'category' => 'metadata', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'moderate',
            'priority' => ['home' => 'low', 'key' => 'low', 'standard' => 'info', 'utility' => 'info'],
            'title' => 'Pages with no sharing preview (Open Graph)',
            'what' => 'These pages have no Open Graph tags, which control how a link looks when it is shared.',
            'why' => 'Shared links to the page may look plain on social networks and in messages. This does not affect search results directly.',
            'proposal' => 'Turn on Open Graph output and choose a share image.',
            'steps' => ['Most SEO plugins and website platforms have a "Social sharing" setting: turn it on and select a default share image and description.'],
            'verify' => 'The next check looks for og:title and og:image on each page.',
        ],
        'canonical_missing' => [
            'category' => 'metadata', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'moderate',
            'priority' => ['*' => 'low'],
            'title' => 'Pages that do not declare a preferred address (canonical)',
            'what' => 'These pages have no canonical link.',
            'why' => 'It is optional. A canonical helps when the same page can be reached at several addresses (with and without www, with tracking parameters).',
            'proposal' => 'Turn on automatic canonical tags in your platform or SEO plugin.',
            'steps' => ['Most SEO plugins add self-referencing canonical tags with one setting; switch it on, or ask your developer.'],
            'verify' => 'The next check looks for a canonical link on each page.',
        ],

        // ------------------------------------------------------------ headings
        'h1_missing' => [
            'category' => 'headings', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'medium', 'key' => 'medium', 'standard' => 'low', 'utility' => 'info'],
            'title' => 'Pages with no main heading (H1)',
            'what' => 'These pages have no non-empty H1 heading.',
            'why' => 'A clear main heading tells visitors and search engines what the page is about. Some themes use styled text instead of a heading, so the page may look fine to people.',
            'proposal' => 'Give each page one clear main heading at the top.',
            'steps' => [self::EDITOR, 'Make the top headline of the page use the "Heading 1" (H1) style, or add a short descriptive headline.'],
            'verify' => 'The next check counts the H1 headings on each page.',
        ],
        'h1_multiple' => [
            'category' => 'headings', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'Pages with more than one main heading (H1)',
            'what' => 'Each page below contains more than one H1 heading. The count shown for a page is the number of H1 headings on that one page.',
            'why' => 'Multiple H1s are allowed by HTML and not a serious problem, but one clear main heading is easier to understand.',
            'proposal' => 'Keep one H1 per page and change the others to H2 or smaller.',
            'steps' => [self::EDITOR, 'Keep one "Heading 1" for the main headline and set the other headings to "Heading 2" or lower.'],
            'verify' => 'The next check counts the H1 headings on each page.',
        ],
        'h1_duplicate' => [
            'category' => 'headings', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'The same main heading used on several pages',
            'what' => 'Each group below is a set of pages whose H1 headings are identical.',
            'why' => 'Distinct headings make it clear how the pages differ.',
            'proposal' => 'Give each page a heading that describes what is unique about it.',
            'steps' => [self::EDITOR, 'Rewrite the main heading on each page of the group.'],
            'verify' => 'The next check compares H1 headings again.',
        ],
        'heading_empty' => [
            'category' => 'headings', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'Pages with empty headings',
            'what' => 'These pages contain heading elements with no text.',
            'why' => 'Empty headings add noise for screen readers and say nothing to search engines.',
            'proposal' => 'Fill or remove each empty heading.',
            'steps' => [self::EDITOR, 'Find blank headings (often an empty line formatted as a heading) and delete them or add text.'],
            'verify' => 'The next check counts empty headings on each page.',
        ],
        'heading_skip' => [
            'category' => 'headings', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'heuristic', 'effort' => 'quick',
            'priority' => ['*' => 'info'],
            'title' => 'Heading levels that skip a step',
            'what' => 'These pages jump over a heading level, for example from H2 straight to H4.',
            'why' => 'A tidy outline helps screen-reader users move through the page. It is a minor advisory.',
            'proposal' => 'Use heading levels in order.',
            'steps' => [self::EDITOR, 'Adjust the heading styles so levels follow each other (H2, then H3, then H4).'],
            'verify' => 'The next check reads each page\'s heading outline again.',
        ],

        // ------------------------------------------------------------ images
        'image_alt_missing' => [
            'category' => 'images', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'medium', 'key' => 'low', 'standard' => 'low', 'utility' => 'info'],
            'title' => 'Images with no alt attribute',
            'what' => 'These pages contain images with no alt attribute at all. Images with an empty alt attribute are treated as decorative and are not counted.',
            'why' => 'Alt text describes an image to people using screen readers and helps search engines understand it. Purely decorative images should be given an empty alt, which is fine.',
            'proposal' => 'Add a short description of what each meaningful image shows; mark decorative images with an empty alt.',
            'steps' => ['In your editor or media library open each image listed and fill in the "Alt text" / "Image description" field.', 'For purely decorative pictures, leave the description empty on purpose.'],
            'verify' => 'The next check counts images without an alt attribute on each page.',
        ],
        'image_broken' => [
            'category' => 'images', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'quick',
            'priority' => ['home' => 'high', 'key' => 'medium', 'standard' => 'medium', 'utility' => 'low'],
            'title' => 'Images that do not load',
            'what' => 'These images are referenced by a page, but their addresses answered with an error. Only the first images found on your own site are probed.',
            'why' => 'Visitors see a missing-image icon or an empty space.',
            'proposal' => 'Re-upload the image or correct its address.',
            'steps' => ['Open the page in your editor, find the image and re-select it from the media library (or upload it again).'],
            'verify' => 'The next check requests the same images again.',
        ],
        'image_large' => [
            'category' => 'images', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'moderate',
            'priority' => ['*' => 'low'],
            'title' => 'Very large image files',
            'what' => 'The server states these image files are larger than about 1 MB. Only images whose size the server reports are listed; the page load time itself was not measured.',
            'why' => 'Large images take longer to download, especially on mobile connections.',
            'proposal' => 'Resize and compress the image before uploading, or switch on image optimisation.',
            'steps' => ['Resize the image to the size it is displayed at and compress it (free tools such as Squoosh do this), then replace it.', 'Many platforms and plugins can compress images automatically.'],
            'verify' => 'The next check reads the file size reported by the server again.',
        ],

        // ------------------------------------------------------------ structured data
        'structured_data_invalid' => [
            'category' => 'structured', 'kind' => 'problem', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'technical',
            'priority' => ['home' => 'medium', 'key' => 'medium', 'standard' => 'low', 'utility' => 'info'],
            'title' => 'Structured data that cannot be read',
            'what' => 'These pages contain a JSON-LD structured-data block that is not valid JSON.',
            'why' => 'Search engines cannot use structured data they cannot parse.',
            'proposal' => 'Fix the JSON syntax of the block, or re-create it with your SEO plugin.',
            'steps' => ['Copy the block into a JSON validator to find the syntax error (often a missing comma or quote), or re-create it from your SEO plugin\'s settings.'],
            'verify' => 'The next check parses the block again.',
        ],
        'structured_data_incomplete' => [
            'category' => 'structured', 'kind' => 'improvement', 'site_level' => false, 'confidence' => 'confirmed', 'effort' => 'technical',
            'priority' => ['*' => 'low'],
            'title' => 'Structured data missing documented required properties',
            'what' => 'These structured-data blocks declare a type but lack a property Google documents as required for it (listed below). Valid structured data is not a guarantee of rich results in Google, and this check does not test eligibility.',
            'why' => 'Required properties are the minimum for a feature to be considered; without them the markup is not usable for it.',
            'proposal' => 'Add the missing properties to the structured data.',
            'steps' => ['In your SEO plugin\'s local-business or schema settings fill in the missing fields (for a business: name and address), or ask your developer to add the properties.'],
            'verify' => 'The next check parses the block again and compares the required properties.',
        ],
        'structured_data_none' => [
            'category' => 'structured', 'kind' => 'opportunity', 'site_level' => true, 'confidence' => 'heuristic', 'effort' => 'moderate',
            'priority' => ['*' => 'low'],
            'title' => 'No JSON-LD structured data found',
            'what' => 'None of the audited pages contain JSON-LD structured data. (Microdata and RDFa are not inspected.)',
            'why' => 'Structured data can describe your business (name, address, hours) in a form search engines read directly. It is optional and does not by itself change rankings.',
            'proposal' => 'Add LocalBusiness (or the closest matching type) structured data to the home or contact page.',
            'steps' => ['Many SEO plugins add this from a "Local business" form: fill in your name, address, phone and opening hours.'],
            'verify' => 'The next check looks for JSON-LD on the audited pages.',
        ],

        // ------------------------------------------------------------ local
        'no_contact_path' => [
            'category' => 'local', 'kind' => 'opportunity', 'site_level' => true, 'confidence' => 'heuristic', 'effort' => 'moderate',
            'priority' => ['*' => 'medium'],
            'title' => 'No clear way to contact or book, found from the home page',
            'what' => 'From the home page we found no link to a contact or booking page, no phone link (tel:) and no email link (mailto:). Only the pages we checked were considered.',
            'why' => 'If visitors cannot quickly find how to reach or book you, interest can be lost. This is the one conversion path most local-business websites depend on.',
            'proposal' => 'Add a visible contact or booking link (and, if you take calls, a tappable phone number) to the home page and menu.',
            'steps' => ['Add "Contact" or "Book now" to the main menu and a button on the home page.', 'Make your phone number a tappable link (tel:) so phones can call it in one tap.'],
            'verify' => 'The next check looks at the links and phone links on the home page.',
        ],
        'nap_phone_mismatch' => [
            'category' => 'local', 'kind' => 'improvement', 'site_level' => true, 'confidence' => 'heuristic', 'effort' => 'quick',
            'priority' => ['*' => 'medium'],
            'title' => 'The phone number on your site differs from your business profile',
            'what' => 'Phone numbers were found on your pages, but none matches the phone number saved in your business profile. Numbers shown as images are not read.',
            'why' => 'Consistent name, address and phone details across your site and directory listings help customers and local search systems trust them. A second number (for example a tracking number) can be intentional.',
            'proposal' => 'Make the number consistent, or update the business profile if the site is right.',
            'steps' => ['Decide which number is correct. Update the site or the business profile so they match, and use the same number in your online listings.'],
            'verify' => 'The next check compares the phone numbers found on the audited pages with the profile.',
        ],
        'nap_phone_not_found' => [
            'category' => 'local', 'kind' => 'opportunity', 'site_level' => true, 'confidence' => 'heuristic', 'effort' => 'quick',
            'priority' => ['*' => 'low'],
            'title' => 'Your business phone number was not found on the checked pages',
            'what' => 'The phone number saved in your business profile does not appear as text or a tel: link on any audited page. A number inside an image would not be detected.',
            'why' => 'Visitors who want to call you need to see the number, and a consistent, crawlable number supports local listings.',
            'proposal' => 'Show your phone number as text (ideally a tappable tel: link) on the home and contact pages.',
            'steps' => ['Add your number as text in the header, footer or contact page, and make it a tel: link.'],
            'verify' => 'The next check looks for the number on the audited pages.',
        ],
        'local_schema_missing' => [
            'category' => 'local', 'kind' => 'opportunity', 'site_level' => true, 'confidence' => 'heuristic', 'effort' => 'moderate',
            'priority' => ['*' => 'low'],
            'title' => 'No LocalBusiness structured data found',
            'what' => 'You have a business location on file, but none of the audited pages declares LocalBusiness (or a matching sub-type) structured data.',
            'why' => 'It states your name, address and details in a form search engines read directly. It is optional and makes no ranking promise.',
            'proposal' => 'Add LocalBusiness structured data to the home or contact page.',
            'steps' => ['Use your SEO plugin\'s "Local business" settings (name, address, phone, hours), or ask your developer to add the JSON-LD.'],
            'verify' => 'The next check looks for LocalBusiness structured data on the audited pages.',
        ],
        'service_area_not_visible' => [
            'category' => 'local', 'kind' => 'opportunity', 'site_level' => true, 'confidence' => 'heuristic', 'effort' => 'moderate',
            'priority' => ['*' => 'low'],
            'title' => 'Your location is not mentioned on the checked pages',
            'what' => 'None of the audited pages mention any of the cities saved on your business locations, in their title, main heading or text.',
            'why' => 'Customers (and search engines) look for where you work. Saying plainly where you operate is useful on its own; this is not a call to repeat a city name everywhere.',
            'proposal' => 'State where you work once, naturally, on the home and contact pages (for example an address or "serving X and nearby").',
            'steps' => ['Add your address or a short "Areas we serve" line to the contact page and the footer. Write it for people, not for keyword counts.'],
            'verify' => 'The next check looks for your saved cities on the audited pages.',
        ],
        'location_page_thin' => [
            'category' => 'local', 'kind' => 'opportunity', 'site_level' => false, 'confidence' => 'heuristic', 'effort' => 'moderate',
            'priority' => ['*' => 'low'],
            'title' => 'Location or service-area pages with very little content (candidates)',
            'what' => 'These look like location or service-area pages from their address, but contain very little text (under about 150 words).',
            'why' => 'Pages with almost no content give visitors little reason to stay. This is a candidate list based on the page address, not proof the page is wrong.',
            'proposal' => 'Add useful, specific information (services offered there, address, hours, photos) or merge the page into a fuller one.',
            'steps' => ['Add what is genuinely specific to that place: address, opening hours, services, directions, local photos. Avoid copying the same text across location pages.'],
            'verify' => 'The next check counts the words on each such page again.',
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::RULES);
    }

    public static function has(string $key): bool
    {
        return isset(self::RULES[$key]);
    }

    /** @return array<string, mixed> */
    public static function rule(string $key): array
    {
        return self::RULES[$key] ?? throw new \InvalidArgumentException("Unknown external audit rule [{$key}].");
    }

    public static function priorityFor(string $key, string $importance): string
    {
        $map = self::rule($key)['priority'];

        return $map[$importance] ?? $map['*'] ?? 'low';
    }

    public static function priorityWeight(string $priority): int
    {
        return match ($priority) {
            'critical' => 5,
            'high' => 4,
            'medium' => 3,
            'low' => 2,
            default => 1,
        };
    }

    public static function category(string $key): string
    {
        return (string) self::rule($key)['category'];
    }

    public static function kind(string $key): string
    {
        return (string) self::rule($key)['kind'];
    }

    /**
     * Lets a rule that existed in version 1 (stored under the shared SEO registry's key) still be recognised when an
     * earlier-format crawl is read. Returns null for an unknown key.
     */
    public static function maybe(string $key): ?array
    {
        return self::RULES[$key] ?? null;
    }
}
