@props(['url'])
{{-- Retained for compatibility: the brand header is rendered by layout.blade.php. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">{!! $slot !!}</a>
</td>
</tr>
