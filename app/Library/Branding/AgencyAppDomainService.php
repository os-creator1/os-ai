<?php

namespace App\Library\Branding;

use App\Library\Domains\DomainReservations;
use App\Library\Domains\DomainUnavailableException;
use App\Exceptions\Branding\AgencyWhiteLabelException;
use App\Library\Website\Domains\DnsVerifier;
use App\Models\AgencyWhiteLabelChange;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Workspace;
use App\Models\WebsiteDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * White Label — the Agency's branded app hostname (e.g. app.agencybrand.com).
 *
 * WHAT THIS DOES: records the hostname, hands the Agency a TXT ownership token,
 * and marks the hostname `verified` only when DnsVerifier (the same exact-match
 * TXT proof website custom domains use) finds that token.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: make the platform answer on that host.
 * Nothing here touches TrustHosts, sessions, cookies, AgencyBrandSource or the
 * hosting provisioner, so `verified` means "ownership proven", never "live".
 * Host-based routing is a separate, infrastructure-gated step; until it exists
 * the UI states that plainly rather than reporting the domain as active.
 *
 * Same authority as a brand write (AgencyWhiteLabelManager::assertCanManage on
 * the locked Workspace). The Agency is the Workspace passed in; there is no
 * "which agency" parameter to name another.
 */
class AgencyAppDomainService
{
    public const STATUS_NONE = 'none';

    public const STATUS_PENDING = 'pending_verification';

    public const STATUS_VERIFIED = 'verified';

    public function __construct(
        private readonly AgencyWhiteLabelManager $whiteLabel,
        private readonly DnsVerifier $dns,
    ) {
    }

    public static function txtHost(string $domain): string
    {
        return '_platform-verify.' . $domain;
    }

    /** @return array{ownership: array{type: string, host: string, value: string}, traffic: array{type: string, target: ?string}} */
    public function dnsInstructions(AgencyWhiteLabelSetting $setting): array
    {
        return [
            'ownership' => [
                'type' => 'TXT',
                'host' => self::txtHost((string) $setting->app_domain),
                'value' => (string) $setting->app_domain_token,
            ],
            'traffic' => [
                'type' => 'CNAME',
                'target' => config('services.forge.cname_target'),
            ],
        ];
    }

    /** @throws AgencyWhiteLabelException */
    public function attach(int $actorUserId, Workspace $agency, string $rawDomain): AgencyWhiteLabelSetting
    {
        $domain = self::normalize($rawDomain);
        $this->assertNotReserved($domain);

        return DB::transaction(function () use ($actorUserId, $agency, $domain): AgencyWhiteLabelSetting {
            $locked = Workspace::query()->whereKey($agency->id)->lockForUpdate()->firstOrFail();
            $this->whiteLabel->assertCanManage($locked, $actorUserId);

            $setting = $this->whiteLabel->find($locked)
                ?? throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::NO_DOMAIN, 'Save your brand name first, then add the app domain.');

            if ($setting->app_domain === $domain) {
                return $setting;
            }

            $taken = AgencyWhiteLabelSetting::query()->where('app_domain', $domain)->exists()
                || WebsiteDomain::query()->where('domain', $domain)->exists();

            if ($taken) {
                throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::DOMAIN_TAKEN);
            }

            $before = $setting->app_domain;

            // Re-pointing releases the old hostname and claims the new one in this same transaction.
            $reservations = app(DomainReservations::class);
            $reservations->release(DomainReservations::AGENCY_APP, (int) $setting->id);

            try {
                $reservations->claim($domain, DomainReservations::AGENCY_APP, (int) $setting->id);
            } catch (DomainUnavailableException $e) {
                throw AgencyWhiteLabelException::because($e->reason === DomainUnavailableException::PLATFORM ? AgencyWhiteLabelException::DOMAIN_RESERVED : AgencyWhiteLabelException::DOMAIN_TAKEN);
            }

            $setting->forceFill([
                'app_domain' => $domain,
                'app_domain_token' => Str::random(40),
                'app_domain_status' => self::STATUS_PENDING,
                'app_domain_verified_at' => null,
                'app_domain_checked_at' => null,
            ])->save();

            $this->audit($locked, $actorUserId, ['app_domain' => [$before, $domain]]);

            return $setting;
        });
    }

    /** Idempotent: an already-verified domain is returned unchanged. */
    public function check(int $actorUserId, Workspace $agency): AgencyWhiteLabelSetting
    {
        return DB::transaction(function () use ($actorUserId, $agency): AgencyWhiteLabelSetting {
            $locked = Workspace::query()->whereKey($agency->id)->lockForUpdate()->firstOrFail();
            $this->whiteLabel->assertCanManage($locked, $actorUserId);

            $setting = $this->whiteLabel->find($locked);

            if ($setting === null || $setting->app_domain === null) {
                throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::NO_DOMAIN);
            }

            if ($setting->app_domain_status === self::STATUS_VERIFIED) {
                return $setting;
            }

            $setting->app_domain_checked_at = now();

            if ($this->dns->hasTxtRecord(self::txtHost($setting->app_domain), (string) $setting->app_domain_token)) {
                $setting->app_domain_status = self::STATUS_VERIFIED;
                $setting->app_domain_verified_at = now();
                $this->audit($locked, $actorUserId, ['app_domain_status' => [self::STATUS_PENDING, self::STATUS_VERIFIED]]);
            }

            $setting->save();

            return $setting;
        });
    }

    public function remove(int $actorUserId, Workspace $agency): AgencyWhiteLabelSetting
    {
        return DB::transaction(function () use ($actorUserId, $agency): AgencyWhiteLabelSetting {
            $locked = Workspace::query()->whereKey($agency->id)->lockForUpdate()->firstOrFail();
            $this->whiteLabel->assertCanManage($locked, $actorUserId);

            $setting = $this->whiteLabel->find($locked);

            if ($setting === null || $setting->app_domain === null) {
                throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::NO_DOMAIN);
            }

            $before = $setting->app_domain;
            app(DomainReservations::class)->release(DomainReservations::AGENCY_APP, (int) $setting->id);
            $setting->forceFill([
                'app_domain' => null, 'app_domain_token' => null, 'app_domain_status' => self::STATUS_NONE,
                'app_domain_verified_at' => null, 'app_domain_checked_at' => null,
            ])->save();

            $this->audit($locked, $actorUserId, ['app_domain' => [$before, null]]);

            return $setting;
        });
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changes */
    private function audit(Workspace $agency, int $actorUserId, array $changes): void
    {
        AgencyWhiteLabelChange::query()->create([
            'agency_workspace_id' => $agency->id,
            'changed_by_user_id' => $actorUserId,
            'change_type' => AgencyWhiteLabelChange::TYPE_UPDATED,
            'changes' => $changes,
        ]);
    }

    /** @throws AgencyWhiteLabelException */
    public static function normalize(string $raw): string
    {
        $domain = strtolower(trim($raw));

        $valid = $domain !== ''
            && strlen($domain) <= 191
            && preg_match('/^(?=.{1,191}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1
            && filter_var($domain, FILTER_VALIDATE_IP) === false;

        if (! $valid) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_DOMAIN);
        }

        return $domain;
    }

    /** The platform's own hosts (and anything beneath them) can never be claimed. */
    private function assertNotReserved(string $domain): void
    {
        try {
            app(DomainReservations::class)->assertNotPlatformHost($domain);
        } catch (DomainUnavailableException) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::DOMAIN_RESERVED);
        }
    }
}
