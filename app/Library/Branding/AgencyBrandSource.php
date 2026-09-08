<?php

namespace App\Library\Branding;

/**
 * Customer Experience Slice 2 — the single extension point through which
 * an authoritative, server-resolved tenancy signal may select an Agency
 * white-label brand for an unauthenticated request (contract §9.1).
 *
 * The only input is the request host, taken by AgencyBrandResolver from
 * the server-resolved request (never a query parameter, a submitted
 * Workspace uid, a session value or "the first Workspace"). At this base
 * no implementation is bound: the repository has no custom-domain
 * mapping and the `white_label` platform feature is still Planned
 * (App\Library\Entitlement\PlatformFeatureRegistry), so every request
 * resolves to the neutral or owner-platform identity. A future
 * white-label slice binds an implementation in the container; nothing
 * else changes.
 */
interface AgencyBrandSource
{
    public function forHost(string $host): ?AgencyBrand;
}
