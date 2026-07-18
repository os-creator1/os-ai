@extends('layouts/contentLayoutMaster')

@section('title', 'Hot Leads')

@section('content')

<section id="hot-leads">
    <div class="card">
        <div class="card-header">
            <h4 class="card-title">Hot Leads</h4>
        </div>

        <div class="card-body">

            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Phone</th>
                            <th>Website Sent</th>
                            <th>Stage</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($leads as $lead)
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
                        @empty
                            <tr>
                                <td colspan="4" class="text-center">
                                    No hot leads yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</section>

@endsection
