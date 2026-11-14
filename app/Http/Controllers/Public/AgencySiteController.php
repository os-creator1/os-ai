<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Library\AgencySite\SiteRenderer;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencySite;
use App\Models\Workspace;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Cache;

/**
 * White Label — an Agency's PUBLISHED marketing site on the platform host (/w/{publicId}).
 *
 * Reads ONLY the immutable published revision, never the draft. The site is served only while it is published
 * AND its Agency is still a usable Agency entitled to white label, so a suspended or downgraded Agency does not
 * keep a live storefront. Every miss — unknown id, unpublished, ineligible — is the same plain 404, so the
 * route discloses nothing about which Agencies exist.
 */
class AgencySiteController extends Controller
{
    public function __construct(
        private readonly SiteRenderer $renderer,
        private readonly AgencyClientRelationshipManager $relationships,
        private readonly AgencyWhiteLabelManager $whiteLabel,
    ) {
    }

    public function home(string $publicId): Response
    {
        return $this->page($publicId, 'home');
    }

    public function page(string $publicId, string $page): Response
    {
        [$site, $values] = $this->live($publicId);

        // Call-only: the old Pricing URL never shows prices; it goes to the booking page instead.
        if (($to = $this->renderer->redirectFor($site, $values, $page)) !== null) {
            return redirect()->away($to, 302)->header('Cache-Control', 'no-store');
        }

        $html = $this->renderer->page($site, $values, $page);
        abort_if($html === null, 404);

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * A published Agency site served as a "Book a call" landing page under the platform's own URL (e.g. /dogs).
     *
     * The Agency is chosen by the platform's configuration, never by the request. Only the published revision is read
     * (same eligibility checks as the hosted site), and the call-only presentation is applied to a COPY of the values:
     * the Agency's saved selling mode is not changed. A landing page whose booking calendar does not work is a 404,
     * never a page with dead buttons.
     */
    public function landing(string $publicId, string $page, string $base): Response
    {
        [$site, $values] = $this->live($publicId);
        $values['x.goal'] = 'book_call';
        unset($values['x.pricing_mode']);

        abort_if($this->renderer->site($site, $values, null, $base)->bookDemoUrl() === null, 404);

        if (($to = $this->renderer->redirectFor($site, $values, $page, null, $base)) !== null) {
            return redirect()->away($to, 302)->header('Cache-Control', 'no-store');
        }

        $html = $this->renderer->page($site, $values, $page, null, $base);
        abort_if($html === null, 404);

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /** The marketing stylesheet in the Agency's colours. */
    public function css(string $publicId): Response
    {
        [$site, $values] = $this->live($publicId);
        $context = $this->renderer->site($site, $values)->context();

        $css = Cache::remember('agency-site-css:'.$site->public_id.':'.md5($context->primaryColor.$context->secondaryColor), 300, fn () => $this->renderer->css($context));

        return response($css)->header('Content-Type', 'text/css; charset=UTF-8')->header('Cache-Control', 'public, max-age=300');
    }

    /** @return array{0: AgencySite, 1: array<string, mixed>} */
    private function live(string $publicId): array
    {
        $site = AgencySite::query()->where('public_id', $publicId)->first();
        abort_if($site === null || ! $site->isLive(), 404);

        $agency = Workspace::query()->find($site->agency_workspace_id);
        abort_if($agency === null || ! $agency->is_active
            || ! $this->relationships->agencyWorkspaceHasManagementEligibility($agency)
            || ! $this->whiteLabel->isEntitled($agency), 404);

        $revision = $site->publishedRevision;
        abort_if($revision === null, 404);

        return [$site, (array) ($revision->config['values'] ?? [])];
    }
}
