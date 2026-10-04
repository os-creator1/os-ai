<div class="d-flex gap-2 mb-2 align-items-center" data-row>
    <input type="text" name="{{ $fieldName }}[]" class="form-control" value="{{ $value }}" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_LIST_ITEM_LENGTH }}" placeholder="{{ $placeholder ?? '' }}">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="up" aria-label="Move up">&uarr;</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="down" aria-label="Move down">&darr;</button>
    <button type="button" class="btn btn-sm btn-outline-danger" data-remove-row aria-label="Remove">Remove</button>
</div>
