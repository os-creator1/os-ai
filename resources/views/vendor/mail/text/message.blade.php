@props(['brand' => null, 'icon' => null])
<x-mail::layout :brand="$brand" :icon="$icon">
{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset
</x-mail::layout>
