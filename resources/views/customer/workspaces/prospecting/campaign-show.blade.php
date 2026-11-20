@extends('layouts/contentLayoutMaster')

@section('title', $campaign->name)
@section('page-heading', 'own')

@section('content')
@php
    $st = $campaign->status->value;
    $isDraft = $st === 'draft';
    $managed = $campaign->isManaged();
    $report = $campaign->recipient_report ?? [];
    $groupLinkOk = $linkedGroup !== null && $groupBusiness !== null;
    $enrolled = $members->count();
    $ready = $readiness?->isReady() ?? true;
    $canStart = $managed ? ($enrolled > 0 && $ready) : true;
    $startHint = $managed ? ($enrolled === 0 ? 'Add at least one recipient to start.' : (! $ready ? ($readiness->firstBlockingReason() ?? 'Finish the checklist to start.') : null)) : null;
    $step1 = $sequenceSteps[0]['body'] ?? null;
    $step2 = $sequenceSteps[1]['body'] ?? null;
    $sameAsMessage1 = $step1 !== null && trim((string) old('opening_message', $campaign->opening_message)) === trim($step1);
    $tone = ['active' => 'g', 'paused' => 'a'][$st] ?? 'n';
    $campaignsUrl = route('customer.workspaces.prospecting.campaigns.index', $workspaceUid);
    $stageLabel = fn ($s) => \App\Library\AgencyOutreach\OutreachProspectStatusPresenter::stageLabel($s);
    $ini = fn ($name) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(preg_replace('/[^\p{L}\p{N} ]/u', '', (string) $name) ?: '?', 0, 2));
@endphp
<link rel="stylesheet" href="{{ asset('css/outreach/outreach.css') }}?v=1">
<div class="og page">
    <a class="back" href="{{ $campaignsUrl }}"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>Campaigns</a>

    {{-- ============ LIVE (started) — managed campaigns only ============ --}}
    @if($managed && $launch !== null && in_array($st, ['active', 'paused'], true))
        <section class="hero" data-role="campaign-launched" aria-labelledby="og-hero-title">
            @foreach([['8%','40px','#FFD27A','20deg'],['24%','30px','#7CF0AE','-30deg'],['70%','50px','#9CC8F0','35deg'],['82%','120px','#FFD27A','10deg']] as [$l,$t,$c,$r])
                <span class="conf" aria-hidden="true" style="left:{{ $l }};top:{{ $t }};background:{{ $c }};transform:rotate({{ $r }})"></span>
            @endforeach
            <div style="position:relative;flex:1 1 420px;display:flex;flex-direction:column;gap:12px">
                <span class="live"><i></i>{{ $st === 'active' ? 'LIVE' : 'PAUSED' }}</span>
                <h1 id="og-hero-title">{{ $st === 'active' ? 'Your campaign is out there! 🚀' : 'Your campaign is paused' }}</h1>
                <p>“{{ $campaign->name }}” {{ $st === 'active' ? 'is texting' : 'was texting' }} {{ $launch['total'] }} local {{ \Illuminate\Support\Str::plural('business', $launch['total']) }}. Every reply is a new chance to land a client.</p>
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:6px">
                    <a class="btn w" href="{{ route('customer.workspaces.prospecting.conversations.index', $workspaceUid) }}?campaign={{ $campaign->uid }}">Watch replies come in</a>
                    <a class="btn g" href="{{ $campaignsUrl }}">Back to campaigns</a>
                </div>
            </div>
            <div style="position:relative;flex:0 1 300px;display:flex;justify-content:center" aria-hidden="true">
                <svg viewBox="0 0 220 180" width="260" height="210"><circle cx="110" cy="90" r="78" fill="rgba(255,255,255,.12)"/><circle cx="110" cy="90" r="56" fill="rgba(255,255,255,.12)"/><path d="M40 110l140-62-46 104-22-34z" fill="#FFFFFF"/><path d="M112 118l68-70-90 58z" fill="#F3D5D1"/><path d="M112 118l6 30 14-30z" fill="#E7B9B3"/><path d="M20 140c20-6 30-14 36-24M8 120c14-2 22-6 30-12" stroke="rgba(255,255,255,.7)" stroke-width="4" stroke-linecap="round" fill="none" stroke-dasharray="2 10"/></svg>
            </div>
        </section>

        <div class="titlebar">
            <span class="pl {{ $tone }}" data-role="campaign-status">{{ ucfirst($st) }}</span>
            <span class="grow"></span>
            <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.status', [$workspaceUid, $campaign->uid]) }}">
                @csrf
                @if($st === 'active')
                    <input type="hidden" name="status" value="paused">
                    <button type="submit" class="btn out">Pause campaign</button>
                @else
                    <input type="hidden" name="status" value="active">
                    <button type="submit" class="btn pri">Resume campaign</button>
                @endif
            </form>
            <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.end', [$workspaceUid, $campaign->uid]) }}"
                  onsubmit="return confirm('Permanently end this campaign? No more automatic messages will be sent, and its recipients can be used in another campaign. Message history is preserved. This cannot be undone.');">
                @csrf
                <button type="submit" class="btn out">End campaign</button>
            </form>
        </div>
        <span class="mut" style="margin-top:-8px">Stops this campaign and releases its contacts for new campaigns. Keeps SMS history.</span>

        <div class="kpis">
            <div class="k5" data-role="launch-sent"><span>Texts sent</span><b>{{ $launch['sent'] }} <span style="font-size:15px;color:var(--og-mute2);font-weight:600">/ {{ $launch['total'] }}</span></b>
                <div class="bar"><i style="width:{{ $launch['total'] > 0 ? min(100, round($launch['sent'] / $launch['total'] * 100)) : 0 }}%"></i></div></div>
            <div class="k5 {{ $launch['replies'] > 0 ? 'win' : '' }}" data-role="launch-replies"><span>Replies</span><b>{{ $launch['replies'] }}{{ $launch['replies'] > 0 ? ' 🎉' : '' }}</b><small>{{ $launch['replies'] > 0 ? 'Prospects who texted back' : 'No replies yet' }}</small></div>
            <div class="k5" data-role="launch-interested"><span>Interested</span><b>{{ $launch['interested'] }}</b><small>{{ $launch['interested'] > 0 ? 'Asked for a call or got your link' : 'Nobody yet' }}</small></div>
            <div class="k5" data-role="launch-booked"><span>Calls booked</span><b>{{ $launch['booked'] }}</b><small>{{ $launch['booked'] > 0 ? 'On your calendar' : 'None yet' }}</small></div>
        </div>

        <div class="grid2">
            <section class="card pad" style="flex:3 1 520px" data-role="live-activity" aria-labelledby="og-act">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px"><b id="og-act" style="flex:1;font-size:16px">Live activity</b>@if($st === 'active')<span class="live-dot"><i></i>live</span>@endif</div>
                @forelse($launch['activity'] as $a)
                    <div class="act">
                        <span class="av" aria-hidden="true">{{ $ini($a['name']) }}</span>
                        <div><b style="font-size:14px;font-weight:600">{{ $a['name'] }}</b><span class="mut">{{ $a['text'] }}</span></div>
                        <span class="pl {{ $a['tone'] }}">{{ $a['pill'] }}</span>
                        <span class="hint" style="width:84px;text-align:right">{{ $a['at'] }}</span>
                    </div>
                @empty
                    <div class="empty" data-role="activity-empty"><b>No activity yet</b>Texts and replies from this campaign appear here as they happen.</div>
                @endforelse
            </section>

            <section class="card miles" style="flex:2 1 300px;padding:20px;display:flex;flex-direction:column;gap:12px" data-role="next-milestone">
                <span style="font-size:30px" aria-hidden="true">🏆</span>
                <b style="font-size:17px">{{ $launch['milestone']['title'] }}</b>
                <span style="font-size:13.5px;line-height:1.5;color:var(--og-neutral)">{{ $launch['milestone']['text'] }}</span>
                @php $steps = $launch['milestone']['steps']; $curIdx = collect($steps)->search(fn ($s) => ! $s['done']); @endphp
                <div class="bars" aria-hidden="true">@foreach($steps as $i => $s)<span class="{{ $s['done'] ? 'done' : ($i === $curIdx ? 'cur' : '') }}"></span>@endforeach</div>
                <span class="mut">{{ collect($steps)->pluck('label')->join(' · ') }}</span>
            </section>
        </div>

    {{-- ============ DRAFT / ENDED / LEGACY header ============ --}}
    @else
        <div style="display:flex;flex-direction:column;gap:4px">
            <div class="titlebar">
                <h1>{{ $campaign->name }}</h1>
                <span class="pl {{ $tone }}" data-role="campaign-status">{{ ucfirst($st) }}</span>
                <span class="grow"></span>
                @if($isDraft)
                    <button type="submit" form="og-config" class="btn out">Save draft</button>
                    <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.start', [$workspaceUid, $campaign->uid]) }}" style="margin:0" id="og-start">
                        @csrf
                        <button type="submit" class="btn pri" @disabled(! $canStart) @if(! $canStart) title="{{ $startHint }}" @endif>Start campaign</button>
                    </form>
                @endif
            </div>
            @if($isDraft && $startHint)<span style="font-size:13.5px;color:var(--og-amber)" data-role="start-hint">{{ $startHint }}</span>@endif
            @if($campaign->context)<span class="mut" style="font-size:13.5px">{{ $campaign->context }}</span>@endif
        </div>

        @if(! $isDraft)
            @if($managed)
                <div class="note" data-role="campaign-ended"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/></svg>
                    <span>This campaign has ended permanently. No more messages will be sent from it. Its contacts are available for a new campaign; SMS history is preserved.</span></div>
            @else
                <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.status', [$workspaceUid, $campaign->uid]) }}" class="panel" style="flex-direction:row;align-items:center;flex-wrap:wrap">
                    @csrf
                    <select name="status" class="in" style="max-width:200px" aria-label="Campaign status">
                        <option value="active" @selected($st === 'active')>Active</option>
                        <option value="paused" @selected($st === 'paused')>Paused</option>
                    </select>
                    <button type="submit" class="btn pri">Update status</button>
                </form>
            @endif
            <div class="kpis">
                <div class="k5"><span>Prospects</span><b>{{ $summary['prospects'] }}</b></div>
                <div class="k5"><span>Replies</span><b>{{ $summary['replies'] }}</b></div>
                <div class="k5"><span>Calls booked</span><b>{{ $summary['booked'] }}</b></div>
            </div>
        @endif
    @endif

    {{-- ============ Two-column body: first text/script/readiness  +  recipients ============ --}}
    <div class="grid2">
        <div style="flex:3 1 520px;min-width:0;display:flex;flex-direction:column;gap:16px">
            @if($isDraft)
                <section class="panel" aria-labelledby="og-first">
                    <span class="eyebrow" id="og-first">1 · First text</span>
                    <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.config', [$workspaceUid, $campaign->uid]) }}" id="og-config" style="display:flex;flex-direction:column;gap:14px">
                        @csrf
                        @unless($managed)
                            <div class="fl">
                                <label for="channel_uid">Channel</label>
                                <select id="channel_uid" name="channel_uid" class="in">
                                    <option value="">Select a channel…</option>
                                    @foreach($availableChannels as $availableChannel)
                                        <option value="{{ $availableChannel->uid }}" @selected($campaign->channel_id === $availableChannel->id)>{{ $availableChannel->provider }} — {{ $availableChannel->sender_number }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endunless
                        <textarea id="opening_message" name="opening_message" class="in" rows="3" aria-label="First text" @unless($managed) placeholder="Hi @{{contact_name}}, this is @{{agency_name}}..." @endunless>{{ old('opening_message', $campaign->opening_message) }}</textarea>
                        @unless($managed)<span class="hint">Placeholders: @{{company_name}}, @{{contact_name}}, @{{agency_name}}</span>@endunless
                    </form>
                    @if($managed && $sameAsMessage1)
                        <div class="note"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/></svg>
                            <span>This is the same as Message 1 of your script, so after a reply we skip straight to Message 2. <a href="{{ route('customer.workspaces.prospecting.script.show', $workspaceUid) }}">Edit script</a></span></div>
                    @elseif($managed)
                        <div class="note"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/></svg>
                            <span>This text is just for this campaign. Your script's follow-ups still run after a reply. <a href="{{ route('customer.workspaces.prospecting.script.show', $workspaceUid) }}">Edit script</a></span></div>
                    @endif
                    @if($managed && $step2)
                        <div class="after" data-role="after-reply"><span class="mut">After they reply, we send</span><span style="font-size:14px">{{ \Illuminate\Support\Str::limit($step2, 220) }}</span></div>
                    @endif
                </section>
            @endif

            @if($managed && $readiness !== null && ($isDraft || ! $ready))
                <section class="panel" style="flex-direction:row;flex-wrap:wrap;align-items:center;gap:10px 14px;padding:18px 20px" data-role="campaign-readiness" aria-label="Ready to send">
                    <b style="font-size:15px;margin-right:4px">{{ $ready ? 'Ready to send' : 'Not ready to send yet' }}</b>
                    <div class="chips">
                        @foreach($readiness->items() as $item)
                            <span class="chip {{ $item['ok'] ? '' : 'no' }}" data-readiness="{{ $item['key'] }}" data-ok="{{ $item['ok'] ? '1' : '0' }}">
                                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true">@if($item['ok'])<path d="M5 12.5l4.5 4.5L19 7.5"/>@else<circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/>@endif</svg>{{ $item['label'] }}
                            </span>
                        @endforeach
                    </div>
                    @foreach($readiness->items() as $item)
                        @if(! $item['ok'])
                            <span class="mut" style="flex-basis:100%">{{ $item['reason'] }}@if(! empty($item['url'])) <a class="link" href="{{ $item['url'] }}">Open</a>@endif</span>
                        @endif
                    @endforeach
                </section>
            @endif

            @if($managed && $diagnostics !== null)
                <section class="panel" data-role="reply-diagnostics" aria-labelledby="og-diag">
                    <span class="eyebrow" id="og-diag">After a prospect replies</span>
                    @foreach($diagnostics['warnings'] as $warning)
                        <div class="note" data-role="reply-warning"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/></svg><span>{{ $warning }}</span></div>
                    @endforeach
                    @if(! empty($diagnostics['deferred']))
                        <div data-role="deferred-sends">
                            <span class="pl a">Rate-limited</span>
                            @foreach($diagnostics['deferred'] as $d)
                                <span class="mut" style="display:block;margin-top:4px" data-reason="{{ $d['reason'] }}">{{ $d['count'] }} {{ \Illuminate\Support\Str::plural('message', $d['count']) }} waiting. {{ $d['label'] }}
                                    @if($d['next_at'])Next attempt around {{ \Illuminate\Support\Carbon::parse($d['next_at'])->diffForHumans() }}.@endif</span>
                            @endforeach
                        </div>
                    @endif
                    @if($diagnostics['first_reply'])
                        <p data-role="first-reply"><strong>First message sent after a reply: {{ $diagnostics['first_reply']['label'] }}</strong>
                            <span class="mut" style="display:block">{{ $diagnostics['first_reply']['preview'] }}</span></p>
                    @endif
                    @if(! empty($diagnostics['next_by_member']))
                        <b style="font-size:14px">Next automated message per prospect</b>
                        <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px" data-role="next-by-member">
                            @foreach($diagnostics['next_by_member'] as $row)
                                <li>{{ $row['name'] }}: <strong>{{ $row['next'] }}</strong>
                                    @if($row['preview'])<span class="mut" style="display:block">{{ \Illuminate\Support\Str::limit($row['preview'], 90) }}</span>@endif</li>
                            @endforeach
                        </ul>
                    @endif
                    @if($diagnostics['silent_replies']->isNotEmpty())
                        <b style="font-size:14px">Replies that did not get a text back</b>
                        <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px" data-role="silent-replies">
                            @foreach($diagnostics['silent_replies'] as $reply)
                                <li>“{{ \Illuminate\Support\Str::limit($reply->body, 60) }}”
                                    <span class="mut" style="display:block" data-reason="{{ $reply->failure_reason }}">{{ \App\Library\AgencyOutreach\OutreachCampaignDiagnostics::reasonLabel($reply->failure_reason) }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                    @if(empty($diagnostics['warnings']) && empty($diagnostics['deferred']) && ! $diagnostics['first_reply'] && empty($diagnostics['next_by_member']) && $diagnostics['silent_replies']->isEmpty())
                        <span class="mut">Nothing to flag. Replies are answered from your script and approved answers.</span>
                    @endif
                </section>
            @endif

            @unless($isDraft)
                <section class="panel" data-role="enrolled-frozen"><b>Enrolled prospects are frozen</b>
                    <span class="mut">This campaign has started — its enrolled prospect set can no longer be changed.</span></section>
            @endunless
        </div>

        {{-- Recipients --}}
        <section class="panel" style="flex:2 1 340px;min-width:0" data-role="campaign-recipients" aria-labelledby="og-rec">
            <span class="eyebrow" id="og-rec">{{ $isDraft ? '2 · ' : '' }}Recipients</span>

            @if($managed)
                @if($campaign->usesContactGroup())
                    @if($linkedGroup === null)
                        <div class="note" data-role="group-missing"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16h.01"/></svg><span>This campaign's contact group is no longer available. Choose another group before starting.</span></div>
                    @else
                        <div style="display:flex;align-items:center;gap:12px" data-role="linked-group">
                            <span style="width:42px;height:42px;border-radius:12px;background:var(--og-brand-s);color:var(--og-brand-d);display:flex;align-items:center;justify-content:center;flex:none"><svg class="ic" viewBox="0 0 24 24" style="width:19px;height:19px"><circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5M16 5a3.5 3.5 0 0 1 0 6.5M18 14.8c1.8.8 3 2.6 3 5.2"/></svg></span>
                            <div style="flex:1;display:flex;flex-direction:column;min-width:0"><b style="font-size:15px" class="ell">Group “{{ $linkedGroup->name }}”</b>
                                <span class="mut">{{ number_format($linkedGroupCount) }} {{ \Illuminate\Support\Str::plural('contact', $linkedGroupCount) }} in group</span></div>
                            @if($groupBusiness !== null)<a class="link" style="font-size:13px" href="{{ route('customer.workspaces.businesses.contacts.show', [$workspaceUid, $groupBusiness->uid, $linkedGroup->uid]) }}">Manage this group in Contacts</a>@endif
                        </div>
                    @endif

                    <div class="stat2" data-role="recipient-counts">
                        <div data-role="count-enrolled"><b>{{ $enrolled }}</b><span class="mut">{{ \Illuminate\Support\Str::plural('recipient', $enrolled) }} will be texted</span><span class="sr">{{ $enrolled }} enrolled {{ \Illuminate\Support\Str::plural('recipient', $enrolled) }}</span></div>
                        @if(isset($report['group_total']))
                            <div class="{{ ($report['excluded'] ?? 0) > 0 ? 'warn' : '' }}" data-role="count-excluded"><b>{{ $report['excluded'] ?? 0 }}</b><span class="mut" style="color:inherit">left out</span><span class="sr">{{ $report['excluded'] ?? 0 }} of {{ $report['group_total'] }} contacts excluded at last sync</span></div>
                        @endif
                    </div>

                    @if(! empty($report['reasons']))
                        <details class="more" data-role="excluded-contacts" @if($isDraft) open @endif>
                            <summary>Excluded contacts — why {{ $report['excluded'] ?? 0 }} {{ ($report['excluded'] ?? 0) === 1 ? 'was' : 'were' }} left out<svg class="ic" viewBox="0 0 24 24" style="width:14px;height:14px"><path d="M7 10l5 5 5-5"/></svg></summary>
                            <div style="display:flex;flex-direction:column;gap:6px;padding-top:6px;font-size:13px">
                                @foreach($report['reasons'] as $reason => $count)
                                    <div style="display:flex;justify-content:space-between"><span>{{ \App\Library\AgencyOutreach\OutreachContactGroupBridge::reasonLabel($reason) }}</span><span class="mut">{{ $count }}</span></div>
                                @endforeach
                                @if(! empty($report['skipped']))
                                    <div style="border-top:1px solid var(--og-line3);margin-top:4px;padding-top:6px;display:flex;flex-direction:column;gap:4px">
                                        @foreach($report['skipped'] as $row)
                                            <div style="display:flex;justify-content:space-between;gap:8px"><span>{{ $row['phone'] !== '' ? $row['phone'] : '(blank)' }}</span><span class="mut">{{ \App\Library\AgencyOutreach\OutreachContactGroupBridge::reasonLabel($row['reason']) }}</span></div>
                                        @endforeach
                                    </div>
                                    @if(count($report['skipped']) < ($report['excluded'] ?? 0))<span class="mut">Showing the first {{ count($report['skipped']) }}.</span>@endif
                                @endif
                            </div>
                        </details>
                    @endif

                    @if($campaign->recipients_synced_at)
                        <div class="mut" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                            <svg class="ic" viewBox="0 0 24 24" style="width:14px;height:14px" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.5-4.5L4 8M4 4v4h4M4 13a8 8 0 0 0 14.5 4.5L20 16M20 20v-4h-4"/></svg>
                            <span>Recipient list captured {{ $campaign->recipients_synced_at->diffForHumans() }}.@unless($isDraft) It is frozen — changes to the group no longer affect this campaign.@endunless</span>
                            @if($isDraft && $groupLinkOk)
                                <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.recipients.refresh', [$workspaceUid, $campaign->uid]) }}" style="display:inline;margin:0">@csrf
                                    <button type="submit" class="link" style="border:0;background:none;padding:0">Refresh recipients</button></form>
                            @endif
                        </div>
                    @elseif($isDraft && $groupLinkOk)
                        <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.recipients.refresh', [$workspaceUid, $campaign->uid]) }}">@csrf
                            <button type="submit" class="btn out sm">Refresh recipients</button> <span class="mut">Re-reads the group. Nothing is sent.</span></form>
                    @endif
                @else
                    <p class="mut" data-role="legacy-recipients">This campaign was created before contact groups. Its recipients are the prospects enrolled individually.</p>
                @endif

                @if($isDraft && $groupBusiness !== null)
                    <details class="more" data-role="change-group-wrap" @if($errors->has('contact_group_uid')) open @endif>
                        <summary>{{ $campaign->usesContactGroup() ? 'Change group' : 'Use a contact group' }}<svg class="ic" viewBox="0 0 24 24" style="width:14px;height:14px"><path d="M7 10l5 5 5-5"/></svg></summary>
                        <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.recipients.group', [$workspaceUid, $campaign->uid]) }}" data-role="change-group" style="display:flex;flex-direction:column;gap:8px"
                              onsubmit="return confirm('Changing the group replaces this campaign\'s group recipients with the new group\'s eligible contacts. Prospects you added individually are kept. Nothing is sent.');">
                            @csrf
                            <label class="sr" for="change_group">{{ $campaign->usesContactGroup() ? 'Change group' : 'Use a contact group' }}</label>
                            <div style="display:flex;gap:8px">
                                <select id="change_group" name="contact_group_uid" class="in" required>
                                    <option value="">Select a group…</option>
                                    @foreach($contactGroups as $group)
                                        <option value="{{ $group->uid }}" @selected($linkedGroup?->id === $group->id)>{{ $group->name }} ({{ $group->subscribers_count }})</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn out">Apply</button>
                            </div>
                            <label style="display:flex;gap:8px;align-items:flex-start;font-size:12.5px;color:var(--og-mute)"><input type="checkbox" name="confirm" value="1" id="confirm_change" required style="margin-top:3px">I understand the group's recipients will be replaced by the new group's current eligible contacts.</label>
                        </form>
                    </details>
                @elseif(! $isDraft)
                    <span class="mut">The group can no longer be changed because this campaign has started.</span>
                @endif
            @endif

            @if($isDraft)
                <div style="height:1px;background:var(--og-line3)"></div>
                <div class="fl">
                    <label for="og-add-person">{{ ($managed && $campaign->usesContactGroup()) ? 'Add one person' : 'Enroll a prospect' }} <span>(optional)</span></label>
                    @if($enrollableProspects->isEmpty())
                        <span class="mut">No eligible prospects to enroll.</span>
                    @else
                        <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.members.store', [$workspaceUid, $campaign->uid]) }}" style="display:flex;gap:8px">
                            @csrf
                            <select id="og-add-person" name="prospect_uid" class="in" required>
                                <option value="">Search contacts</option>
                                @foreach($enrollableProspects as $prospect)
                                    <option value="{{ $prospect->uid }}">{{ $prospect->company_name }} ({{ $prospect->phone }})</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn out">Add</button>
                        </form>
                    @endif
                </div>
            @endif

            @if($members->isNotEmpty())
                <div data-role="enrolled-prospects" style="display:flex;flex-direction:column;max-height:340px;overflow:auto">
                    <span class="eyebrow" style="margin:4px 0 6px">Enrolled prospects</span>
                    @foreach($members as $member)
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 0;border-top:1px solid var(--og-line3)">
                            <a class="ell" href="{{ route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $member->prospect->uid]) }}">{{ $member->prospect->company_name }}</a>
                            <span class="pl n">{{ $stageLabel($member->stage) }}</span>
                        </div>
                    @endforeach
                </div>
            @elseif(! $isDraft)
                <span class="mut">No prospects enrolled.</span>
            @endif
        </section>
    </div>
</div>
@endsection
