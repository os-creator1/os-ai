<?php

namespace Tests\Unit\Coo;

use App\Library\Coo\Insight\CooInsightFacts;
use App\Library\Coo\Insight\CooInsightOutputValidator;
use Tests\TestCase;

/**
 * AI-3 — contract §8.3/§8.4, T-INS-4. Every rejection path, and the whole
 * answer discarded when any one statement fails.
 */
class CooInsightOutputValidatorTest extends TestCase
{
    public function test_a_valid_answer_is_accepted_whole(): void
    {
        $statements = $this->validate([
            ['class' => 'known', 'text' => 'New contacts rose to 20, from 5 in the previous period.', 'fact_refs' => ['metric.new_contacts']],
            ['class' => 'likely', 'text' => 'More people may be finding the business than before.', 'fact_refs' => ['metric.new_contacts']],
            ['class' => 'unknown', 'text' => "Messages received rose after the website went live. We can't tell whether the website caused it.", 'fact_refs' => ['metric.messages_received', 'visibility.website']],
        ]);

        $this->assertIsArray($statements);
        $this->assertCount(3, $statements);
        $this->assertSame('known', $statements[0]['class']);
    }

    public function test_the_schema_is_fixed(): void
    {
        $validator = new CooInsightOutputValidator();
        $facts = $this->facts();
        $ok = ['class' => 'known', 'text' => 'New contacts rose to 20.', 'fact_refs' => ['metric.new_contacts']];

        foreach ([
            'not json' => 'New contacts rose to 20.',
            'a list, not an object' => json_encode([$ok]),
            'an extra top-level key' => json_encode(['statements' => [$ok], 'note' => 'x']),
            'no statements' => json_encode(['statements' => []]),
            'four statements' => json_encode(['statements' => [$ok, $ok, $ok, $ok]]),
            'an extra statement key' => json_encode(['statements' => [$ok + ['confidence' => 0.9]]]),
            'a missing key' => json_encode(['statements' => [['class' => 'known', 'text' => 'New contacts rose to 20.']]]),
            'an unknown class' => json_encode(['statements' => [['class' => 'certain'] + $ok]]),
            'empty text' => json_encode(['statements' => [['text' => '   '] + $ok]]),
            'text over 280 characters' => json_encode(['statements' => [['text' => 'New contacts rose to 20. ' . str_repeat('a', 280)] + $ok]]),
            'fact_refs not a list' => json_encode(['statements' => [['fact_refs' => 'metric.new_contacts'] + $ok]]),
            'an empty answer' => '',
        ] as $case => $raw) {
            $this->assertNull($validator->validate($raw, $facts), "Rejected: {$case}.");
        }

        $this->assertNull($validator->validate(null, $facts));
    }

    public function test_a_hallucinated_fact_reference_fails_rather_than_being_shown(): void
    {
        $this->assertNull($this->validate([
            ['class' => 'known', 'text' => 'New contacts rose to 20.', 'fact_refs' => ['metric.new_contacts', 'metric.revenue']],
        ]));

        $this->assertNull($this->validate([
            ['class' => 'unknown', 'text' => 'Bookings are not tracked here.', 'fact_refs' => ['attention.google_connection_lost']],
        ]), 'A reference to an Attention type that was not raised is not a fact.');
    }

    public function test_a_known_statement_must_cite_a_metric_and_restate_its_number(): void
    {
        $this->assertNull($this->validate([['class' => 'known', 'text' => 'New contacts rose to 20.', 'fact_refs' => []]]), 'Known with no fact_refs.');
        $this->assertNull($this->validate([['class' => 'known', 'text' => 'New contacts rose sharply.', 'fact_refs' => ['metric.new_contacts']]]), 'Known without the number.');
        $this->assertNull($this->validate([['class' => 'known', 'text' => 'New contacts rose to 21.', 'fact_refs' => ['metric.new_contacts']]]), 'Known with a number that is not the fact.');
        $this->assertNull($this->validate([['class' => 'known', 'text' => 'The website is published.', 'fact_refs' => ['visibility.website']]]), 'Known about a fact that has no number to check.');
        $this->assertNull($this->validate([['class' => 'known', 'text' => 'New contacts rose to 200.', 'fact_refs' => ['metric.new_contacts']]]), '20 inside 200 is not 20.');

        $this->assertNotNull($this->validate([['class' => 'known', 'text' => 'The previous period had 5 new contacts.', 'fact_refs' => ['metric.new_contacts']]]), 'The previous figure is a fact too.');
    }

    public function test_a_likely_statement_must_be_hedged(): void
    {
        $this->assertNull($this->validate([['class' => 'likely', 'text' => 'More people are finding the business.', 'fact_refs' => ['metric.new_contacts']]]));

        foreach (['may', 'likely', 'could'] as $hedge) {
            $this->assertNotNull($this->validate([['class' => 'likely', 'text' => "More people {$hedge} be finding the business.", 'fact_refs' => []]]), "'{$hedge}' is hedged wording.");
        }
    }

    public function test_causal_wording_is_rejected_in_every_class(): void
    {
        foreach (['caused', 'because of', 'led to', 'resulted in', 'drove', 'thanks to'] as $phrase) {
            foreach ([
                ['class' => 'likely', 'text' => "The website may have {$phrase} more contacts.", 'fact_refs' => []],
                ['class' => 'unknown', 'text' => "It is unclear what {$phrase} the change.", 'fact_refs' => []],
                ['class' => 'known', 'text' => "New contacts rose to 20, {$phrase} the website.", 'fact_refs' => ['metric.new_contacts']],
            ] as $statement) {
                $this->assertNull($this->validate([$statement]), "'{$phrase}' in a {$statement['class']} statement.");
            }
        }
    }

    public function test_one_bad_statement_discards_the_whole_answer(): void
    {
        $this->assertNull($this->validate([
            ['class' => 'known', 'text' => 'New contacts rose to 20.', 'fact_refs' => ['metric.new_contacts']],
            ['class' => 'likely', 'text' => 'The new website drove the rise.', 'fact_refs' => []],
        ]));
    }

    /** @param array<int, array<string, mixed>> $statements */
    private function validate(array $statements): ?array
    {
        return (new CooInsightOutputValidator())->validate(json_encode(['statements' => $statements]), $this->facts());
    }

    private function facts(): CooInsightFacts
    {
        return new CooInsightFacts(
            businessId: 1,
            periodKey: 'this_month_2026-09',
            periodLabel: 'This month',
            currentStart: '2026-09-01',
            currentEnd: '2026-09-10',
            previousStart: '2026-08-22',
            previousEnd: '2026-08-31',
            metrics: [
                'new_contacts' => ['current' => 20, 'previous' => 5, 'direction' => 'material_increase'],
                'conversations_started' => ['current' => 0, 'previous' => 0, 'direction' => 'insufficient_data'],
                'messages_received' => ['current' => 14, 'previous' => 3, 'direction' => 'material_increase'],
            ],
            attention: [],
            opportunities: [],
            visibility: ['website' => 'published', 'google' => null],
        );
    }
}
