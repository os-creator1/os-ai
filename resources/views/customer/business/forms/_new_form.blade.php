{{-- The New form card (design: FormNew.dc.html). Rendered twice from one source:
     as the modal dialog on the Forms list ($dialog = true, opened by
     public/js/forms/new-form-dialog.js) and as the standalone page the
     `forms.create` route still serves when JavaScript is unavailable ($dialog = false).
     Both POST to FormsController::store(), which creates a real DRAFT form from
     FormStarterTemplates and redirects into the existing builder. --}}
@php
    $dialog = $dialog ?? false;
    $scope = [$workspace->uid, $business->uid];
    $cards = \App\Library\Forms\Builder\FormStarterTemplates::cards();
    $chosen = old('starter');
    $chosen = is_string($chosen) && isset($cards[$chosen]) ? $chosen : array_key_first($cards);
    $nameError = $errors->first('name') ?: $errors->first('forms');
@endphp
<section class="mgf-dlg" @if ($dialog) role="dialog" aria-modal="true" aria-labelledby="mgf-nf-title" aria-describedby="mgf-nf-sub" @endif data-role="forms-new-dialog">
    <form method="POST" action="{{ route('customer.workspaces.businesses.forms.store', $scope) }}" data-role="forms-create-form" id="mgf-nf-form" novalidate>
        @csrf
        <div class="mgf-dlg-head">
            <div>
                @if ($dialog)<h2 id="mgf-nf-title">New form</h2>@else<h1 id="mgf-nf-title" style="margin:0;font-size:20px;font-weight:800">New form</h1>@endif
                <span id="mgf-nf-sub">Name it and pick a start. You'll design it next.</span>
            </div>
            @if ($dialog)
                <button type="button" class="mgf-x" data-nf-close aria-label="Close">@include('customer.business.forms._icon', ['n' => 'x'])</button>
            @endif
        </div>
        <div class="mgf-dlg-body">
            <div class="mgf-fl">
                <label for="mgf-nf-name">Form name</label>
                <input type="text" id="mgf-nf-name" name="name" class="mgf-in {{ $nameError ? 'is-invalid' : '' }}" value="{{ old('name') }}" placeholder="e.g. Check availability" maxlength="120" required autocomplete="off"
                       aria-describedby="mgf-nf-err" @if ($nameError) aria-invalid="true" @endif @unless ($dialog) autofocus @endunless>
                <p class="mgf-err" id="mgf-nf-err" role="alert" data-role="forms-create-error" @unless ($nameError) hidden @endunless>{{ $nameError }}</p>
            </div>
            <fieldset class="mgf-tpls-wrap" style="border:0;padding:0;margin:0;min-width:0">
                <legend style="font-size:13.5px;font-weight:600;color:var(--mgf-ink2);margin:0 0 6px;padding:0;width:auto">Start from</legend>
                <div class="mgf-tpls" role="radiogroup" aria-label="Start from">
                    @foreach ($cards as $id => $card)
                        <label class="mgf-tpl {{ $chosen === $id ? 'is-on' : '' }}" data-role="forms-starter-{{ $id }}">
                            <input type="radio" name="starter" value="{{ $id }}" id="starter-{{ $id }}" @checked($chosen === $id)>
                            <span class="mgf-tpl-mk" aria-hidden="true">
                                @foreach ($card['lines'] as $w)<i style="width:{{ $w }}%"></i>@endforeach
                                <i class="b" style="width:40%;height:12px"></i>
                            </span>
                            <b>{{ $card['title'] }}</b>
                            <small>{{ $card['description'] }}</small>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </div>
        <div class="mgf-dlg-foot">
            <span>Starts as a draft until you turn it on.</span>
            <a href="{{ route('customer.workspaces.businesses.forms.index', $scope) }}" class="mgf-btn" data-nf-close style="color:var(--mgf-mute2)">Cancel</a>
            <button type="submit" class="mgf-btn is-primary" style="padding:0 20px" data-nf-submit data-role="forms-create-submit"><span class="mgf-spin" aria-hidden="true"></span><span data-nf-label>Create &amp; open builder</span></button>
        </div>
    </form>
</section>
