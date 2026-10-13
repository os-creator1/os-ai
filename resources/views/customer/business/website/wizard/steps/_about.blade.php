{{--
    The About screen: a complete description written from the owner's confirmed
    facts is already in the box. Keep it, edit it, try another wording, or go
    back to the original — and Continue works without typing anything.
--}}
@php
    $state = $defaultsState[$step['key']] ?? ['source' => 'saved', 'url' => ''];
@endphp
<div data-about-box data-default-url="{{ $state['url'] }}" data-variant="0">
    <textarea name="{{ $n('value') }}" rows="6" class="form-control" maxlength="{{ \App\Library\Website\Setup\QuestionnaireAnswerValidator::MAX_TEXTAREA }}" data-about-text readonly @if($step['required']) required @endif>{{ is_string($answer) ? $answer : '' }}</textarea>
    <div class="d-flex flex-wrap gap-2 mt-2">
        <button type="button" class="btn btn-sm btn-primary" data-about-action="keep">Keep this</button>
        <button type="button" class="btn btn-sm btn-outline-primary" data-about-action="edit">Edit</button>
        <button type="button" class="btn btn-sm btn-outline-primary" data-about-action="another">Try another suggestion</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-about-action="restore">Restore default</button>
        <span class="text-caption align-self-center d-none" data-about-status role="status"></span>
    </div>
    <p class="text-caption mt-2 mb-0">Written only from details you confirmed &mdash; nothing about years in business, awards, staff or guarantees. Add those yourself if they&rsquo;re true.</p>
</div>
