<?php

namespace App\DTO\Analytics;

/**
 * Contract §13 — everything the overview renders, assembled by
 * BusinessAnalyticsPresenter and cached as a plain array under the
 * Business-scoped key (§11.3). `advisor` is null when the Opportunity
 * engine is disabled (§2.6) and `automations` is null when the
 * automation_executions table is absent (§2.6, §9) — absent, never zeros.
 */
final class BusinessAnalyticsViewModel
{
    /**
     * @param  array<string, mixed>  $business  uid, name, timezone
     * @param  array<string, mixed>  $range  AnalyticsDateRange::toArray()
     */
    public function __construct(
        public readonly array $business,
        public readonly array $range,
        public readonly CoverageNotice $coverage,
        public readonly MessageKpis $messages,
        public readonly DailySeries $messageVolume,
        public readonly CampaignKpis $campaigns,
        public readonly ContactKpis $contacts,
        public readonly DailySeries $contactGrowth,
        public readonly ?AdvisorKpis $advisor,
        public readonly ?AutomationKpis $automations,
        public readonly string $generatedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'business' => $this->business,
            'range' => $this->range,
            'coverage' => $this->coverage->toArray(),
            'messages' => $this->messages->toArray(),
            'message_volume' => $this->messageVolume->toArray(),
            'campaigns' => $this->campaigns->toArray(),
            'contacts' => $this->contacts->toArray(),
            'contact_growth' => $this->contactGrowth->toArray(),
            'advisor' => $this->advisor?->toArray(),
            'automations' => $this->automations?->toArray(),
            'generated_at' => $this->generatedAt,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['business'],
            $data['range'],
            CoverageNotice::fromArray($data['coverage']),
            MessageKpis::fromArray($data['messages']),
            DailySeries::fromArray($data['message_volume']),
            CampaignKpis::fromArray($data['campaigns']),
            ContactKpis::fromArray($data['contacts']),
            DailySeries::fromArray($data['contact_growth']),
            isset($data['advisor']) ? AdvisorKpis::fromArray($data['advisor']) : null,
            isset($data['automations']) ? AutomationKpis::fromArray($data['automations']) : null,
            (string) $data['generated_at'],
        );
    }
}
