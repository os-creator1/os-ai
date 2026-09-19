<?php

namespace App\Enums\Catalog;

/**
 * Implementation Contract 16 §5.1 — display/categorization only, matching
 * the feature's compound nav label "Packages & Products". Carries no
 * behavioral difference anywhere else in the schema: this contract models
 * one canonical catalog-item table, never a Package-contains-Products
 * bundling relationship.
 */
enum CatalogItemType: string
{
    case Product = 'product';
    case Package = 'package';
}
