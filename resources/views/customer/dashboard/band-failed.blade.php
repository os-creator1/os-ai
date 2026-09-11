{{--
    Customer Experience Slice 4 §12 — a band whose source failed degrades to
    one plain line, locally. No retry button, no poll; the rest of the page
    has already rendered.
--}}
<section class="mb-2" aria-labelledby="dashboard-{{ $band }}-heading" data-band="{{ $band }}" data-band-state="failed">
    <x-card>
        <h2 class="h4 text-section-heading mb-1" id="dashboard-{{ $band }}-heading">{{ $title }}</h2>
        <x-alert variant="warning" icon="info" class="mb-0">This section could not be loaded just now. Everything else on this page is up to date.</x-alert>
    </x-card>
</section>
