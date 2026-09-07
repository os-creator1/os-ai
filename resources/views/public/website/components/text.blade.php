{{-- Website Component Library — text (contract §7.2). Plain escaped text only. --}}
<section class="website-section website-text">
    @if (! empty($data['heading']))
        <h2>{{ $data['heading'] }}</h2>
    @endif
    <div class="website-text-body">
        @foreach (explode("\n", $data['body'] ?? '') as $paragraph)
            @if (trim($paragraph) !== '')
                <p>{{ $paragraph }}</p>
            @endif
        @endforeach
    </div>
</section>
