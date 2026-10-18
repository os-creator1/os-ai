<?php

namespace Tests\Feature\Growth\Concerns;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Library\Growth\GrowthEvaluationService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CrmOpportunity;
use App\Models\Opportunity;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;

/**
 * Shared fixtures for the Growth Center tests: a Growth-tier Business that is
 * Active, the Opportunity Engine switched on, and small helpers that write the
 * canonical rows the Growth fact readers read (deals, conversations,
 * documents). Nothing here calls a provider or an AI.
 */
trait CreatesGrowthFixtures
{
    use CreatesCrmFixtures;

    protected Business $business;

    protected Workspace $workspace;

    protected BusinessLocation $primaryLocation;

    protected function setUpGrowthBusiness(): void
    {
        config(['opportunity.enabled' => true]);

        [, $business, $workspace] = $this->crmTenant();

        $this->business = $business;
        $this->workspace = $workspace;
        $this->primaryLocation = $this->growthLocation();
    }

    protected function evaluateGrowth(?CarbonImmutable $now = null): array
    {
        return app(GrowthEvaluationService::class)->evaluate($this->business->fresh(), $now);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Opportunity> */
    protected function growthOpportunities(?string $type = null)
    {
        return Opportunity::query()
            ->where('business_id', $this->business->id)
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->orderBy('id')
            ->get();
    }

    protected function growthLocation(string $name = "Main Street"): BusinessLocation
    {
        return BusinessLocation::create([
            "business_id" => $this->business->id,
            "name" => $name,
            "service_mode" => "storefront",
            "country_code" => "US",
        ]);
    }

    protected function secondLocation(string $name = "Second site"): BusinessLocation
    {
        return $this->growthLocation($name);
    }

    /** An open deal created $hoursAgo hours ago, still "No contact". */
    protected function unansweredDeal(int $hoursAgo = 48, ?int $valueMinor = 70000, string $title = 'Wedding booth', ?int $locationId = null): CrmOpportunity
    {
        $deal = $this->deal($this->business, valueMinor: $valueMinor, title: $title);

        DB::table('crm_opportunities')->where('id', $deal->id)->update([
            'created_at' => now()->subHours($hoursAgo),
            'updated_at' => now()->subHours($hoursAgo),
            'stage_entered_at' => now()->subHours($hoursAgo),
            'contact_status' => 'no_contact',
            'location_id' => $locationId ?? $this->primaryLocation->id,
        ]);

        // The service wrote a "created" history row at now(); a deal that is
        // hours or days old has history that old too.
        DB::table('crm_opportunity_history')->where('opportunity_id', $deal->id)->update(['created_at' => now()->subHours($hoursAgo)]);

        return $deal->fresh();
    }

    protected function markDealContacted(CrmOpportunity $deal): void
    {
        DB::table('crm_opportunities')->where('id', $deal->id)->update(['contact_status' => 'in_contact']);
    }

    protected function archiveLocation(BusinessLocation $location): void
    {
        DB::table('business_locations')->where('id', $location->id)->update([
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
        ]);
    }
    /**
     * A conversation with the given messages: [direction, hoursAgo, ?send_status].
     *
     * @param  array<int, array{0: string, 1: int, 2?: string|null}>  $messages
     */
    protected function conversation(array $messages, ?int $locationId = null): int
    {
        $boxId = DB::table('chat_boxes')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->business->customer_id,
            'business_id' => $this->business->id,
            'location_id' => $locationId ?? $this->primaryLocation->id,
            'from' => '15550001111',
            'to' => '15550002222',
            'notification' => 0,
            'created_at' => now()->subDays(20),
            'updated_at' => now(),
        ]);

        foreach ($messages as $m) {
            DB::table('chat_box_messages')->insert([
                'box_id' => $boxId,
                'message' => 'x',
                'sms_type' => 'sms',
                'direction' => $m[0],
                'send_status' => $m[2] ?? null,
                'created_at' => now()->subHours($m[1]),
                'updated_at' => now()->subHours($m[1]),
            ]);
        }

        return $boxId;
    }

    /**
     * A document with one issued version and optional schedule items:
     * each item [amountMinor, ?dueDaysFromNow, ?status].
     *
     * @param  array<int, array{0: int, 1?: int|null, 2?: string}>  $items
     */
    protected function document(string $status, array $attributes = [], array $items = [], int $total = 100000): int
    {
        $contact = $this->crmContact($this->business);

        $documentId = DB::table('business_documents')->insertGetId(array_merge([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $this->business->id,
            'business_location_id' => $this->primaryLocation->id,
            'contact_id' => $contact->id,
            'kind' => 'proposal',
            'status' => $status,
            'requires_signature' => true,
            'title' => 'Wedding package',
            'currency_code' => 'USD',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ], $attributes));

        $versionId = DB::table('business_document_versions')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_document_id' => $documentId,
            'version_number' => 1,
            'state' => 'issued',
            'content' => json_encode([]),
            'subtotal_minor' => $total,
            'total_minor' => $total,
            'currency_code' => 'USD',
            'issued_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        DB::table('business_documents')->where('id', $documentId)->update(['current_version_id' => $versionId]);

        foreach ($items as $i => $item) {
            DB::table('business_document_payment_schedule_items')->insert([
                'uid' => (string) \Illuminate\Support\Str::uuid(),
                'business_document_version_id' => $versionId,
                'sequence' => $i + 1,
                'kind' => $item[3] ?? 'full',
                'amount_minor' => $item[0],
                'currency_code' => 'USD',
                'due_at' => isset($item[1]) ? now()->addDays($item[1]) : null,
                'status' => $item[2] ?? 'pending',
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ]);
        }

        return $documentId;
    }

    protected function reviewLink(?int $locationId = null): void
    {
        DB::table('seo_location_review_links')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $this->business->id,
            'business_location_id' => $locationId ?? $this->primaryLocation->id,
            'review_url' => 'https://g.page/r/example/review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function reviewRequest(int $daysAgo, ?int $locationId = null): void
    {
        DB::table('seo_review_requests')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $this->business->id,
            'business_location_id' => $locationId ?? $this->primaryLocation->id,
            'channel' => 'sms',
            'status' => 'requested',
            'requested_at' => now()->subDays($daysAgo),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function bookingType(?int $locationId = null, bool $active = true): int
    {
        return DB::table('booking_types')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'public_booking_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'business_location_id' => $locationId ?? $this->primaryLocation->id,
            'name' => 'Photo booth hire',
            'duration_minutes' => 60,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function assignStaff(int $bookingTypeId, ?int $locationId = null, int $weeklyHours = 0): void
    {
        $staff = (int) $this->business->customer_id;

        DB::table('booking_type_staff')->insert([
            'booking_type_id' => $bookingTypeId,
            'staff_user_id' => $staff,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // $weeklyHours of availability, spread as one 1-hour window per weekday-slot
        for ($i = 0; $i < $weeklyHours; $i++) {
            DB::table('staff_availability_rules')->insert([
                'business_location_id' => $locationId ?? $this->primaryLocation->id,
                'staff_user_id' => $staff,
                'day_of_week' => $i % 7,
                'start_time' => sprintf('%02d:00:00', 9 + intdiv($i, 7)),
                'end_time' => sprintf('%02d:00:00', 10 + intdiv($i, 7)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
