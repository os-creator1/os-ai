@extends('layouts/contentLayoutMaster')

@section('title', $directory ? 'Edit directory' : 'Add directory')

@php
    $v = fn (string $key, $default = null) => old($key, $directory ? $directory->{$key} : $default);
@endphp

@section('content')
    <section id="admin-citation-directory-form">
        <div class="row">
            <div class="col-12">
                @if (session('flash_error'))
                    <div class="alert alert-danger">{{ session('flash_error') }}</div>
                @endif
                @foreach ($errors->all() as $message)
                    <div class="alert alert-danger">{{ $message }}</div>
                @endforeach
            </div>
            <div class="col-12 col-lg-8">
                <x-card :title="$directory ? 'Edit ' . $directory->name : 'Add a directory'">
                    <form method="POST" action="{{ $directory ? route('admin.citation-directories.update', $directory->uid) : route('admin.citation-directories.store') }}">
                        @csrf
                        @if ($directory) @method('PUT') @endif

                        <div class="mb-1">
                            <label class="form-label" for="name">Name</label>
                            <input id="name" name="name" class="form-control" maxlength="120" value="{{ $v('name') }}" required>
                        </div>
                        <div class="row g-1 mb-1">
                            <div class="col-md-6">
                                <label class="form-label" for="website_url">Official website</label>
                                <input id="website_url" name="website_url" class="form-control" maxlength="2048" value="{{ $v('website_url') }}" placeholder="https://">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="claim_url">Official claim / manage link</label>
                                <input id="claim_url" name="claim_url" class="form-control" maxlength="2048" value="{{ $v('claim_url') }}" placeholder="https://">
                                <small class="text-muted">Verify it on the directory's own site. No affiliate or tracking parameters.</small>
                            </div>
                        </div>
                        <div class="row g-1 mb-1">
                            <div class="col-md-4">
                                <label class="form-label" for="category">Category</label>
                                <select id="category" name="category" class="form-select">
                                    @foreach ($categories as $category)
                                        <option value="{{ $category }}" @selected($v('category', 'general') === $category)>{{ str_replace('_', ' ', $category) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="importance">Default importance</label>
                                <select id="importance" name="importance" class="form-select">
                                    @foreach ($importances as $importance)
                                        <option value="{{ $importance->value }}" @selected(old('importance', $directory?->importance?->value ?? 'recommended') === $importance->value)>{{ $importance->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="tracking_mode">Tracking</label>
                                <select id="tracking_mode" name="tracking_mode" class="form-select">
                                    @foreach ($modes as $mode)
                                        <option value="{{ $mode->value }}" @selected(old('tracking_mode', $directory?->tracking_mode?->value ?? 'assisted') === $mode->value)>{{ $mode->label() }}</option>
                                    @endforeach
                                </select>
                                <small class="text-muted">Automatic checking is not selectable: it exists only where an integration ships.</small>
                            </div>
                        </div>
                        <div class="row g-1 mb-1">
                            <div class="col-md-4">
                                <label class="form-label" for="country_scope">Market (2-letter code, blank = everywhere)</label>
                                <input id="country_scope" name="country_scope" class="form-control" maxlength="2" value="{{ $v('country_scope') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="icon">Icon name</label>
                                <input id="icon" name="icon" class="form-control" maxlength="40" value="{{ $v('icon') }}" placeholder="map-pin">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="sort_order">Sort order</label>
                                <input id="sort_order" type="number" name="sort_order" class="form-control" min="0" max="60000" value="{{ $v('sort_order', 100) }}">
                            </div>
                        </div>
                        <div class="mb-1">
                            <label class="form-label" for="setup_guidance">Setup guidance (short, original wording)</label>
                            <textarea id="setup_guidance" name="setup_guidance" class="form-control" maxlength="500" rows="2">{{ $v('setup_guidance') }}</textarea>
                        </div>
                        <div class="form-check mb-2">
                            <input id="is_platform_core" type="checkbox" name="is_platform_core" value="1" class="form-check-input" @checked((bool) $v('is_platform_core', false))>
                            <label class="form-check-label" for="is_platform_core">Core directory (shown to every Business)</label>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ $directory ? 'Save directory' : 'Add directory' }}</button>
                        <a href="{{ route('admin.citation-directories.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </x-card>
            </div>
        </div>
    </section>
@endsection
