<?php

namespace App\Library\Domains;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one place a hostname is reserved. See the `domain_reservations` migration for why it exists.
 *
 * claim() MUST run inside the transaction that creates (or re-points) the owning row, so a rollback releases the
 * reservation with it and a committed claim always has its owner row. The UNIQUE index decides every race; the
 * application-level look-ups in the two services are only there to give a friendlier message earlier.
 */
final class DomainReservations
{
    public const WEBSITE = 'website_domain';

    public const AGENCY_APP = 'agency_app_domain';

    /** @throws DomainUnavailableException the hostname is reserved by the platform or already claimed by someone else */
    public function claim(string $domain, string $ownerType, int $ownerId): void
    {
        $domain = strtolower(trim($domain));

        $this->assertNotPlatformHost($domain);
        $this->purgeStale($domain);

        try {
            DB::table('domain_reservations')->insert([
                'domain' => $domain, 'owner_type' => $ownerType, 'owner_id' => $ownerId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Duplicate `domain`, or this owner already holds a (different) reservation: either way not claimable.
            if ($this->isDuplicate($e)) {
                throw DomainUnavailableException::taken();
            }

            throw $e;
        }
    }

    public function release(string $ownerType, int $ownerId): void
    {
        DB::table('domain_reservations')->where('owner_type', $ownerType)->where('owner_id', $ownerId)->delete();
    }

    /**
     * A reservation whose owner row is gone (a website removed by a database cascade, which fires no model event)
     * must not block the hostname for ever. Only a reservation that no longer matches a real owner is dropped.
     */
    private function purgeStale(string $domain): void
    {
        $row = DB::table('domain_reservations')->where('domain', $domain)->first();

        if ($row === null) {
            return;
        }

        $alive = $row->owner_type === self::WEBSITE
            ? DB::table('website_domains')->where('id', $row->owner_id)->where('domain', $domain)->exists()
            : DB::table('agency_white_label_settings')->where('id', $row->owner_id)->where('app_domain', $domain)->exists();

        if (! $alive) {
            DB::table('domain_reservations')->where('id', $row->id)->delete();
        }
    }

    public function isReserved(string $domain): bool
    {
        return DB::table('domain_reservations')->where('domain', strtolower(trim($domain)))->exists();
    }

    /** The platform's own hosts (and anything beneath them, or any parent of them) can never be claimed. */
    public function assertNotPlatformHost(string $domain): void
    {
        $reserved = array_filter(array_merge(
            [parse_url((string) config('app.url'), PHP_URL_HOST)],
            (array) config('marketing.hosts', []),
        ));

        foreach ($reserved as $host) {
            $host = strtolower((string) $host);

            if ($domain === $host || str_ends_with($domain, '.'.$host) || str_ends_with($host, '.'.$domain)) {
                throw DomainUnavailableException::platform();
            }
        }
    }

    private function isDuplicate(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
