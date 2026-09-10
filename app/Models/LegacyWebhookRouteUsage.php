<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy Provider Webhook Measurement Contract §3.4 — one aggregate row per
 * (route_name, provider_slug). This is usage telemetry, not request
 * surveillance: the table has no column capable of holding a payload, a
 * phone number, a header, a signature, a credential or an IP address.
 *
 * Rows are written exclusively through the atomic update-or-insert shape in
 * App\Http\Middleware\RecordLegacyWebhookUsage (§3.5) — never through a
 * read-modify-write on this model.
 */
class LegacyWebhookRouteUsage extends Model
{
    protected $table = 'legacy_webhook_route_usage';

    protected $fillable = [
        'route_name',
        'provider_slug',
        'hit_count',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'hit_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
