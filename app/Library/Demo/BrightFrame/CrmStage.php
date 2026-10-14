<?php

namespace App\Library\Demo\BrightFrame;

use App\Enums\Crm\CrmContactStatus;
use App\Enums\Crm\CrmContactStatusSource;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;

/** CRM board + Opportunities: one pipeline, 18 deals across every stage, some won and lost. */
final class CrmStage implements DemoStage
{
    /** [contact index, title, value (USD), stage position 0-3, outcome open|won|lost, days ago created, contact status] */
    private const DEALS = [
        [0, 'Thompson Wedding: Signature Package', 1149, 3, 'open', 9, 'in_contact'],
        [1, 'Queen City Software Summer Mixer: 360 Video Booth', 1299, 2, 'open', 6, 'in_contact'],
        [2, 'Nair-Shah Wedding: Luxe Package', 1599, 2, 'open', 12, 'in_contact'],
        [3, 'Bennett 40th Birthday: Classic Booth', 649, 1, 'open', 4, 'in_contact'],
        [4, 'Costa Wedding: Glam Mirror Booth', 949, 0, 'open', 1, 'no_contact'],
        [5, 'Piedmont Health Gala: Branded Activation', 2199, 3, 'open', 14, 'in_contact'],
        [6, 'Lindqvist Prom Night: Classic Booth', 599, 1, 'open', 3, 'in_contact'],
        [7, 'Brooks Backyard Birthday: Roaming Photographer', 499, 0, 'open', 0, 'no_contact'],
        [8, 'Whitfield Wedding: Signature Package', 949, 2, 'open', 8, 'in_contact'],
        [9, 'Carolina Craft Distillers Launch: 360 Video Booth', 1049, 1, 'open', 5, 'in_contact'],
        [10, 'Herrera Quinceañera: Luxe Package', 1299, 0, 'open', 2, 'no_contact'],
        [11, 'Fitzgerald Graduation Party: Classic Booth', 549, 1, 'open', 2, 'in_contact'],
        [12, 'Rahman Wedding: Signature Package', 1149, 3, 'won', 38, 'in_contact'],
        [13, 'Southline Logistics Holiday Party: Glam Mirror Booth', 899, 2, 'won', 30, 'in_contact'],
        [14, 'Liu Sweet Sixteen: Classic Booth', 649, 1, 'won', 26, 'in_contact'],
        [15, 'Matthews Wedding: Essential Package', 699, 2, 'won', 44, 'in_contact'],
        [16, 'Moretti Wedding: Luxe Package', 1299, 3, 'lost', 52, 'in_contact'],
        [17, 'Sutton Graduation Open House: Classic Booth', 549, 1, 'lost', 33, 'in_contact'],
    ];

    private const LOST_REASONS = [
        'Moretti Wedding: Luxe Package' => 'Chose a venue with an in-house booth',
        'Sutton Graduation Open House: Classic Booth' => 'Date no longer available',
    ];

    public function __construct(
        private readonly CrmPipelineService $pipelines,
        private readonly CrmOpportunityService $opportunities,
    ) {
    }

    public function run(DemoContext $context): void
    {
        $business = $context->business;
        $pipeline = $this->pipelines->setUpStandardPipeline($business, $context->user->id);
        $this->pipelines->renamePipeline($pipeline, 'Event bookings');
        $pipeline = $pipeline->fresh();
        $stages = $pipeline->stages()->orderBy('position')->get()->values();

        $contacts = array_values($context->refs['contacts']);
        $created = 0;

        foreach (self::DEALS as [$index, $title, $value, $stagePosition, $outcome, $daysAgo, $contactStatus]) {
            $opportunity = CrmOpportunity::query()->where('business_id', $business->id)->where('title', $title)->first();

            if ($opportunity === null) {
                $opportunity = $this->opportunities->create(
                    $business,
                    $pipeline,
                    $contacts[$index],
                    $title,
                    $value * 100,
                    $stages[0],
                    $context->user->id,
                    $this->sourceFor($index),
                );

                if ($stagePosition > 0) {
                    $this->opportunities->moveToStage($opportunity, $stages[$stagePosition], $context->user->id);
                }
                if ($contactStatus === 'in_contact') {
                    $this->opportunities->setContactStatus($opportunity->fresh(), CrmContactStatus::InContact, CrmContactStatusSource::Manual, $context->user->id);
                }
                if ($outcome === 'won') {
                    $this->opportunities->markWon($opportunity->fresh(), $context->user->id);
                } elseif ($outcome === 'lost') {
                    $this->opportunities->markLost($opportunity->fresh(), self::LOST_REASONS[$title] ?? null, $context->user->id);
                }

                $at = $context->daysFromNow(-$daysAgo, 11);
                $opportunity->fresh()->forceFill([
                    'created_at' => $at,
                    'stage_entered_at' => $at->addDays(min(2, $daysAgo)),
                    'updated_at' => $context->daysFromNow(-max(0, $daysAgo - 3), 15),
                ] + ($outcome === 'won' ? ['won_at' => $at->addDays(min(6, $daysAgo))] : []) + ($outcome === 'lost' ? ['lost_at' => $at->addDays(min(9, $daysAgo))] : []))->save();
                $created++;
            }

            $context->refs['opportunities'][$title] = $opportunity->fresh();
        }

        $context->refs['pipeline'] = $pipeline;
        $context->note('opportunities', CrmOpportunity::query()->where('business_id', $business->id)->count());
        $context->note('opportunities_created_now', $created);
    }

    private function sourceFor(int $index): string
    {
        return $index % 3 === 0 ? CrmOpportunity::SOURCE_FORM : CrmOpportunity::SOURCE_MANUAL;
    }
}
