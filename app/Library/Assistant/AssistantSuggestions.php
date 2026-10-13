<?php

namespace App\Library\Assistant;

/**
 * The chips under the Ask box. They are CONTEXTUAL — setup help while the Business is still launching, performance
 * and optimisation questions once it is running — and each is only offered when the tool that answers it is available
 * to this actor. Each chip is the literal question it sends, so choosing one is the same as typing it.
 */
final class AssistantSuggestions
{
    /** @var array<string, array<int, array{key: string, label: string, needs: string}>> */
    private const SETS = [
        'setup' => [
            ['key' => 'setup_first', 'label' => 'What should I set up first?', 'needs' => 'growth_recommendations|schedule|website_and_listings'],
            ['key' => 'website_status', 'label' => 'Is my website ready to go live?', 'needs' => 'website_and_listings'],
            ['key' => 'booking_type', 'label' => 'Help me create a booking type', 'needs' => 'create_booking_type'],
            ['key' => 'open_website', 'label' => 'Open my website', 'needs' => 'open_page'],
            ['key' => 'add_contact', 'label' => 'Add a contact', 'needs' => 'open_page'],
        ],
        'growth' => [
            ['key' => 'how_doing', 'label' => 'How is my business doing this month?', 'needs' => 'business_snapshot'],
            ['key' => 'focus_next', 'label' => 'What should I focus on next?', 'needs' => 'growth_recommendations'],
            ['key' => 'seo_improved', 'label' => 'Which keywords improved recently?', 'needs' => 'seo_status'],
            ['key' => 'unreplied', 'label' => "Show customers who haven't received a reply", 'needs' => 'unanswered_conversations'],
            ['key' => 'ads_help', 'label' => "Why aren't my ads performing well?", 'needs' => 'ads_status'],
            ['key' => 'upcoming', 'label' => 'Show upcoming appointments', 'needs' => 'schedule'],
        ],
    ];

    public function __construct(private readonly AssistantToolRegistry $tools)
    {
    }

    /** @return array<int, array{key: string, label: string, available: bool, prompt: string}> */
    public function for(AssistantContext $context, bool $launching): array
    {
        $reads = $this->tools->availableReads($context);
        $actions = $this->tools->availableActions($context);
        $out = [];

        foreach (self::SETS[$launching ? 'setup' : 'growth'] as $chip) {
            $available = false;

            foreach (explode('|', $chip['needs']) as $need) {
                if (isset($reads[$need]) || isset($actions[$need])) {
                    $available = true;
                }
            }

            $out[] = ['key' => $chip['key'], 'label' => $chip['label'], 'available' => $available, 'prompt' => $chip['label']];
        }

        return $out;
    }
}
