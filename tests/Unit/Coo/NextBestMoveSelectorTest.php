<?php

namespace Tests\Unit\Coo;

use App\Enums\Dashboard\AttentionType;
use App\Library\Coo\NextBestMove;
use App\Library\Coo\NextBestMoveSelector;
use App\Library\Dashboard\AttentionItem;
use App\Models\Opportunity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unified Business Home §6.4 (C-2), T-NBM-1 — the selector's fixed order,
 * proven exhaustively and pairwise, with billing never a candidate.
 *
 * A pure function over already-built values, so this is a plain unit test:
 * no database, no container, no clock.
 */
class NextBestMoveSelectorTest extends TestCase
{
    /** The locked order, as the contract states it. The queue head sits at 5. */
    private const ORDER = [
        'conversations_awaiting_reply',
        'google_connection_lost',
        'automation_failing',
        'google_location_unhealthy',
        'opportunity',
        'website_unpublished',
    ];

    public function test_the_order_in_code_is_the_contract_order(): void
    {
        $this->assertSame(
            [AttentionType::ConversationsAwaitingReply, AttentionType::GoogleConnectionLost, AttentionType::AutomationFailing, AttentionType::GoogleLocationUnhealthy],
            NextBestMoveSelector::BEFORE_OPPORTUNITIES,
        );
        $this->assertSame([AttentionType::WebsiteUnpublished], NextBestMoveSelector::AFTER_OPPORTUNITIES);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function everyPair(): array
    {
        $pairs = [];

        foreach (self::ORDER as $i => $higher) {
            foreach (array_slice(self::ORDER, $i + 1) as $lower) {
                $pairs["{$higher} beats {$lower}"] = [$higher, $lower];
            }
        }

        return $pairs;
    }

    /**
     * Every one of the fifteen pairs, in both insertion orders: the winner
     * is decided by rank, never by which item arrived first.
     */
    #[DataProvider('everyPair')]
    public function test_the_higher_ranked_candidate_always_wins(string $higher, string $lower): void
    {
        foreach ([[$higher, $lower], [$lower, $higher]] as $arrival) {
            [$attention, $head] = $this->pool($arrival);

            $move = (new NextBestMoveSelector())->select($attention, $head);

            $this->assertNotNull($move);
            $this->assertSame($higher, $this->keyOf($move), 'Arrival order: ' . implode(', ', $arrival));
        }
    }

    public function test_each_candidate_alone_is_selected(): void
    {
        foreach (self::ORDER as $only) {
            [$attention, $head] = $this->pool([$only]);

            $this->assertSame($only, $this->keyOf((new NextBestMoveSelector())->select($attention, $head)));
        }
    }

    public function test_billing_is_never_a_candidate_even_when_it_is_all_there_is(): void
    {
        $billing = [
            AttentionType::WalletSuspended,
            AttentionType::OutstandingDebt,
            AttentionType::PaidActivityPaused,
            AttentionType::LowBalance,
            AttentionType::AutoRechargeFailing,
        ];

        $pool = array_map(fn (AttentionType $type) => $this->item($type), $billing);

        $this->assertNull((new NextBestMoveSelector())->select($pool, null), 'Billing has its own strip, never the move.');

        // And alongside a real candidate, the real candidate wins untouched.
        $pool[] = $this->item(AttentionType::WebsiteUnpublished);
        $this->assertSame('website_unpublished', $this->keyOf((new NextBestMoveSelector())->select($pool, null)));
    }

    public function test_nothing_to_do_is_null_so_the_caller_says_all_caught_up(): void
    {
        $this->assertNull((new NextBestMoveSelector())->select([], null));
    }

    public function test_the_queue_head_is_used_as_given_and_never_re_scored(): void
    {
        $head = new Opportunity(['priority_score' => 1, 'impact' => 1, 'urgency' => 1]);

        $move = (new NextBestMoveSelector())->select([$this->item(AttentionType::WebsiteUnpublished)], $head);

        $this->assertTrue($move->isOpportunity());
        $this->assertSame($head, $move->opportunity, 'Whatever RFC-002 ordering put first is what is recommended.');
    }

    public function test_exactly_one_move_is_returned_however_many_candidates_exist(): void
    {
        [$attention, $head] = $this->pool(self::ORDER);

        $move = (new NextBestMoveSelector())->select($attention, $head);

        $this->assertInstanceOf(NextBestMove::class, $move);
        $this->assertSame('conversations_awaiting_reply', $this->keyOf($move));
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<int, string>  $keys
     * @return array{0: array<int, AttentionItem>, 1: ?Opportunity}
     */
    private function pool(array $keys): array
    {
        $attention = [];
        $head = null;

        foreach ($keys as $key) {
            if ($key === 'opportunity') {
                $head = new Opportunity(['title' => 'Add your business phone number']);

                continue;
            }

            $attention[] = $this->item(AttentionType::from($key));
        }

        return [$attention, $head];
    }

    private function item(AttentionType $type): AttentionItem
    {
        return new AttentionItem($type, $type->severity(), 'Fixture Business', $type->sentence(), $type->actionLabel(), 'https://example.test/' . $type->value);
    }

    private function keyOf(?NextBestMove $move): ?string
    {
        if ($move === null) {
            return null;
        }

        return $move->isOpportunity() ? 'opportunity' : $move->attention->type->value;
    }
}
