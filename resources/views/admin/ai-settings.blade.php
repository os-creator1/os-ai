@extends('layouts.contentLayoutMaster')

@section('title', 'AI Brain')

@section('content')

<div class="container-fluid py-4">
    <h1 class="mb-3">AI Brain</h1>

    <form method="POST">
    @csrf

    <x-input name="model" label="Model" value="{{ $settings->model }}" />

    <label>System Prompt</label>

    <textarea name="system_prompt" rows="12" class="form-control">{{ $settings->system_prompt }}</textarea>

    <x-button type="submit">Save</x-button>
    </form>
</div>

@endsection
