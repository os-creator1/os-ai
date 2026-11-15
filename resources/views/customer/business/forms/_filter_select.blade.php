{{-- A reference-styled filter: a real <select> (keyboard + screen-reader native) drawn
     like the design's filter button. Submits the surrounding GET form on change; the
     noscript button in the form covers no-JS use. --}}
<label class="mgf-select">
    @include('customer.business.forms._icon', ['n' => $icon])
    <select name="{{ $name }}" aria-label="{{ $label }}" onchange="this.form.submit()" data-role="{{ $role }}">
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected((string) $selected === (string) $value)>{{ $text }}</option>
        @endforeach
    </select>
    @include('customer.business.forms._icon', ['n' => 'down'])
</label>
