{{--
    Growth Center — Advisor. A closed list of owner questions. Every answer is
    built from the open Opportunities (engine order) and links to them; AI, when
    it is on, only explains in plainer words and can never add, remove or
    re-rank anything. With AI off, this page is complete.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Growth Center — Advisor')

@section('page-style')
    @include('customer.business.growth._styles')
@endsection

@php
    $aiNote = match ($answer['ai'] ?? null) {
        'used' => 'Explained with AI from your Growth Center data. The plan itself comes from your account.',
        'disabled' => 'AI explanations are off. This answer comes straight from your data.',
        'refused' => 'AI explanations are not available right now. This answer comes straight from your data.',
        'rejected', 'unavailable' => 'This answer comes straight from your data.',
        default => null,
    };
@endphp

@section('content')
    @include('customer.business.growth._header')

    <div class="gc">
        <section class="gc-panel mb-2" data-role="advisor-questions">
            <h2 class="gc-panel-title">Ask about your business</h2>
            <form method="POST" action="{{ route('customer.workspaces.businesses.growth.advisor.ask', [$workspaceUid, $businessUid]) }}" class="gc-chips mb-0">
                @csrf
                @foreach($questions as $key => $label)
                    <button type="submit" name="question" value="{{ $key }}" class="gc-chip @if(($answer['question'] ?? null) === $label) is-active @endif" data-question="{{ $key }}">{{ $label }}</button>
                @endforeach
            </form>
            <p class="gc-note">Answers use only what is in your account. They never invent customers, numbers or promises.</p>
        </section>

        @if($errors->has('question'))
            <x-alert variant="danger" icon="alert-circle" class="mb-2">Please choose one of the questions above.</x-alert>
        @endif

        @if($answer)
            <section class="gc-panel" data-role="advisor-answer" data-ai="{{ $answer['ai'] ?? 'not_used' }}">
                <h2 class="gc-panel-title">{{ $answer['question'] }}</h2>
                <p style="font-size:1.05rem; font-weight:500;" data-role="advisor-lead">{{ $answer['lead'] }}</p>

                @php $planNumber = 1; @endphp
                @foreach($answer['sections'] as $section)
                    <h3 class="gc-panel-title mt-2">{{ $section['title'] }}</h3>
                    <ol class="gc-bullets" style="grid-template-columns:1fr" start="{{ $planNumber }}" data-section="{{ \Illuminate\Support\Str::slug($section['title']) }}">
                        @foreach($section['items'] as $i => $item)
                            <li style="grid-template-columns:1.5rem 1fr" data-role="advisor-item">
                                <strong>{{ $planNumber++ }}.</strong>
                                <span>
                                    @if(! empty($item['uid']))
                                        <a href="{{ $item['detail_url'] }}" data-role="advisor-item-link">{{ $item['headline'] }}</a>
                                        <small class="d-block gc-flat">{{ $item['category'] }} · {{ $item['impact'] }} impact · {{ $item['action_label'] }}</small>
                                        @if(! empty($item['why']))<small class="d-block">{{ $item['why'] }}</small>@endif
                                        @if(! empty($item['note']))<small class="d-block gc-flat" data-role="advisor-note">{{ $item['note'] }}</small>@endif
                                    @else
                                        {{ $item['text'] ?? '' }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endforeach

                @foreach($answer['notes'] as $note)
                    <p class="gc-note" data-role="advisor-unavailable">{{ $note }}</p>
                @endforeach

                @if($aiNote)<p class="gc-note" data-role="advisor-ai-note">{{ $aiNote }}</p>@endif
            </section>
        @else
            <div class="gc-panel text-center" data-role="advisor-empty">
                <x-ds-icon name="message-circle" size="28" />
                <p class="gc-section-title mt-1">Pick a question to get started.</p>
                <p class="gc-empty-line">The answer lists real opportunities from your account, each one linked so you can act on it.</p>
            </div>
        @endif
    </div>
@endsection
