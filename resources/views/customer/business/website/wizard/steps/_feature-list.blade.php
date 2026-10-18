{{--
    A package's included features: one per row, add/remove/re-order, no
    fixed count. Nested inside a package row, so its input names carry that
    row's index; `data-name-template` ({i} = the package row's position) is
    re-resolved by the script whenever package rows move.
--}}
@php
    $features = array_values($features ?? []);
    $resolveName = fn ($i) => str_replace('{i}', (string) $i, $nameTemplate);
@endphp
<div class="mb-2" data-repeatable data-nested data-min-rows="0" data-name-template="{{ $nameTemplate }}">
    <label class="form-label">Included features</label>
    <div data-repeatable-rows>
        @foreach ($features as $feature)
            <div class="d-flex gap-2 mb-2 align-items-center" data-row>
                <input type="text" name="{{ $resolveName($index ?? '__INDEX__') }}" class="form-control" value="{{ $feature }}" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_FEATURE_LENGTH }}" placeholder="e.g. Unlimited prints">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="up" aria-label="Move up">&uarr;</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="down" aria-label="Move down">&darr;</button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-remove-row aria-label="Remove">Remove</button>
            </div>
        @endforeach
    </div>
    <template data-row-template>
        <div class="d-flex gap-2 mb-2 align-items-center" data-row>
            <input type="text" name="" class="form-control" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_FEATURE_LENGTH }}" placeholder="e.g. Unlimited prints">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="up" aria-label="Move up">&uarr;</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="down" aria-label="Move down">&darr;</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-remove-row aria-label="Remove">Remove</button>
        </div>
    </template>
    <button type="button" class="btn btn-link px-0" data-add-row>+ Add another feature</button>
</div>
