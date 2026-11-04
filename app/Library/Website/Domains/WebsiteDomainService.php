<?php

namespace App\Library\Website\Domains;

use App\Library\Domains\DomainReservations;
use App\Library\Domains\DomainUnavailableException;
use App\Enums\Website\WebsiteDomainCertificateStatus;
use App\Enums\Website\WebsiteDomainStatus;
use App\Http\Middleware\TrustHosts;
use App\Models\Website;
use App\Models\WebsiteDomain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Custom website domains: TWO DNS records, no TXT, no manual SSL.
 *
 *   A      @    the production server IP
 *   CNAME  www  <unique-token>.<FORGE_CNAME_ZONE>   (this domain's own, unguessable target)
 *
 * The unique CNAME is both the routing destination and the ownership proof: only whoever controls the domain's DNS can
 * create it, and nobody else can know the token. A shared server IP (or a shared CNAME target) proves nothing, so a
 * domain that merely already points at our IP can never be claimed this way. Ownership is checked against the EXACT
 * expected target, again before every provisioning step. If it cannot be proven (zone/IP not configured, a subdomain
 * that cannot use the two records) the domain stays unverified; there is no weaker fallback.
 *
 * advance() is the one idempotent step function, driven by the `website-domains:process` scheduler command (and by the
 * owner's "Check again"): Pending (waiting for DNS) -> Verified (DNS proven, preparing) -> Provisioning (certificates
 * requested for BOTH apex and www, one Forge domain + one certificate each) -> Active only when both certificates are
 * active AND both hostnames really answer over HTTPS. Transient failures back off and retry a bounded number of times,
 * then land on Failed with one concise reason; retry() starts again from the DNS proof, keeping every Forge id.
 */
final class WebsiteDomainService
{
    private const MAX_DOMAINS_PER_WEBSITE = 5;

    /** Statuses the background processor owns. */
    private const IN_FLIGHT = [WebsiteDomainStatus::PendingVerification, WebsiteDomainStatus::Verified, WebsiteDomainStatus::Provisioning];

    private const MAX_PREPARE_ATTEMPTS = 6;

    private const MAX_CERTIFICATE_ATTEMPTS = 3;

    private const MAX_POLL_ATTEMPTS = 120;

    public function __construct(
        private readonly DnsVerifier $dns,
        private readonly ForgeDomainProvisioner $provisioner,
        private readonly HttpsProbe $https,
    ) {}

    /**
     * @throws ValidationException
     */
    public function attach(Website $website, string $rawDomain): WebsiteDomain
    {
        if ($website->published_revision_id === null) {
            throw ValidationException::withMessages([
                'domain' => ['Publish your website at least once before connecting a custom domain.'],
            ]);
        }

        $domain = self::normalize($rawDomain);
        self::validateFormat($domain);

        if (! WebsiteDomain::isRootDomain($domain)) {
            throw ValidationException::withMessages([
                'domain' => ['Connect your main domain (like example.com). Addresses such as shop.example.com are not supported yet.'],
            ]);
        }

        if (! $this->dnsConfigured()) {
            throw ValidationException::withMessages([
                'domain' => ["Custom domains aren't available yet. We'll let you know when you can connect one."],
            ]);
        }

        return DB::transaction(function () use ($website, $domain) {
            // Locks any row for this EXACT domain string first; the unique column and the reservations back it up.
            $existing = WebsiteDomain::where('domain', $domain)->lockForUpdate()->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'domain' => ['This domain is already connected to another website.'],
                ]);
            }

            Website::whereKey($website->id)->lockForUpdate()->first();

            if ($website->domains()->count() >= self::MAX_DOMAINS_PER_WEBSITE) {
                throw ValidationException::withMessages([
                    'domain' => ['A website may not have more than '.self::MAX_DOMAINS_PER_WEBSITE.' domains.'],
                ]);
            }

            $created = $website->domains()->create([
                'domain' => $domain,
                // The first domain a website ever connects becomes its canonical address automatically.
                'is_primary' => ! $website->domains()->exists(),
                'status' => WebsiteDomainStatus::PendingVerification,
                'verification_token' => $this->newToken(),
            ]);

            // Apex AND www are reserved together, for this one row, in this one transaction: a different customer can
            // claim neither, and a lost race rolls both back.
            $this->reserveBoth($created);

            return $created;
        });
    }

    /**
     * The two records, and nothing else. Null target/IP means the records cannot be shown (not configured).
     *
     * @return array{records: list<array{type: string, host: string, value: ?string, ttl: string}>, ready: bool}
     */
    public function dnsInstructions(WebsiteDomain $domain): array
    {
        $ip = trim((string) config('services.forge.a_record_ip')) ?: null;
        $target = $domain->cnameTarget();

        return [
            'ready' => $ip !== null && $target !== null,
            'records' => [
                ['type' => 'A', 'host' => '@', 'value' => $ip, 'ttl' => 'Automatic'],
                ['type' => 'CNAME', 'host' => 'www', 'value' => $target, 'ttl' => 'Automatic'],
            ],
        ];
    }

    /** Whether the platform side of custom domains is ready to be shown to a customer (DNS values AND Forge). */
    public function ready(): bool
    {
        return $this->dnsConfigured() && $this->provisioner->isConfigured();
    }

    private function dnsConfigured(): bool
    {
        return trim((string) config('services.forge.a_record_ip')) !== '' && trim((string) config('services.forge.cname_zone')) !== '';
    }

    /**
     * Runs the next step for one domain, at most once at a time across workers. Safe to call repeatedly.
     */
    public function advance(WebsiteDomain $domain, bool $ignoreSchedule = false): WebsiteDomain
    {
        if (! in_array($domain->status, self::IN_FLIGHT, true) || ! $this->claim($domain, $ignoreSchedule)) {
            return $domain->refresh();
        }

        try {
            $domain->refresh();

            if ($domain->status === WebsiteDomainStatus::PendingVerification) {
                $this->checkDns($domain);
            }

            if ($domain->status === WebsiteDomainStatus::Verified) {
                $this->prepare($domain);
            } elseif ($domain->status === WebsiteDomainStatus::Provisioning) {
                $this->pollCertificates($domain);
            }
        } catch (Throwable $exception) {
            Log::warning('Custom domain step failed', ['domain_uid' => $domain->uid, 'exception' => $exception::class]);
        } finally {
            $domain->processing_until = null;
            $domain->save();
        }

        return $domain;
    }

    /**
     * After the platform side is fixed (or any time the owner asks): start a Failed domain again FROM THE DNS PROOF.
     * Forge ids, certificate references and the hostname reservations are kept; nothing is created twice, and ownership
     * and routing are re-verified before anything else happens.
     */
    public function retry(WebsiteDomain $domain): WebsiteDomain
    {
        if ($domain->status !== WebsiteDomainStatus::Failed) {
            return $domain;
        }

        try {
            DB::transaction(fn () => $this->reserveWwwIfMissing($domain));
        } catch (ValidationException) {
            $domain->failure_reason = "The www version of this domain is already connected to another website.";
            $domain->save();

            return $domain;
        }

        $domain->forceFill([
            'status' => WebsiteDomainStatus::PendingVerification,
            'failure_reason' => null,
            'verified_at' => null,
            'attempts' => 0,
            'certificate_attempts' => 0,
            'next_attempt_at' => null,
            'processing_until' => null,
        ])->save();

        return $this->advance($domain, true);
    }

    private function claim(WebsiteDomain $domain, bool $ignoreSchedule): bool
    {
        return WebsiteDomain::whereKey($domain->id)
            ->whereIn('status', array_map(fn ($status) => $status->value, self::IN_FLIGHT))
            ->where(fn ($q) => $q->whereNull('processing_until')->orWhere('processing_until', '<=', now()))
            ->when(! $ignoreSchedule, fn ($q) => $q->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now())))
            ->update(['processing_until' => now()->addMinutes(5)]) === 1;
    }

    /** Step 1: both records present. The CNAME is the ownership proof; the A record is routing readiness. */
    private function checkDns(WebsiteDomain $domain): void
    {
        $domain->last_checked_at = now();

        if (! $this->dnsConfigured() || $domain->cnameTarget() === null) {
            $domain->failure_reason = null;
            $domain->next_attempt_at = now()->addMinutes(15);

            return;
        }

        $this->readDns($domain);

        if ($domain->dns_cname_ok && $domain->dns_a_ok) {
            $domain->status = WebsiteDomainStatus::Verified;
            $domain->verified_at = now();
            $domain->failure_reason = null;
            $domain->attempts = 0;
            $domain->next_attempt_at = null;

            return;
        }

        // Not there yet (or not propagated): keep waiting, checking more slowly as time goes on. Never an error.
        $domain->attempts = min(65535, $domain->attempts + 1);
        $domain->next_attempt_at = now()->addMinutes(min(1 + intdiv($domain->attempts, 5), 30));
    }

    private function readDns(WebsiteDomain $domain): void
    {
        $domain->dns_cname_ok = $this->dns->hasCnameTo($domain->wwwHost(), (string) $domain->cnameTarget());
        $domain->dns_a_ok = $this->dns->hasARecord($domain->domain, (string) config('services.forge.a_record_ip'));
    }

    /** Step 2: re-prove ownership, then create both Forge domains and request both certificates. */
    private function prepare(WebsiteDomain $domain): void
    {
        $domain->last_checked_at = now();
        $this->readDns($domain);

        if (! $domain->dns_cname_ok || ! $domain->dns_a_ok) {
            // DNS changed under us: ownership is no longer proven, so nothing is provisioned.
            $domain->status = WebsiteDomainStatus::PendingVerification;
            $domain->verified_at = null;
            $domain->next_attempt_at = now()->addMinutes(1);

            return;
        }

        if (! $this->provisioner->isConfigured()) {
            // Our side is not ready. Not the customer's fault and not a failure: wait and try again later.
            Log::warning('Custom-domain provisioning is not configured; domain is waiting', ['domain_uid' => $domain->uid]);
            $domain->failure_reason = "We're finishing setting up secure connections. This will continue automatically.";
            $domain->next_attempt_at = now()->addMinutes(15);

            return;
        }

        try {
            // Each id is persisted the instant Forge returns it, so a retry reuses it and removal can always clean up.
            if ($domain->forge_domain_id === null) {
                $domain->forge_domain_id = $this->provisioner->attachDomain($domain->domain);
                $domain->save();
            }

            if ($domain->www_forge_domain_id === null) {
                $domain->www_forge_domain_id = $this->provisioner->attachDomain($domain->wwwHost());
                $domain->save();
            }

            if ($domain->certificate_reference === null) {
                $domain->certificate_reference = $this->provisioner->requestCertificate($domain->forge_domain_id);
                $domain->save();
            }

            if ($domain->www_certificate_reference === null) {
                $domain->www_certificate_reference = $this->provisioner->requestCertificate($domain->www_forge_domain_id);
            }

            $domain->status = WebsiteDomainStatus::Provisioning;
            $domain->failure_reason = null;
            $domain->attempts = 0;
            $domain->next_attempt_at = now()->addMinute();
        } catch (Throwable $exception) {
            Log::warning('Custom-domain preparation failed', ['domain_uid' => $domain->uid, 'error' => mb_substr($exception->getMessage(), 0, 200)]);
            $domain->attempts++;

            if ($domain->attempts >= self::MAX_PREPARE_ATTEMPTS) {
                $this->fail($domain, "We couldn't finish setting up this domain. Please try again.");
            } else {
                $domain->failure_reason = null;
                $domain->next_attempt_at = now()->addMinutes(2 ** $domain->attempts);
            }
        }
    }

    /** Step 3: wait for both certificates, retry a failed one a bounded number of times, go live only when both work. */
    private function pollCertificates(WebsiteDomain $domain): void
    {
        $domain->last_checked_at = now();

        $apex = $this->provisioner->certificateStatus((string) $domain->forge_domain_id, (string) $domain->certificate_reference);
        $www = $this->provisioner->certificateStatus((string) $domain->www_forge_domain_id, (string) $domain->www_certificate_reference);

        if ($apex === WebsiteDomainCertificateStatus::Failed || $www === WebsiteDomainCertificateStatus::Failed) {
            $domain->certificate_attempts++;

            if ($domain->certificate_attempts > self::MAX_CERTIFICATE_ATTEMPTS) {
                $this->fail($domain, "We couldn't secure this domain. Check that both records are correct, then try again.");

                return;
            }

            try {
                if ($apex === WebsiteDomainCertificateStatus::Failed) {
                    $domain->certificate_reference = $this->provisioner->requestCertificate((string) $domain->forge_domain_id);
                }

                if ($www === WebsiteDomainCertificateStatus::Failed) {
                    $domain->www_certificate_reference = $this->provisioner->requestCertificate((string) $domain->www_forge_domain_id);
                }
            } catch (Throwable) {
                // Next poll retries within the same bound.
            }

            $domain->next_attempt_at = now()->addMinutes(5 * $domain->certificate_attempts);

            return;
        }

        $domain->attempts++;

        if ($apex === WebsiteDomainCertificateStatus::Active
            && $www === WebsiteDomainCertificateStatus::Active
            && $this->https->isServing($domain->domain)
            && $this->https->isServing($domain->wwwHost())) {
            $this->activate($domain);

            return;
        }

        if ($domain->attempts >= self::MAX_POLL_ATTEMPTS) {
            $this->fail($domain, "Securing this domain is taking longer than expected. Please try again.");

            return;
        }

        $domain->next_attempt_at = now()->addMinutes(min(1 + intdiv($domain->attempts, 3), 10));
    }

    private function activate(WebsiteDomain $domain): void
    {
        $domain->status = WebsiteDomainStatus::Active;
        $domain->activated_at = now();
        $domain->failure_reason = null;
        $domain->next_attempt_at = null;

        // If a LATER domain becomes Active while no Active domain is primary, the site would be unreachable.
        $hasLivePrimary = $domain->website->domains()
            ->where('id', '!=', $domain->id)
            ->where('is_primary', true)
            ->where('status', WebsiteDomainStatus::Active->value)
            ->exists();

        if (! $hasLivePrimary) {
            $domain->website->domains()->where('id', '!=', $domain->id)->update(['is_primary' => false]);
            $domain->is_primary = true;
        }

        // The trusted-host allowlist must know a newly Active domain on the very next request.
        Cache::forget(TrustHosts::ACTIVE_DOMAINS_CACHE_KEY);
    }

    private function fail(WebsiteDomain $domain, string $reason): void
    {
        $domain->status = WebsiteDomainStatus::Failed;
        $domain->failure_reason = $reason;
        $domain->next_attempt_at = null;
    }

    private function newToken(): string
    {
        do {
            $token = strtolower(Str::random(32));
        } while (WebsiteDomain::where('verification_token', $token)->exists());

        return $token;
    }

    /** Apex + www, same owner row, same transaction. Either both are reserved or the caller's transaction rolls back. */
    private function reserveBoth(WebsiteDomain $domain): void
    {
        $reservations = app(DomainReservations::class);

        try {
            $reservations->claim($domain->domain, DomainReservations::WEBSITE, (int) $domain->id);
            $reservations->claim($domain->wwwHost(), DomainReservations::WEBSITE_WWW, (int) $domain->id);
        } catch (DomainUnavailableException $e) {
            throw ValidationException::withMessages(['domain' => [$e->getMessage()]]);
        }
    }

    /** Domains created before www was reserved gain the reservation on retry; refused if someone else holds it. */
    private function reserveWwwIfMissing(WebsiteDomain $domain): void
    {
        $held = DB::table('domain_reservations')
            ->where('owner_type', DomainReservations::WEBSITE_WWW)->where('owner_id', $domain->id)->exists();

        if (! $held) {
            try {
                app(DomainReservations::class)->claim($domain->wwwHost(), DomainReservations::WEBSITE_WWW, (int) $domain->id);
            } catch (DomainUnavailableException $e) {
                throw ValidationException::withMessages(['domain' => [$e->getMessage()]]);
            }
        }
    }

    /**
     * Stops serving this domain, and relinquishes its primary-address
     * claim, IMMEDIATELY and unconditionally — an owner who clicked
     * "Remove" must never keep seeing their site served on the address
     * they just asked to disconnect, no matter what happens next.
     *
     * The row itself, `domain` string, and `forge_domain_id` are only
     * ever deleted once Forge actually confirms the domain resource is
     * gone (or there was never one to confirm — see attemptDeletion()).
     * A domain stuck unable to confirm removal stays in the Removing
     * status: its hostname remains claimed (the same uniqueness check
     * attach() always runs sees this row) and its forge_domain_id
     * remains on the row, so a retry (calling this method again on the
     * same row) targets the exact same Forge resource rather than
     * orphaning it and leaking a duplicate. Never silently frees a
     * hostname, or discards a provider reference, while that provider
     * resource might still exist.
     */
    public function remove(Website $website, WebsiteDomain $domain): void
    {
        abort_unless($domain->website_id === $website->id, 404);

        $wasActive = $domain->isActive();
        $wasPrimary = $domain->is_primary;

        if ($domain->status !== WebsiteDomainStatus::Removing) {
            $domain->status = WebsiteDomainStatus::Removing;
            $domain->is_primary = false;
            $domain->failure_reason = null;
            $domain->save();

            if ($wasPrimary) {
                $website->domains()->where('status', WebsiteDomainStatus::Active->value)
                    ->orderBy('id')
                    ->first()
                    ?->update(['is_primary' => true]);
            }

            if ($wasActive) {
                Cache::forget(TrustHosts::ACTIVE_DOMAINS_CACHE_KEY);
            }
        }

        $this->attemptDeletion($domain);
    }

    /**
     * The one place forge_domain_id is ever cleared or the row ever
     * deleted for a domain being removed — only once Forge itself
     * confirms the resource is gone (detachDomain() throws otherwise,
     * including on a transport failure, and never on a 404, which
     * means the resource is already gone). A domain that never reached
     * a domain that is still waiting for DNS when removed
     * has no forge_domain_id and nothing to confirm, so it deletes
     * immediately.
     */
    private function attemptDeletion(WebsiteDomain $domain): void
    {
        $forgeIds = array_values(array_filter([$domain->forge_domain_id, $domain->www_forge_domain_id]));

        if ($forgeIds === []) {
            $domain->delete();

            return;
        }

        try {
            foreach ($forgeIds as $forgeId) {
                $this->provisioner->detachDomain($forgeId);
            }
        } catch (Throwable $exception) {
            $domain->failure_reason = 'Removal could not be confirmed and will need to be retried: '.$exception->getMessage();
            $domain->save();

            return;
        }

        $domain->delete();
    }

    /**
     * @throws ValidationException when $domain is not yet Active
     */
    public function makePrimary(Website $website, WebsiteDomain $domain): WebsiteDomain
    {
        abort_unless($domain->website_id === $website->id, 404);

        if (! $domain->isActive()) {
            throw ValidationException::withMessages([
                'domain' => ['Only an active domain — verified, with a live certificate — can become the primary address.'],
            ]);
        }

        return DB::transaction(function () use ($website, $domain) {
            Website::whereKey($website->id)->lockForUpdate()->first();

            $website->domains()->where('id', '!=', $domain->id)->update(['is_primary' => false]);
            $domain->update(['is_primary' => true]);

            return $domain->fresh();
        });
    }

    private static function normalize(string $raw): string
    {
        $domain = trim($raw);
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = strtok($domain, '/');
        $domain = strtok($domain, '?');
        $domain = strtok($domain, ':');

        // A leading www. is the same site: the apex is what gets connected (www is always set up beside it).
        return preg_replace('/^www\\./', '', strtolower(trim((string) $domain, '.')));
    }

    /**
     * @throws ValidationException
     */
    private static function validateFormat(string $domain): void
    {
        if ($domain === '' || strlen($domain) > 255) {
            throw ValidationException::withMessages(['domain' => ['Enter a valid domain name.']]);
        }

        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages(['domain' => ['Enter a domain name, not an IP address.']]);
        }

        if (! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domain)) {
            throw ValidationException::withMessages(['domain' => ['Enter a valid domain name, e.g. example.com.']]);
        }

        $platformHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($platformHost !== '' && ($domain === $platformHost || str_ends_with($domain, '.'.$platformHost))) {
            throw ValidationException::withMessages(['domain' => ['This domain is reserved by the platform.']]);
        }
    }
}
