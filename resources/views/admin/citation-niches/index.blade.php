@extends('layouts/contentLayoutMaster')

@section('title', 'Citation Niches')

@section('content')
    <section id="admin-citation-niches">
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
                <x-card title="Citation niche recommendations">
                    <p class="text-caption">Pick a niche to choose which directories its Businesses are recommended, in what order and at what importance. Changes apply to every existing Business of the niche immediately — nothing is copied into Businesses.</p>
                    <x-table :headers="['Niche', 'Recommended directories', '']">
                        @foreach ($industries as $industry)
                            <tr data-niche="{{ $industry->value }}">
                                <td><strong>{{ $industry->label() }}</strong><br><code>{{ $industry->value }}</code></td>
                                <td>{{ $counts[$industry->value] ?? 0 }}</td>
                                <td><a href="{{ route('admin.citation-niches.show', $industry->value) }}">Manage</a></td>
                            </tr>
                        @endforeach
                    </x-table>
                </x-card>
            </div>
        </div>
    </section>
@endsection
