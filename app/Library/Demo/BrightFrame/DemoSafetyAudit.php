<?php

namespace App\Library\Demo\BrightFrame;

use Illuminate\Support\Facades\DB;

/**
 * Proves, from the database, that the demo tenant has no way to message,
 * charge or call out: no messaging number/identity, no sender, no sending
 * server, no email account, no stored OAuth token, no live Stripe charges,
 * no connected domain or external calendar. Read-only.
 */
final class DemoSafetyAudit
{
    /**
     * @return array<string, string> failed check => explanation (empty = all clear)
     */
    public function violations(DemoContext $context): array
    {
        $businessId = (int) $context->business->id;
        $userId = (int) $context->user->id;
        $failed = [];

        $mustBeEmpty = [
            'business_messaging_identities' => ['business_id', $businessId],
            'business_messaging_registrations' => ['business_id', $businessId],
            'business_email_accounts' => ['business_id', $businessId],
            'external_calendar_connections' => ['user_id', $userId],
            'phone_numbers' => ['business_id', $businessId],
            'sending_servers' => ['user_id', $userId],
            'payment_provider_customers' => ['business_id', $businessId],
            'business_funding_attempts' => ['business_id', $businessId],
        ];

        foreach ($mustBeEmpty as $table => [$column, $value]) {
            $count = $this->count($table, $column, $value);
            if ($count !== 0) {
                $failed[$table] = "{$count} row(s) found; the demo tenant must have none.";
            }
        }

        $numbers = DB::table('business_messaging_numbers')->join('business_messaging_identities', 'business_messaging_identities.id', '=', 'business_messaging_numbers.business_messaging_identity_id')
            ->where('business_messaging_identities.business_id', $businessId)->count();
        if ($numbers !== 0) {
            $failed['business_messaging_numbers'] = "{$numbers} messaging number(s) found; the demo tenant must have none.";
        }

        $website = DB::table('website_domains')->join('websites', 'websites.id', '=', 'website_domains.website_id')->where('websites.business_id', $businessId)->count();
        if ($website !== 0) {
            $failed['website_domains'] = 'A custom domain is attached; the demo site must stay on the platform path.';
        }

        foreach (['business_google_connections' => 'refresh_token_encrypted', 'business_meta_connections' => 'access_token_encrypted'] as $table => $column) {
            $withToken = DB::table($table)->where('business_id', $businessId)->whereNotNull($column)->where($column, '!=', '')->count();
            if ($withToken !== 0) {
                $failed[$table] = 'A stored provider token exists; simulated connections must hold none.';
            }
        }

        $live = DB::table('business_stripe_connections')->where('business_id', $businessId)->where(fn ($q) => $q->where('charges_enabled', true)->orWhere('status', 'active'))->count();
        if ($live !== 0) {
            $failed['business_stripe_connections'] = 'A Stripe connection can take charges; the demo must not.';
        }

        $notifying = DB::table('booking_types')->join('business_locations', 'business_locations.id', '=', 'booking_types.business_location_id')
            ->where('business_locations.business_id', $businessId)->where(fn ($q) => $q->where('notify_email', true)->orWhere('notify_sms', true))->count();
        if ($notifying !== 0) {
            $failed['booking_types'] = 'A booking type would notify customers.';
        }

        $running = DB::table('automation_workflows')->where('business_id', $businessId)->where('status', 'published')->count();
        if ($running !== 0) {
            $failed['automation_workflows'] = "{$running} published workflow(s); demo workflows must stay draft or paused.";
        }

        $dueRank = DB::table('seo_rank_targets')->where('business_id', $businessId)->where('tracking_state', 'tracking')
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()->addYear()))->count();
        if ($dueRank !== 0) {
            $failed['seo_rank_targets'] = 'A rank target could be picked up by the scheduler (a paid provider call).';
        }

        return $failed;
    }

    private function count(string $table, string $column, int $value): int
    {
        $schema = DB::getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            return 0;
        }

        // Fail closed: a table this audit cannot interrogate is a failed audit, never a silent pass.
        if (! $schema->hasColumn($table, $column)) {
            throw new \RuntimeException("Safety audit cannot check [{$table}]: column [{$column}] is missing.");
        }

        return (int) DB::table($table)->where($column, $value)->count();
    }
}
