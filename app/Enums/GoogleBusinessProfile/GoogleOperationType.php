<?php

namespace App\Enums\GoogleBusinessProfile;

/**
 * GBP Slice A contract §11.3.2 — closed set. Every value describes a READ
 * or a local lifecycle transition; there is deliberately no mutation
 * operation type, because Slice A writes nothing to Google (§6, §14.2).
 */
enum GoogleOperationType: string
{
    case ConnectInitiated = 'connect_initiated';
    case ConnectCompleted = 'connect_completed';
    case ConnectFailed = 'connect_failed';
    case TokenRefreshed = 'token_refreshed';
    case Disconnected = 'disconnected';
    case AccountsEnumerated = 'accounts_enumerated';
    case LocationsEnumerated = 'locations_enumerated';
    case LocationBound = 'location_bound';
    case LocationUnbound = 'location_unbound';
    case MirrorRefreshed = 'mirror_refreshed';
    case MirrorPurged = 'mirror_purged';

    /**
     * SEO Contract 18 §7.3 / §21.B — extended ADDITIVELY for the second
     * Google product. The operations ledger is SHARED across products
     * (§7.3), so Search Console work is recorded in the same table with its
     * own types rather than in a parallel ledger.
     *
     * ADDITIVE MEANS ADDITIVE: every case above keeps its exact value, and
     * nothing here renames, reuses or repurposes one. Persisted rows written
     * before this change therefore still resolve, which is what makes the
     * shared ledger safe to extend.
     *
     * The connect/token/disconnect lifecycle above is deliberately NOT
     * duplicated per product: those types are product-neutral and the
     * connection row they reference already carries the product, so a
     * `search_console` connect is `connect_completed` on a `search_console`
     * row. Only the operations that have no Business Profile equivalent get
     * new types, and each mirrors its GBP sibling's naming:
     *
     *   properties_enumerated  <- accounts_enumerated / locations_enumerated
     *   property_bound         <- location_bound
     *   property_unbound       <- location_unbound
     *   metrics_synced         <- mirror_refreshed
     *   metrics_purged         <- mirror_purged
     *
     * Nothing in Sub-slice B emits these; Sub-slice C does. They are
     * declared now because §7.3 assigns the additive extension to this
     * sub-slice, and because a value that exists before its first writer
     * cannot be invented ad hoc at the call site later.
     *
     * All values fit `business_google_operations.operation_type`
     * (varchar(40)).
     */
    case PropertiesEnumerated = 'properties_enumerated';

    case PropertyBound = 'property_bound';

    case PropertyUnbound = 'property_unbound';

    case MetricsSynced = 'metrics_synced';

    case MetricsPurged = 'metrics_purged';
}
