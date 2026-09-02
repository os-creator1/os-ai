@extends('layouts/contentLayoutMaster')

@section('title', 'Hot Leads')

@section('content')

<section id="hot-leads">
    <x-card title="Hot Leads">
        @if($leads->isEmpty())
            <x-empty-state title="No hot leads yet." />
        @else
            <x-table :headers="['Phone', 'Website Sent', 'Stage', 'Action']">
                @foreach($leads as $lead)
                    <tr>
                        <td>{{ $lead->to }}</td>
                        <td>{{ $lead->website_sent_at }}</td>
                        <td>{{ $lead->ai_stage }}</td>
                        <td>
                            <form method="POST" action="/admin/hot-leads/mark-called">
                                @csrf
                                <input type="hidden" name="id" value="{{ $lead->id }}">
                                <button class="btn btn-success btn-sm">
                                    Mark as Called
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
</section>

@endsection
