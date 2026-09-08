@extends('layouts/contentLayoutMaster')

@php
    // Customer Experience Slice 1B (Correction Round 1, contract §5.3): a
    // Core/Growth customer reaches this list as "Accounts" and never reads
    // the word Workspace; Agency and unassigned accounts keep the
    // established Workspace wording. The resolved context is the one
    // App\Http\Middleware\ResolveCustomerContext stores on the request, so
    // the controller's view data keeps its exact key shape.
    $resolvedCustomerContext = request()->attributes->get('customerContext');
    $accountVocabulary = $resolvedCustomerContext instanceof \App\Library\Navigation\CustomerContext && $resolvedCustomerContext->usesBusinessVocabulary();
    $accountNoun = $accountVocabulary ? 'account' : 'Workspace';
    $accountNounPlural = $accountVocabulary ? 'accounts' : 'Workspaces';
    $accountTitle = $accountVocabulary ? 'Accounts' : 'Workspaces';
@endphp

@section('title', $accountTitle)

@section('content')
    <section id="workspace-index">
        <div class="row">
            <div class="col-12">
                <x-card :title="$accountTitle">
                    @if (session('flash_success'))
                        <x-alert variant="success">{{ session('flash_success') }}</x-alert>
                    @endif

                    @if (session('flash_error'))
                        <x-alert variant="danger">{{ session('flash_error') }}</x-alert>
                    @endif

                    @if ($errors->any())
                        <x-alert variant="danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-alert>
                    @endif

                    <form method="POST" action="{{ route('customer.workspaces.store') }}" class="mb-2">
                        @csrf

                        <x-input name="name" label="New {{ $accountNoun }} name" type="text" value="{{ old('name') }}" required />

                        <x-button type="submit" variant="primary">Create {{ $accountNoun }}</x-button>
                    </form>

                    @if ($workspaces->isEmpty())
                        <p class="mb-0">You don't have access to any {{ $accountNounPlural }} yet.</p>
                    @else
                        <x-table :headers="['Name', 'Role', 'Status']">
                            @foreach ($workspaces as $workspace)
                                <tr>
                                    <td><a href="{{ route('customer.workspaces.show', $workspace['uid']) }}">{{ $workspace['name'] }}</a></td>
                                    <td>{{ $workspace['role'] }}</td>
                                    <td>
                                        @if ($workspace['is_active'])
                                            <x-badge variant="success">Active</x-badge>
                                        @else
                                            <x-badge variant="neutral">Inactive</x-badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
