@extends('layouts/contentLayoutMaster')

@section('title', 'Unanswered questions')
@section('page-heading', 'own')

@section('content')
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'unanswered', 'ogInside' => true])

    <div class="filters">
        <span class="mut" style="font-size:13px">Questions prospects asked that none of your approved answers covered. Teaching an answer changes future replies only &mdash; nothing is sent to the prospect when you save.</span>
    </div>

    @if($errors->any())
        <div class="alertbar" role="alert" data-role="unanswered-errors"><span>{{ $errors->first() }}</span></div>
    @endif

    <section class="card" aria-label="Unanswered questions">
        @if($items->isEmpty())
            <div class="empty" data-role="unanswered-empty"><b>No unanswered questions</b>When a prospect asks something your approved answers don't cover, it shows up here.</div>
        @else
            <div data-role="unanswered-list">
                @foreach($items as $item)
                    @php
                        $member = $item->campaignMember;
                        $link = $member !== null ? ($links[$member->id] ?? null) : null;
                        $choices = $options[$item->id] ?? [];
                    @endphp
                    <div class="row rsp" style="display:block;padding:14px 16px" data-unanswered="{{ $item->uid }}">
                        <div style="display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap">
                            <div style="min-width:0">
                                <b data-role="unanswered-question">&ldquo;{{ $item->question }}&rdquo;</b>
                                <div class="mut" style="font-size:13px">
                                    {{ ($item->asked_at ?? $item->created_at)?->diffForHumans() }}
                                    &middot; {{ $item->campaign?->name ?? 'No campaign' }}
                                    @if($link)&middot; <a href="{{ $link }}" data-role="unanswered-conversation">Open conversation</a>@endif
                                </div>
                            </div>
                            <form method="post" action="{{ route('customer.workspaces.prospecting.unanswered.dismiss', [$workspaceUid, $item->uid]) }}">
                                @csrf
                                <button type="submit" class="btn out sm" data-role="unanswered-dismiss">Dismiss</button>
                            </form>
                        </div>

                        <details class="qa" style="margin-top:10px">
                            <summary data-role="unanswered-add-open">Add answer</summary>
                            <form method="post" action="{{ route('customer.workspaces.prospecting.unanswered.answer', [$workspaceUid, $item->uid]) }}" style="display:flex;flex-direction:column;gap:8px;margin-top:8px">
                                @csrf
                                <label>Question
                                    <input class="in" type="text" name="question" maxlength="300" value="{{ $item->question }}">
                                </label>
                                <label>Approved answer
                                    <textarea class="in" name="answer" rows="3" maxlength="1000" required placeholder="The exact wording prospects should get"></textarea>
                                </label>
                                <label>Applies to
                                    <select class="in" name="scope">
                                        <option value="all">All campaigns{{ $niche ? ' (niche: ' . $niche . ')' : '' }}</option>
                                        @if($item->campaign)
                                            <option value="campaign">Only &ldquo;{{ $item->campaign->name }}&rdquo;</option>
                                        @endif
                                    </select>
                                </label>
                                <div><button type="submit" class="btn pri sm">Save answer</button></div>
                            </form>
                        </details>

                        <details class="qa" style="margin-top:6px">
                            <summary data-role="unanswered-link-open">Link existing answer</summary>
                            @if($choices === [])
                                <p class="mut" style="font-size:13px;margin-top:8px">No saved answers apply to this campaign yet.</p>
                            @else
                                <form method="post" action="{{ route('customer.workspaces.prospecting.unanswered.link', [$workspaceUid, $item->uid]) }}" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px">
                                    @csrf
                                    <label style="flex:1;min-width:220px">Answer this wording with
                                        <select class="in" name="faq_id" required>
                                            @foreach($choices as $choice)
                                                <option value="{{ $choice['id'] }}">{{ $choice['question'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <button type="submit" class="btn pri sm">Link</button>
                                </form>
                            @endif
                        </details>
                    </div>
                @endforeach
            </div>
            @if($items->hasPages())<div class="pager">{{ $items->links() }}</div>@endif
        @endif
    </section>
</div>
@endsection
