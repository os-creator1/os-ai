<?php

namespace App\Library\Coo\Context;

/**
 * Implementation Contract 19 §5.8 R-24 — the capability keys that COO fact
 * composition actually consults, and therefore the only permission keys that
 * belong in `authorization_scope_fingerprint`.
 *
 * WHY A CLOSED LIST. R-24 is "only the capability keys that fact composition
 * actually consults, sorted". A wider list would make the fingerprint churn on
 * permissions the COO never reads; a narrower one would let a permission
 * change go unnoticed and leave a richer cached answer readable by someone who
 * lost the permission that produced it. So the list is source-controlled,
 * explicit, and grows exactly when a fact source starts consulting a new key.
 *
 * TODAY IT IS ONE KEY. `view_reports` is the capability that decides whether
 * an actor may see the Business performance figures the COO's only shipped
 * insight describes: it gates the Home results surface
 * (BusinessHomePresenter's DashboardLinkGate call) and the "Explain this
 * change" boundary (CooInsightExplainController's Gate check). Sub-slice 19.B
 * makes each fact source permission-aware and extends this list as it does —
 * adding a key here is a deliberate, reviewed act, not a side effect.
 *
 * These are Gate ability names, resolved through the application's existing
 * customer-permission gates (AuthServiceProvider defines one per
 * config('customer-permissions') key). No second permission mechanism is
 * introduced (Contract 19 R-0).
 */
final class CooCapabilityKeys
{
    /**
     * The consulted keys, in their canonical sorted order.
     *
     * @var array<int, string>
     */
    public const CONSULTED = [
        'view_reports',
    ];

    private function __construct()
    {
    }
}
