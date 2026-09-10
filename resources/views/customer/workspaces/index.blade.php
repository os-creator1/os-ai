@extends('layouts/contentLayoutMaster')

@php
    // Customer Experience Slice 1 (Correction 3, contract §5): every
    // customer reaches this list through CustomerContext::accountNoun()/
    // accountsNoun() — "account"/"accounts" for Core/Growth and for the
    // unselected-multi-workspace chooser, "Agency account"/"Agency
    // accounts" only once a proven Agency frame is selected. The raw word
    // "Workspace" is never rendered here. The resolved context is the one
    // App\Http\Middleware\ResolveCustomerContext stores on the request, so
    // the controller's view data keeps its exact key shape.
    $resolvedCustomerContext = request()->attributes->get('customerContext');
    $accountNoun = $resolvedCustomerContext instanceof \App\Library\Navigation\CustomerContext ? $resolvedCustomerContext->accountNoun() : 'account';
    $accountNounPlural = $resolvedCustomerContext instanceof \App\Library\Navigation\CustomerContext ? $resolvedCustomerContext->accountsNoun() : 'accounts';
    $accountTitle = ucfirst($accountNounPlural);
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
