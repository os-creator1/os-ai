<?php

namespace App\Library\Branding;

/**
 * Customer Experience Slice 2 — the raw white-label brand an
 * AgencyBrandSource claims for one Workspace. Nothing here is trusted
 * until AgencyBrandResolver has re-checked the Workspace itself (active,
 * Agency tier) and AuthBrandPresenter has normalized every asset
 * reference; the presenter never hands this object to Blade.
 */
final class AgencyBrand
{
    public function __construct(
        public readonly string $workspaceUid,
        public readonly string $displayName,
        public readonly ?string $logoPath = null,
        public readonly ?string $tagline = null,
        public readonly ?string $accentColor = null,
    ) {
    }
}
