@extends('layouts/contentLayoutMaster')

@section('title', $industry->label() . ' — citation recommendations')

@section('content')
    <section id="admin-citation-niche">
        <div class="row">
            <div class="col-12">
                @if (session('flash_success'))
                    <div class="alert alert-success">{{ session('flash_success') }}</div>
                @endif
                @if (session('flash_error'))
                    <div class="alert alert-danger">{{ session('flash_error') }}</div>
                @endif
                @foreach ($errors->all() as $message)
                    <div class="alert alert-danger">{{ $message }}</div>
                @endforeach
            </div>
            <div class="col-12">
                <x-card :title="$industry->label() . ' — recommended directories'">
                    <p class="text-caption">A recommendation only references a catalog directory: you can set its importance for this niche, its order, short niche guidance and whether it is enabled. Its website, claim link and tracking capability are edited in Citation Directories.</p>

                    <x-table :headers="['Directory', 'Importance', 'Order', 'Guidance', 'Enabled', '']">
                        @forelse ($recommendations as $rec)
                            <tr data-recommendation="{{ $rec->directory->key }}">
                                <td><strong>{{ $rec->directory->name }}</strong>@if (! $rec->directory->is_active) <x-badge variant="neutral">Disabled</x-badge>@endif</td>
                                <td>
                                    <select name="importance" form="rec-{{ $rec->uid }}" class="form-select form-select-sm">
                                        <option value="">Directory default ({{ $rec->directory->importance->label() }})</option>
                                        @foreach ($importances as $importance)
                                            <option value="{{ $importance->value }}" @selected($rec->importance?->value === $importance->value)>{{ $importance->label() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="number" name="sort_order" form="rec-{{ $rec->uid }}" class="form-control form-control-sm" min="0" max="60000" value="{{ $rec->sort_order }}" style="width:5.5rem"></td>
                                <td><textarea name="guidance" form="rec-{{ $rec->uid }}" class="form-control form-control-sm" maxlength="500" rows="2">{{ $rec->guidance }}</textarea></td>
                                <td><input type="checkbox" name="is_enabled" value="1" form="rec-{{ $rec->uid }}" @checked($rec->is_enabled)></td>
                                <td class="text-nowrap">
                                    <form method="POST" action="{{ route('admin.citation-niches.recommendations.update', [$industry->value, $rec->uid]) }}" id="rec-{{ $rec->uid }}" class="d-inline">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.citation-niches.recommendations.destroy', [$industry->value, $rec->uid]) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-empty-state icon="inbox" title="No recommendations for this niche yet." /></td></tr>
                        @endforelse
                    </x-table>
                </x-card>
            </div>

            <div class="col-12 col-lg-8">
                <x-card title="Add a directory">
                    @if ($available->isEmpty())
                        <p class="mb-0">Every catalog directory is already recommended for this niche.</p>
                    @else
                        <form method="POST" action="{{ route('admin.citation-niches.recommendations.store', $industry->value) }}">
                            @csrf
                            <div class="row g-1 mb-1">
                                <div class="col-md-6">
                                    <label class="form-label" for="directory">Directory</label>
                                    <select id="directory" name="directory" class="form-select" required>
                                        @foreach ($available as $directory)
                                            <option value="{{ $directory->uid }}">{{ $directory->name }}@if (! $directory->is_active) (disabled)@endif</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="importance">Importance for this niche</label>
                                    <select id="importance" name="importance" class="form-select">
                                        <option value="">Directory default</option>
                                        @foreach ($importances as $importance)
                                            <option value="{{ $importance->value }}">{{ $importance->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="mb-1">
                                <label class="form-label" for="guidance">Niche guidance (short, optional)</label>
                                <textarea id="guidance" name="guidance" class="form-control" maxlength="500" rows="2"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Add recommendation</button>
                        </form>
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
