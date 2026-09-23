<?php

namespace App\Library\Documents;

use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Models\BusinessLocation;
use App\Models\Workspace;

/**
 * Implementation Contract 17 §6.3 — everything a public document request is
 * allowed to know, after PublicDocumentGuard has re-derived all of it from
 * persistence.
 *
 * It carries the FROZEN ISSUED VERSION, never the open draft: an end
 * customer must never see an in-progress revision of the thing they are
 * being asked to sign.
 */
final readonly class PublicDocumentAccess
{
    public function __construct(
        public BusinessDocument $document,
        public BusinessDocumentVersion $version,
        public Business $business,
        public Workspace $workspace,
        public BusinessLocation $location,
    ) {
    }
}
