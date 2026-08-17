<?php

namespace App\Enums\Usage;

/**
 * business_funding_attempt_transitions.source (RFC-005 §26, M3 contract
 * §21.4) — the shared 4-value transition-source enum RFC-005 §26 names,
 * first implemented by M3's own transitions table, reusable as-is by a
 * future M4 slot/renewal/add-on transitions table without redefinition.
 */
enum TransitionSource: string
{
    case SyncResponse = 'sync_response';
    case WebhookEvent = 'webhook_event';
    case AdminAction = 'admin_action';
    case ReconciliationJob = 'reconciliation_job';
}
