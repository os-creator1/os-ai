{{-- The region the Website tab router swaps. Rendered inside the shell for a full page and on its own for ?fragment=1. --}}
<div id="website-content" data-section-key="{{ $tab }}" data-section-title="{{ $tabLabels[$tab] ?? 'Website' }}">
    @switch($tab)
        @case('packages')
            @include('customer.business.website.studio.packages')
            @break

        @case('forms')
            @include('customer.business.website.studio.forms')
            @break

        @case('questionnaires')
            @include('customer.business.website.studio.questionnaires')
            @break

        @case('settings')
            @include('customer.business.website.studio.settings')
            @break

        @default
            @include('customer.business.website.studio.website')
    @endswitch
</div>
