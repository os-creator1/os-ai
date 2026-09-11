@extends('layouts/contentLayoutMaster')

{{--
    Add contact / Import when the Business has several groups (pick one) or
    none yet (create its first list, "Contacts", in one click — no trip
    through the Groups screen). With exactly one group the controller goes
    straight to that group's existing form.
--}}

@php
    $workspaceUid = request()->route('workspaceUid');
    $businessUid = request()->route('businessUid');
    $heading = $purpose === 'import' ? 'Import contacts' : 'Add contact';
@endphp

@section('title', $heading)

@section('content')
    <section id="contacts-choose-group">
        <div class="mb-1">
            <a href="{{ route('customer.workspaces.businesses.people.index', [$workspaceUid, $businessUid]) }}">&larr; All contacts</a>
        </div>

        <div class="row">
            <div class="col-12 col-lg-6">
                <x-card :title="$heading">
                    @if (! empty($groups))
                        <p class="text-caption">Which group should {{ $purpose === 'import' ? 'these contacts' : 'this contact' }} go in?</p>
                        <ul class="list-group" data-role="contacts-group-choices">
                            @foreach ($groups as $group)
                                <li class="list-group-item"><a href="{{ $group['url'] }}">{{ $group['name'] }}</a></li>
                            @endforeach
                        </ul>
                    @elseif ($canCreateFirstList)
                        <p class="text-caption">Contacts are kept in a group. We'll create one called “Contacts” for you — you can rename it or add more groups any time.</p>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.people.first-list', [$workspaceUid, $businessUid]) }}" data-role="contacts-first-list">
                            @csrf
                            <input type="hidden" name="next" value="{{ $purpose }}">
                            <x-button type="submit" variant="primary">Continue</x-button>
                        </form>
                    @else
                        <p class="mb-0">There are no groups to add contacts to yet. Ask the account owner to create one.</p>
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
