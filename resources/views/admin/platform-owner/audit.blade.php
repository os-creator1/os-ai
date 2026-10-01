@extends('layouts/contentLayoutMaster')

@section('title', 'Platform Owner audit')

@section('content')
    <section id="admin-platform-owner-audit">
        <div class="row">
            <div class="col-12">
                <p class="text-muted">
                    Actions taken by platform administrators on Workspace plans, account lifecycle and Business status, newest first.
                    System and customer-driven changes are not listed.
                </p>
                @include('admin.platform-owner.partials.audit', ['rows' => $rows->items(), 'actors' => $actors, 'title' => 'Platform Owner actions', 'showWorkspace' => true])

                <div class="d-flex gap-2">
                    @if ($rows->previousPageUrl())
                        <a class="btn btn-outline-secondary btn-sm" href="{{ $rows->previousPageUrl() }}">Newer</a>
                    @endif
                    @if ($rows->hasMorePages())
                        <a class="btn btn-outline-secondary btn-sm" href="{{ $rows->nextPageUrl() }}">Older</a>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection
