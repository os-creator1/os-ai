<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Website\Domains\WebsiteDomainService;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Website Generation + Hosting Slice B (custom domains). Runs the exact
 * same tenancy/entitlement chain Business\WebsiteController does
 * (PlatformFeature::WebsiteGeneration) — custom domains are a Website
 * capability, not a separately entitled product. Every mutation is
 * delegated to WebsiteDomainService, the sole writer of `website_domains`.
 */
class WebsiteDomainController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WebsiteDomainService $domains,
    ) {}

    public function home(string $workspaceUid, string $businessUid): View
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        $domains = $website->domains()->orderByDesc('is_primary')->orderBy('id')->get();

        return view('customer.business.website.domains', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'domains' => $domains,
            'instructionsByDomainUid' => $domains->mapWithKeys(
                fn (WebsiteDomain $domain) => [$domain->uid => $this->domains->dnsInstructions($domain)]
            ),
        ]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        $validated = $request->validate(['domain' => 'required|string|max:255']);

        $domain = $this->domains->attach($website, $validated['domain']);

        return redirect()->route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => "Domain added. Add the TXT record shown for {$domain->domain} to prove you own it, then check verification.",
        ]);
    }

    public function verify(string $workspaceUid, string $businessUid, string $domainUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $domain = $this->resolveDomain($website, $domainUid);

        $domain = $this->domains->checkVerification($domain);

        $message = $domain->status->value === 'verified'
            ? 'DNS ownership verified. You can now request a certificate.'
            : ($domain->failure_reason ?? 'Verification is not complete yet.');

        return redirect()->route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid])->with([
            'status' => $domain->status->value === 'verified' ? 'success' : 'error',
            'message' => $message,
        ]);
    }

    public function provision(string $workspaceUid, string $businessUid, string $domainUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $domain = $this->resolveDomain($website, $domainUid);

        $domain = $this->domains->provisionCertificate($domain);

        return redirect()->route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid])->with([
            'status' => $domain->status->value === 'failed' ? 'error' : 'success',
            'message' => $domain->status->value === 'failed'
                ? ($domain->failure_reason ?? 'Certificate request failed.')
                : 'Certificate requested. This can take a few minutes — check status below.',
        ]);
    }

    public function checkCertificate(string $workspaceUid, string $businessUid, string $domainUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $domain = $this->resolveDomain($website, $domainUid);

        $domain = $this->domains->checkCertificate($domain);

        return redirect()->route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid])->with([
            'status' => $domain->status->value === 'failed' ? 'error' : 'success',
            'message' => match ($domain->status->value) {
                'active' => "{$domain->domain} is live.",
                'failed' => $domain->failure_reason ?? 'Certificate provisioning failed.',
                default => 'Still provisioning — check back shortly.',
            },
        ]);
    }

    public function makePrimary(string $workspaceUid, string $businessUid, string $domainUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $domain = $this->resolveDomain($website, $domainUid);

        $this->domains->makePrimary($website, $domain);

        return redirect()->route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => "{$domain->domain} is now this website's primary address.",
        ]);
    }

    public function destroy(string $workspaceUid, string $businessUid, string $domainUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $domain = $this->resolveDomain($website, $domainUid);

        $removedDomain = $domain->domain;
        $this->domains->remove($website, $domain);

        // remove() only ever deletes the row once Forge itself confirms
        // the domain resource is gone — $domain->exists is still false
        // right here when it did, and still true (status Removing) when
        // Forge could not be reached or refused the delete.
        return redirect()->route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid])->with(
            $domain->exists
                ? ['status' => 'error', 'message' => "{$removedDomain} is no longer served, but we couldn't confirm removal with our hosting provider yet. It will need to be retried."]
                : ['status' => 'success', 'message' => "{$removedDomain} removed."]
        );
    }

    /**
     * @return array{0: Workspace, 1: Business}
     */
    private function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
    }

    private function resolveWebsite(Business $business): Website
    {
        $website = Website::where('business_id', $business->id)->first();

        abort_unless($website !== null, 404);

        return $website;
    }

    private function resolveDomain(Website $website, string $domainUid): WebsiteDomain
    {
        $domain = $website->domains()->where('uid', $domainUid)->first();

        abort_unless($domain !== null, 404);

        return $domain;
    }
}
