{{--
    One product update, read-only. Opening it has already marked it read.

    The body is the platform owner's own published rich text, rendered as
    they wrote it; nothing on this page can change the update.
--}}
@extends('layouts.contentLayoutMaster')

@section('title', __('locale.menu.Product updates'))

@section('content')
    <section class="product-updates" data-role="product-update-detail">
        <x-card :title="$announcement->title">
            <div class="card-text">{!! $announcement->description !!}</div>

            <x-slot:footer>
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <time class="text-caption text-muted" datetime="{{ $announcement->created_at->toIso8601String() }}">{{ $announcement->created_at->diffForHumans() }}</time>
                    <x-button variant="secondary" size="sm" :href="route('user.account.announcement')">All product updates</x-button>
                </div>
            </x-slot:footer>
        </x-card>
    </section>
@endsection
