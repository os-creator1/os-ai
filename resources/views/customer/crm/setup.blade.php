@extends('layouts/contentLayoutMaster')

{{--
    CRM Opportunities, before the Business has a pipeline. Setting one up copies
    the standard template (BusinessTemplateRegistry::GENERIC) into rows the
    Business owns; nothing is created by merely opening this page.
--}}

@section('title', 'Opportunities')

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $businessUid = request()->route('businessUid');
    @endphp

    <section id="crm-setup" data-role="crm-setup">
        <x-card>
            <x-empty-state icon="kanban" title="Track your opportunities in a pipeline"
                           description="Start with a standard sales pipeline — New inquiry, Qualified, Proposal sent, Negotiating. You can rename, reorder and add stages afterwards." />

            @can(\App\Http\Controllers\Customer\Business\CrmOpportunitiesController::MANAGE_PERMISSION)
                <form method="POST" action="{{ route('customer.workspaces.businesses.crm.setup', [$workspaceUid, $businessUid]) }}" class="text-center mt-1">
                    @csrf
                    <x-button type="submit" variant="primary">Set up pipeline</x-button>
                </form>
            @endcan
        </x-card>
    </section>
@endsection
