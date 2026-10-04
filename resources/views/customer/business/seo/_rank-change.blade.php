{{--
    Change vs the previous COMPLETED organic observation. Rank 1 is best, so a
    lower number is an improvement. Not-found transitions are named, never
    turned into a number. @param array{kind: string, amount: int|null} $change
--}}
@php use App\Library\Seo\Rank\SeoRankHistoryReader; @endphp
@switch($change['kind'])
    @case(SeoRankHistoryReader::CHANGE_UP)
        <span class="text-success fw-bold" data-role="rank-change" data-kind="up">↑ {{ $change['amount'] }}</span>
        @break
    @case(SeoRankHistoryReader::CHANGE_DOWN)
        <span class="text-danger fw-bold" data-role="rank-change" data-kind="down">↓ {{ $change['amount'] }}</span>
        @break
    @case(SeoRankHistoryReader::CHANGE_SAME)
        <span class="text-muted" data-role="rank-change" data-kind="same">No change</span>
        @break
    @case(SeoRankHistoryReader::CHANGE_ENTERED)
        <span class="text-success" data-role="rank-change" data-kind="entered">Newly ranking</span>
        @break
    @case(SeoRankHistoryReader::CHANGE_DROPPED)
        <span class="text-danger" data-role="rank-change" data-kind="dropped">Dropped out</span>
        @break
    @default
        <span class="text-muted" data-role="rank-change" data-kind="none">—</span>
@endswitch
