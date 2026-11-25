@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach import prospects')
@section('page-heading', 'own')

@section('content')
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'prospects', 'ogInside' => true])

    <section class="card imp" data-role="import-upload">
        <div class="imp-head"><h2>Import prospects</h2>@include('customer.workspaces.prospecting._import-stepper')</div>
        <form method="post" enctype="multipart/form-data" action="{{ route('customer.workspaces.prospecting.prospects.import.upload', $workspaceUid) }}" class="mid" style="padding:20px 24px">
            @csrf
            <div class="fl"><label for="imp-kind">What are you importing?</label>
                <select id="imp-kind" name="kind" class="in">
                    <option value="prospects" @selected(old('kind', 'prospects') === 'prospects')>Prospects to message (e.g. a Google Maps export)</option>
                    <option value="existing_clients" @selected(old('kind') === 'existing_clients')>Existing clients — never prospect these</option>
                </select></div>
            <div class="fl"><label for="imp-file">CSV files <span>(up to 5,000 rows total)</span></label>
                <input type="file" id="imp-file" name="files[]" multiple accept=".csv,.txt,.tsv,text/csv" class="in @error('file') bad @enderror" style="padding-top:10px" required>
                @error('file')<span class="err" role="alert">{{ $message }}</span>@enderror
                @foreach($errors->get('files.*') as $messages)
                    @foreach($messages as $message)<span class="err" role="alert">{{ $message }}</span>@endforeach
                @endforeach
                @error('files')<span class="err" role="alert">{{ $message }}</span>@enderror
                <span class="hint">Select files with the same column names to review them together. We detect the columns for you. Nothing is texted by importing a file.</span></div>
            <div class="foot" style="margin:0 -24px -20px;padding-left:24px;padding-right:24px"><a class="btn" href="{{ route('customer.workspaces.prospecting.prospects.index', $workspaceUid) }}">Cancel</a><button type="submit" class="btn pri">Upload and check</button></div>
        </form>
    </section>
</div>
@endsection
