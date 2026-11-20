@php $steps = [1 => 'Upload', 2 => 'Check fields', 3 => 'Import']; @endphp
<ol class="imp-steps" aria-label="Import progress" data-role="import-steps">
    @foreach($steps as $n => $label)
        <li @class(['done' => $step > $n, 'cur' => $step === $n]) @if($step === $n) aria-current="step" @endif><span class="dot">{{ $step > $n ? '✓' : $n }}</span>{{ $label }}</li>
    @endforeach
</ol>
