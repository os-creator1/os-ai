<?php

namespace App\Enums\NicheBlueprint;

/**
 * How a Blueprint component reaches a Business after the first install.
 *
 * Copy: the Business owns a copy; a later Blueprint version only ever raises
 *       "update available" and never replaces the copy.
 * Live: nothing is copied; the Business reads the latest published Blueprint
 *       (or a table the publish step syncs) at read time, so a new version
 *       propagates by design.
 */
enum BlueprintUpdatePolicy: string
{
    case Copy = 'copy';

    case Live = 'live';
}
