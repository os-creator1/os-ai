<?php

namespace App\Library\ExternalSite;

use App\Library\ExternalSite\Audit\ExternalAuditCatalog;
use App\Library\ExternalSite\Audit\ExternalPageImportance;
use App\Models\ExternalSiteCrawl;

/**
 * Every owner-facing sentence about the STATE of an audit (as opposed to a finding,
 * whose words live in ExternalAuditCatalog): what a status means, why a crawl
 * stopped, what a connection result says, how to read the coverage numbers and what
 * could not be measured. Pure functions of stored values, so the views stay free of
 * wording and the wording stays testable.
 *
 * The wording rules, each pinned by a test:
 *   - an observation is stated as fact only when it was observed; interpretations
 *     say "may", "usually", "candidate";
 *   - a crawler being turned away is never described as the website being down;
 *   - "Indexing permitted" / "No obvious indexing block" is NEVER written as "indexed"
 *     or "Google can find your site": Google's index is not consulted;
 *   - what was not measured is shown as "Not measured" / "Not verified", never as a
 *     number.
 */
final class ExternalAuditPresenter
{
    public static function statusLabel(ExternalSiteCrawl $crawl): string
    {
        return match ($crawl->status) {
            ExternalSiteCrawl::QUEUED => 'Queued',
            ExternalSiteCrawl::RUNNING => 'Running',
            ExternalSiteCrawl::COMPLETED => 'Completed',
            ExternalSiteCrawl::PARTIAL => 'Partially completed',
            ExternalSiteCrawl::FAILED => 'Failed',
            ExternalSiteCrawl::CANCELLED => 'Cancelled',
            default => ucfirst((string) $crawl->status),
        };
    }

    /** Badge variant for the status. */
    public static function statusVariant(ExternalSiteCrawl $crawl): string
    {
        return match ($crawl->status) {
            ExternalSiteCrawl::COMPLETED => 'success',
            ExternalSiteCrawl::PARTIAL => 'warning',
            ExternalSiteCrawl::FAILED => 'danger',
            default => 'neutral',
        };
    }

    public static function stageLabel(?string $stage): string
    {
        return match ($stage) {
            'queued' => 'Waiting to start',
            'connecting' => 'Connecting to your website',
            'discovering' => 'Reading your home page and sitemap',
            'crawling' => 'Checking pages',
            'analyzing' => 'Analysing what was found',
            'done' => 'Finished',
            default => 'Working',
        };
    }

    public static function priorityVariant(string $priority): string
    {
        return match ($priority) {
            'critical' => 'danger',
            'high' => 'danger',
            'medium' => 'warning',
            default => 'neutral',
        };
    }

    public static function priorityLabel(string $priority): string
    {
        return ExternalAuditCatalog::PRIORITY_LABELS[$priority] ?? ucfirst($priority);
    }

    /** What a finished-but-incomplete crawl stopped on. */
    public static function finishReason(?string $reason, ?array $coverage = null): string
    {
        $coverage = (array) $coverage;

        return match ($reason) {
            'page_limit' => 'The check stopped at its limit of '.($coverage['audited'] ?? 'N').' audited pages and left '.($coverage['skipped'] ?? 'some').' eligible page(s) unchecked, so these results cover part of your site.',
            'time_limit' => 'The check ran out of its time budget before every page was read, so these results cover part of your site.',
            'some_pages_blocked' => 'A firewall or rate limit turned our crawler away from '.($coverage['blocked'] ?? 'some').' page(s). They were not audited; this is not a statement about whether they work for visitors.',
            'some_pages_unreachable' => ($coverage['unreachable'] ?? 'Some').' page(s) did not answer (timeouts or connection problems), so they were not audited.',
            'cancelled' => 'The check was cancelled.',
            default => '',
        };
    }

    /**
     * The headline of a FAILED crawl: whether the website or the crawler's access is the cause, and the next step.
     *
     * @return array{title: string, body: string, fault: string, rule: ?string}
     */
    public static function failure(ExternalSiteCrawl $crawl): array
    {
        $status = $crawl->connection_http_status;
        $final = (string) $crawl->final_url;

        return match ((string) $crawl->failure_code) {
            'crawler_blocked' => ['title' => 'Our crawler was turned away', 'fault' => 'crawler', 'rule' => 'crawler_blocked', 'body' => 'Your website answered our crawler with '.($status ? 'HTTP '.$status : 'a block').' (a firewall, bot protection or rate limit). That says nothing about whether your website works for visitors, so no audit could be done. Allow the "MotionGroveSiteAudit" crawler in your firewall or CDN and check again.'],
            'blocked_by_robots' => ['title' => 'Your robots.txt asks crawlers to stay out', 'fault' => 'site', 'rule' => null, 'body' => 'Your robots.txt file asks crawlers such as ours not to fetch your home page, so we did not. If you want your site audited (and found in search), remove or narrow the Disallow rule that covers it, then check again.'],
            'http_error' => ['title' => 'Your website returned an error', 'fault' => 'site', 'rule' => 'home_page_error', 'body' => 'Your home page answered with HTTP '.($status ?: 'an error status').' even after a retry. If this is also what visitors see, it needs fixing first.'],
            'redirects_elsewhere' => ['title' => 'Your address redirects to a different website', 'fault' => 'address', 'rule' => null, 'body' => 'The address you saved sends visitors to '.($final !== '' ? $final : 'another website').'. MotionGrove audits one website at a time, so nothing was audited. If that is your real website, use it as your website address.'],
            'tls_failed' => ['title' => 'The secure connection could not be set up', 'fault' => 'site', 'rule' => 'home_page_unreachable', 'body' => 'The secure-connection (HTTPS certificate) handshake with your website failed. Visitors may see a browser warning. Renew or reinstall your SSL certificate with your hosting provider.'],
            'dns_failed' => ['title' => 'We could not find that website', 'fault' => 'address', 'rule' => 'home_page_unreachable', 'body' => 'The address did not resolve to a website. Check the spelling of the address, and that the domain is active and points at your hosting.'],
            'timeout', 'connect_failed', 'transport_error' => ['title' => 'We could not connect to your website', 'fault' => 'unknown', 'rule' => 'home_page_unreachable', 'body' => 'Our connection to your website failed or timed out, even after a retry. This may be temporary or specific to our servers; it does not prove visitors cannot reach you.'],
            'url_malformed', 'host_not_allowed', 'ip_literal_not_allowed', 'private_destination', 'scheme_not_allowed', 'port_not_allowed', 'credentials_in_url' => ['title' => 'That address cannot be audited', 'fault' => 'address', 'rule' => null, 'body' => 'The saved address is not a public website address we can check (it must be a normal website name such as example.com).'],
            'stale' => ['title' => 'The check did not finish', 'fault' => 'unknown', 'rule' => null, 'body' => 'The last check stopped before it finished (it was cancelled by the system after it stopped reporting progress). Nothing is wrong with your website because of this. Please check again.'],
            'job_failed', 'crawler_error', 'storage_error', 'business_missing' => ['title' => 'The check could not be completed', 'fault' => 'unknown', 'rule' => null, 'body' => 'Something went wrong on our side while checking your website. Please try again in a little while.'],
            default => ['title' => 'No page could be audited', 'fault' => 'unknown', 'rule' => null, 'body' => 'We could not read any page of your website to audit, so there are no results from this check.'],
        };
    }

    /** What the connection check found, for the Settings screen. */
    public static function connection(?string $state): array
    {
        return match ($state) {
            ConnectionResult::REACHABLE => ['label' => 'Website reachable', 'variant' => 'success', 'detail' => 'The crawler could load your website.'],
            ConnectionResult::REDIRECTS_ELSEWHERE => ['label' => 'Redirects to another website', 'variant' => 'warning', 'detail' => 'The address you saved leads to a different website.'],
            ConnectionResult::CRAWLER_BLOCKED => ['label' => 'Reachable, but our crawler was blocked', 'variant' => 'warning', 'detail' => 'A firewall or rate limit refused the crawler. This does not mean the website is down.'],
            ConnectionResult::ROBOTS_BLOCKED => ['label' => 'robots.txt asks crawlers to stay out', 'variant' => 'warning', 'detail' => 'The site\'s robots.txt disallows the crawler.'],
            ConnectionResult::HTTP_ERROR => ['label' => 'Website returned an error', 'variant' => 'danger', 'detail' => 'The website itself answered with an error status.'],
            ConnectionResult::TLS_ERROR => ['label' => 'Secure connection failed', 'variant' => 'danger', 'detail' => 'The HTTPS certificate handshake failed.'],
            ConnectionResult::NETWORK_ERROR => ['label' => 'Could not connect', 'variant' => 'warning', 'detail' => 'The name did not resolve or the connection timed out.'],
            ConnectionResult::INVALID_ADDRESS => ['label' => 'Address cannot be checked', 'variant' => 'danger', 'detail' => 'The address is not a public website address.'],
            default => ['label' => 'Not checked yet', 'variant' => 'neutral', 'detail' => 'No connection check has been made.'],
        };
    }

    /**
     * What the crawler OBSERVED about indexing on the home page. Deliberately never "indexed" and never "Google can find".
     *
     * @return array{label: string, variant: string, detail: string}
     */
    public static function indexability(?string $value): array
    {
        return match ($value) {
            CrawlOutcome::INDEXABLE => ['label' => 'No obvious indexing block detected', 'variant' => 'success', 'detail' => 'The home page has no noindex directive and robots.txt does not disallow it. Whether Google has actually indexed it is not verified.'],
            CrawlOutcome::NOINDEX => ['label' => 'Home page asks not to be indexed (noindex)', 'variant' => 'danger', 'detail' => 'The home page carries a noindex instruction.'],
            CrawlOutcome::BLOCKED_BY_ROBOTS => ['label' => 'robots.txt disallows the home page for crawlers', 'variant' => 'danger', 'detail' => 'Your robots.txt asks crawlers like ours to stay away from the home page.'],
            CrawlOutcome::UNREACHABLE => ['label' => 'Home page could not be loaded by our crawler', 'variant' => 'warning', 'detail' => 'The crawler got an error or no answer from the home page.'],
            CrawlOutcome::NOT_VERIFIED => ['label' => 'Not verified', 'variant' => 'neutral', 'detail' => 'Our crawler was turned away, so nothing can be said about indexing.'],
            default => ['label' => 'Not checked yet', 'variant' => 'neutral', 'detail' => ''],
        };
    }

    /** The label for one page's assessment. */
    public static function pageIndexability(?bool $indexable, ?string $canonicalStatus, string $kind): string
    {
        if ($kind !== 'html') {
            return 'Not assessed';
        }

        return match (true) {
            $indexable === false => 'Marked noindex',
            $canonicalStatus === 'other_page' => 'Canonical points to another page',
            $canonicalStatus === 'off_site' => 'Canonical points to another website',
            $canonicalStatus === 'other_problem' => 'Canonical points to a problem page',
            default => 'No obvious indexing block',
        };
    }

    public static function canonicalLabel(?string $status): string
    {
        return match ($status) {
            'self' => 'Points to itself',
            'missing' => 'Not declared',
            'conflict' => 'Conflicting canonicals',
            'other_page' => 'Points to another page',
            'other_problem' => 'Points to a broken or hidden page',
            'off_site' => 'Points to another website',
            'unknown_target' => 'Points to a page that was not checked',
            default => '—',
        };
    }

    public static function importanceLabel(string $importance): string
    {
        return match ($importance) {
            ExternalPageImportance::HOME => 'Home page',
            ExternalPageImportance::KEY => 'Key page (contact / booking / services)',
            ExternalPageImportance::UTILITY => 'Housekeeping page',
            default => 'Content page',
        };
    }

    /** Owner-facing name of a URL record kind. */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            CrawledPage::HTML => 'Audited page',
            CrawledPage::REDIRECT => 'Redirect',
            CrawledPage::NON_HTML => 'Not a web page',
            CrawledPage::ERROR => 'Error response',
            CrawledPage::BLOCKED => 'Crawler blocked',
            CrawledPage::UNREACHABLE => 'No answer',
            CrawledPage::ROBOTS_DISALLOWED => 'Disallowed by robots.txt',
            CrawledPage::EXCLUDED => 'Excluded',
            CrawledPage::SKIPPED => 'Not checked (limit reached)',
            default => ucfirst($kind),
        };
    }

    /** Why a non-audited URL was not audited. */
    public static function reasonLabel(string $kind, ?string $reason, ?int $status = null): string
    {
        return match (true) {
            $kind === CrawledPage::EXCLUDED => UrlClassifier::describe((string) $reason),
            $kind === CrawledPage::NON_HTML => 'It answered with something other than a web page (for example a file).',
            $kind === CrawledPage::ROBOTS_DISALLOWED => 'Your robots.txt asks crawlers like ours not to fetch it, so it was not fetched.',
            $kind === CrawledPage::BLOCKED => 'A firewall or rate limit refused our crawler (HTTP '.($status ?: '—').'). This says nothing about whether the page works for visitors.',
            $kind === CrawledPage::ERROR && in_array($reason, ['redirect_loop', 'too_many_redirects'], true) => 'It redirects in a loop or through too many redirects.',
            $kind === CrawledPage::ERROR => 'Your website answered with HTTP '.($status ?: 'an error').'.',
            $kind === CrawledPage::REDIRECT && $reason === 'redirects_off_site' => 'It redirects to a different website, which is not audited.',
            $kind === CrawledPage::REDIRECT => 'It redirects to another address; the page it leads to is audited instead.',
            $kind === CrawledPage::UNREACHABLE => 'No answer: '.str_replace('_', ' ', (string) $reason).'.',
            $kind === CrawledPage::SKIPPED && $reason === 'time_limit' => 'The check ran out of time before reaching it.',
            $kind === CrawledPage::SKIPPED && $reason === 'cancelled' => 'The check was cancelled.',
            $kind === CrawledPage::SKIPPED => 'The check\'s page limit was reached before it got to this page.',
            default => '',
        };
    }

    /**
     * The sentence that explains the coverage numbers, so "35 found / 19 checked" is never left unexplained.
     * Each URL is in exactly one bucket, so the parts add up to the total.
     *
     * @param  array<string, int>  $c  ExternalSiteCrawl::coverageCounts()
     */
    public static function coverageSentence(array $c): string
    {
        $parts = [];
        $map = [
            'redirected' => ['address that redirects to another page', 'addresses that redirect to another page'],
            'errors' => ['page that answered with an error', 'pages that answered with an error'],
            'blocked' => ['page our crawler was blocked from (not audited)', 'pages our crawler was blocked from (not audited)'],
            'unreachable' => ['page that did not answer', 'pages that did not answer'],
            'robots_disallowed' => ['page robots.txt asks crawlers to skip', 'pages robots.txt asks crawlers to skip'],
            'excluded' => ['file or technical address that is not a web page', 'files or technical addresses that are not web pages'],
            'skipped' => ['page not checked because the page limit was reached', 'pages not checked because the page limit was reached'],
        ];

        foreach ($map as $key => [$one, $many]) {
            $n = (int) ($c[$key] ?? 0);

            if ($n > 0) {
                $parts[] = $n.' '.($n === 1 ? $one : $many);
            }
        }

        $discovered = (int) ($c['discovered'] ?? 0);
        $audited = (int) ($c['audited'] ?? 0);

        $sentence = 'We found '.$discovered.' '.($discovered === 1 ? 'address' : 'addresses').' and audited '.$audited.' web '.($audited === 1 ? 'page' : 'pages').'.';

        if ($parts !== []) {
            $sentence .= ' The rest: '.implode(', ', $parts).'.';
        }

        if (($c['omitted'] ?? 0) > 0) {
            $sentence .= ' '.$c['omitted'].' further address(es) were seen but not recorded individually (storage limit).';
        }

        return $sentence;
    }

    /**
     * What this audit does NOT measure, shown instead of any number.
     *
     * @return list<array{label: string, status: string, detail: string}>
     */
    public static function notMeasured(): array
    {
        return [
            ['label' => 'Google indexing and search performance', 'status' => 'Not verified', 'detail' => 'Whether Google has indexed a page, and its clicks, impressions and positions, come only from Google Search Console, which is not connected. The audit checks only whether anything stops indexing (noindex, robots.txt, redirects, canonicals).'],
            ['label' => 'Page speed and Core Web Vitals (LCP, INP, CLS)', 'status' => 'Not measured', 'detail' => 'These need real measurements from a performance tool or real visitors. MotionGrove does not estimate them. Only static signals are checked: mobile viewport tag, file sizes the server reports and insecure resources.'],
            ['label' => 'Mobile usability and rendering', 'status' => 'Not measured', 'detail' => 'Pages are read as HTML; JavaScript is not run and pages are not rendered. Only the viewport tag is checked.'],
            ['label' => 'Rankings, traffic and competitors', 'status' => 'Not measured', 'detail' => 'No ranking, traffic, revenue or competitor figure appears in this audit, and none is projected.'],
            ['label' => 'Rich-result eligibility', 'status' => 'Not verified', 'detail' => 'Structured data is checked for valid JSON and documented required properties on a few types. Eligibility for rich results in Google is decided by Google.'],
        ];
    }
}
