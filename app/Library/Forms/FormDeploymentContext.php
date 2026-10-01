<?php

namespace App\Library\Forms;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormVersion;

/**
 * Forms V1 — every authoritative row a public submission acts on, each RE-READ
 * from persistence by `FormDeploymentResolver` and mutually proven: the form
 * belongs to the Business, the Location belongs to that same Business, the
 * version belongs to the form. Nothing here came from the request.
 */
final class FormDeploymentContext
{
    public function __construct(
        public readonly FormDeployment $deployment,
        public readonly Form $form,
        public readonly FormVersion $version,
        public readonly Business $business,
        public readonly BusinessLocation $location,
    ) {
    }

    /** The same proven rows, with the version replaced by one the caller has already proven. */
    public function withVersion(FormVersion $version): self
    {
        return new self($this->deployment, $this->form, $version, $this->business, $this->location);
    }
}
