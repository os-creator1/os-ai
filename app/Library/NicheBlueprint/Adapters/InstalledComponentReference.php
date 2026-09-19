<?php

namespace App\Library\NicheBlueprint\Adapters;

use InvalidArgumentException;

/**
 * Contract 20 §10 — what an adapter returns to say WHICH Business-owned row it
 * created, so the installer can write it into
 * `business_blueprint_component_installations.installed_record_type` /
 * `installed_record_id` as provenance (§5.4).
 *
 * Provenance only. Nothing dereferences these two values to make a decision,
 * and there is deliberately no foreign key behind them — they point across
 * bounded contexts (`crm_pipelines` today, others later), which a polymorphic
 * key cannot express.
 *
 * Validated on construction so a malformed reference fails in the adapter that
 * produced it rather than as a confusing database error one layer later.
 */
final readonly class InstalledComponentReference
{
    public function __construct(
        public string $recordType,
        public int $recordId,
    ) {
        if (trim($recordType) === '' || mb_strlen($recordType) > 64) {
            throw new InvalidArgumentException(
                'An installed component reference must carry a record type of 1-64 characters.'
            );
        }

        if ($recordId < 1) {
            throw new InvalidArgumentException(
                'An installed component reference must carry a positive record id.'
            );
        }
    }
}
