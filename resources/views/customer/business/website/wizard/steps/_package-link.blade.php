{{--
    Optional: pair this package with one of the booths above so its card can show that booth's photo. A package with
    no photo of its own and no pairing is a designed text-only card — never an empty frame or an unrelated picture.
--}}
@if (! empty($serviceChoices))
    <div class="mb-2">
        <label class="form-label">Booth shown on this package <span class="text-caption fw-normal">(optional — only if its photo truly represents the package)</span></label>
        <select name="{{ $linkName }}" class="form-select" data-package-link>
            <option value="">No photo — show a text-only card</option>
            @foreach ($serviceChoices as $choiceKey => $choiceName)
                <option value="{{ $choiceKey }}" @selected(($linked ?? null) === (string) $choiceKey)>{{ $choiceName }}</option>
            @endforeach
        </select>
    </div>
@endif
