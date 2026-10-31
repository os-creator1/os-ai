{{--
    Toll-free number selection (state 2): prefix + "More" controls and the existing candidate list /
    purchase summary, or the existing empty states. Rendered server-side after a search and re-fetched
    in place by the page script. Inputs as no-number.blade.php.
--}}
<form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.number.search', [$workspaceUid, $businessUid]) }}" class="pm-results-bar" data-flow-search data-role="toll-free-search-controls">
    @csrf
    <input type="hidden" name="number_type" value="toll_free">
    <h2 class="pm-panel__title">Pick a number</h2>
    <div class="pm-results-bar__controls">
        <label class="visually-hidden" for="toll_free_prefix">Toll-free prefix</label>
        <select id="toll_free_prefix" name="toll_free_prefix" class="form-select form-select-sm @error('toll_free_prefix') is-invalid @enderror" data-flow-autosubmit>
            <option value="">Any prefix</option>
            @foreach(['800', '888', '877', '866', '855', '844', '833'] as $prefix)
                <option value="{{ $prefix }}" @selected(old('toll_free_prefix', $criteria['toll_free_prefix'] ?? '') === $prefix)>{{ $prefix }}</option>
            @endforeach
        </select>
        <x-button type="submit" variant="secondary" size="sm" :disabled="! $available" data-role="find-toll-free-more">More</x-button>
    </div>
    @error('toll_free_prefix')<div class="pm-error w-100">{{ $message }}</div>@enderror
</form>

@if(count($candidateOffers) > 0)
    @include('customer.settings.text-messaging._number-candidate-offers', ['hideHeading' => true])
    <p class="pm-hint mt-2" data-role="number-rotation-note">Want more choices? Press More to see the next available numbers, without repeating those already shown in this session.</p>
@elseif($numberOptionsExhausted ?? false)
    <x-empty-state icon="search" title="No more different numbers" description="The carrier returned no further unseen numbers for these filters. Choose a different toll-free prefix to see other numbers." />
@else
    <x-empty-state icon="search" title="No number found" description="We couldn't find any toll-free numbers with confirmed pricing for that search. Try another prefix or search again later." />
@endif
