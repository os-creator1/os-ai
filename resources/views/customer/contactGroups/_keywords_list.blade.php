{{--
    One keywords view for both directions. $kwKind is 'in' (Join) or 'out' (Leave);
    $kwList is that direction's real keyword collection. The add/delete hooks
    (add_opt_in_keyword, action-delete-optin-keyword, and the opt-out pair) are
    the page's existing handlers.
--}}
@php
    $isIn = $kwKind === 'in';
    $addClass = $isIn ? 'add_opt_in_keyword' : 'add_opt_out_keyword';
    $deleteClass = $isIn ? 'action-delete-optin-keyword' : 'action-delete-optout-keyword';
    $tableClass = $isIn ? 'opt-in-keywords' : 'opt-out-keywords';
@endphp
<div @if ($isIn) id="datatables-keywords-in" @else id="opt-out-keywords" @endif class="mgx-gd-kw">
    <div class="mgx-gd-kwbar">
        <div class="mgx-seg" role="group" aria-label="Keyword type">
            <button type="button" class="@if ($isIn) is-on @endif" data-kw-show="opt_in_keywords">Join keywords</button>
            <button type="button" class="@unless ($isIn) is-on @endunless" data-kw-show="opt_out_keywords">Leave keywords</button>
        </div>
        <span class="mgx-mut mgx-gd-kwhint">{{ $isIn ? 'People text a keyword to your number to join this group.' : 'People text a keyword to your number to leave this group.' }}</span>
        <a href="#" class="mgx-btn is-primary {{ $addClass }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Add keyword</a>
    </div>

    @if ($kwList->isEmpty())
        <section class="mgx-card mgx-gd-empty" data-role="keywords-empty-{{ $kwKind }}">
            <span class="mgx-gd-emptyico" aria-hidden="true">#</span>
            <div class="mgx-gd-emptytext">
                <b>{{ $isIn ? 'No join keywords yet' : 'No leave keywords yet' }}</b>
                <span>{{ $isIn ? 'Someone texts a keyword to your number and is added to ' . $contact->name . '.' : 'Someone texts a keyword to your number and is removed from ' . $contact->name . '.' }}</span>
            </div>
            <a href="#" class="mgx-btn is-primary {{ $addClass }}">Add your first keyword</a>
            <span class="mgx-mut">STOP always works for leaving, even without leave keywords.</span>
        </section>
    @endif

    <div class="mgx-card mgx-gd-card mgx-gd-table" @if ($kwList->isEmpty()) hidden @endif>
        <table class="table {{ $tableClass }}">
            <thead>
            <tr>
                <th>{{__('locale.labels.keyword')}} </th>
                <th>{{__('locale.labels.added_at')}}</th>
                <th>{{__('locale.labels.actions')}}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($kwList as $keywords)
                <tr>
                    <td>{{ $keywords->keyword }}</td>
                    <td>{{ \App\Library\Tool::formatHumanTime($keywords->created_at) }}</td>
                    <td>
                        <span class='{{ $deleteClass }} text-danger cursor-pointer' data-id='{{$keywords->uid}}' data-bs-toggle='tooltip' data-placement='top' title='{{__('locale.buttons.delete')}}'><x-ds-icon name="trash" class="feather-24" /></span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
