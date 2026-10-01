<?php

namespace App\Library\Branding;

/**
 * Customer Experience Slice 2 — the single extension point through which
 * an authoritative, server-resolved tenancy signal may select an Agency
 * white-label brand for an unauthenticated request (contract §9.1).
 *
 * The only input is the request host, taken by AgencyBrandResolver from
 * the server-resolved request (never a query parameter, a submitted
 * Workspace uid, a session value or "the first Workspace"). No
 * implementation is bound: the repository has no custom-domain mapping or
 * domain-verification lifecycle, so every UNAUTHENTICATED request resolves
 * to the neutral or owner-platform identity. (The `white_label` feature is
 * now Available and brands the SIGNED-IN client chrome through
 * ClientWorkspaceBrandResolver, which starts from the persisted management
 * relationship instead of a host — Implementation Contract 22 §4. That does
 * not bind this seam.) A future custom-domain slice binds an implementation
 * in the container; nothing else changes.
 */
interface AgencyBrandSource
{
    public function forHost(string $host): ?AgencyBrand;
}
