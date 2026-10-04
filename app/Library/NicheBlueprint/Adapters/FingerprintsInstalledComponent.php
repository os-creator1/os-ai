<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Models\Business;

/**
 * Optional beside BlueprintComponentAdapter: an adapter that copies rows into a
 * Business implements this so the installer can store a fingerprint at install
 * time and the update detector can later tell whether the owner changed the
 * copy. Returns null when the copy no longer exists (deleted/archived).
 *
 * The descriptor payload is passed because a component that installs several
 * rows (a tag set) needs it to know which rows to fingerprint.
 */
interface FingerprintsInstalledComponent
{
    /** @param array<string, mixed> $payload */
    public function fingerprint(Business $business, InstalledComponentReference $reference, array $payload): ?string;
}
