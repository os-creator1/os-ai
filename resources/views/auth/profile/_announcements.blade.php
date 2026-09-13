{{--
    Product updates — what the platform owner published to this person.

    Read-only by design: the platform owner is the publisher (admin
    Announcements). Here there is a list and a way to open each update, and
    nothing else — no Actions menu, no selection, no create, edit, delete or
    bulk management. An update not yet opened is marked "New".
--}}
@extends('layouts.contentLayoutMaster')

@section('title', __('locale.menu.Product updates'))

@section('content')
    <section class="product-updates" data-role="product-updates">
        <x-card :padded="false">
            @if($updates->isEmpty())
                <x-empty-state icon="bell" title="No product updates yet" description="When there is news about the product, it will appear here." />
            @else
                <ul class="list-group list-group-flush" data-role="product-update-list">
                    @foreach($updates as $update)
                        @php $unread = $update->pivot->read_at === null; @endphp
                        <li class="list-group-item" data-role="product-update" data-state="{{ $unread ? 'unread' : 'read' }}">
                            <a href="{{ route('user.account.announcement.view', $update->uid) }}" class="d-flex align-items-center justify-content-between gap-2 text-body">
                                <span class="{{ $unread ? 'fw-bolder' : '' }}">{{ $update->title }}</span>
                                <span class="d-flex align-items-center gap-1 flex-shrink-0">
                                    @if($unread)
                                        <x-badge variant="accent">New</x-badge>
                                    @endif
                                    <time class="text-caption text-muted" datetime="{{ $update->created_at->toIso8601String() }}">{{ $update->created_at->diffForHumans() }}</time>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        <x-pagination :paginator="$updates" />
    </section>
@endsection
