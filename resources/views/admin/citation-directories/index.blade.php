@extends('layouts/contentLayoutMaster')

@section('title', 'Citation Directories')

@section('content')
    <section id="admin-citation-directories">
        <div class="row">
            <div class="col-12">
                @if (session('flash_success'))
                    <div class="alert alert-success">{{ session('flash_success') }}</div>
                @endif
                @if (session('flash_error'))
                    <div class="alert alert-danger">{{ session('flash_error') }}</div>
                @endif
            </div>
            <div class="col-12">
                <x-card title="Citation Directories">
                    <x-slot:actions>
                        <x-button :href="route('admin.citation-directories.create')" variant="primary" icon="plus">Add directory</x-button>
                    </x-slot:actions>

                    <p class="text-caption">The platform catalog every Business sees. Core directories appear for everyone; others appear where a niche recommends them. Disabling a directory stops new recommendations but keeps every Business's existing record readable. Automatic checking exists only where a real integration ships; nothing here stores credentials.</p>

                    <x-table :headers="['Directory', 'Core', 'Importance', 'Tracking', 'Claim link', 'Markets', 'Active', '']">
                        @forelse ($directories as $directory)
                            <tr data-directory="{{ $directory->key }}">
                                <td><strong>{{ $directory->name }}</strong><br><code>{{ $directory->key }}</code></td>
                                <td>{{ $directory->is_platform_core ? 'Core' : 'Niche only' }}</td>
                                <td>{{ $directory->importance->label() }}</td>
                                <td>{{ $directory->tracking_mode->label() }}</td>
                                <td>@if ($directory->claim_url)<span class="text-break">{{ $directory->claim_url }}</span>@else<span class="text-muted">None verified</span>@endif</td>
                                <td>{{ $directory->country_scope ?? 'Global' }}</td>
                                <td>
                                    @if ($directory->is_active)
                                        <x-badge variant="success">Active</x-badge>
                                    @else
                                        <x-badge variant="neutral">Disabled</x-badge>
                                    @endif
                                </td>
                                <td class="text-nowrap">
                                    <a href="{{ route('admin.citation-directories.edit', $directory->uid) }}">Edit</a>
                                    <form method="POST" action="{{ route('admin.citation-directories.active', $directory->uid) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="active" value="{{ $directory->is_active ? 0 : 1 }}">
                                        <button type="submit" class="btn btn-link btn-sm p-0 ms-1">{{ $directory->is_active ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><x-empty-state icon="inbox" title="No directories yet." /></td></tr>
                        @endforelse
                    </x-table>
                </x-card>
            </div>
        </div>
    </section>
@endsection
