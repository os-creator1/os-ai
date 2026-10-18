<div class="d-flex gap-2 mb-2 align-items-center" data-row>
    <input type="text" name="{{ $fieldName }}[]" class="form-control flex-grow-1" style="min-width:0;" value="{{ $value }}" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_LIST_ITEM_LENGTH }}" placeholder="{{ $placeholder ?? '' }}" aria-label="{{ ($placeholder ?? '') !== '' ? 'Location name' : 'Entry' }}">
    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" data-move-row="up" aria-label="Move up">&uarr;</button>
    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" data-move-row="down" aria-label="Move down">&darr;</button>
    <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" data-remove-row aria-label="Remove"><span class="d-none d-sm-inline">Remove</span><span class="d-sm-none" aria-hidden="true">&times;</span></button>
</div>
