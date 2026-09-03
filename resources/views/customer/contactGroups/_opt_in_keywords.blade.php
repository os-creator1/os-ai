<div id="datatables-basic">

    <div class="mb-3 mt-2">
        <div class="btn-group">
            <a href="#" class="btn btn-success waves-light waves-effect fw-bold add_opt_in_keyword">
                {{__('locale.buttons.add_new')}} <x-ds-icon name="plus-circle" />
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <x-card :padded="false">
                <table class="table opt-in-keywords">
                    <thead>
                    <tr>
                        <th>{{__('locale.labels.keyword')}} </th>
                        <th>{{__('locale.labels.added_at')}}</th>
                        <th>{{__('locale.labels.actions')}}</th>
                    </tr>
                    </thead>

                    <tbody>
                    @foreach ($opt_in_keywords as $keywords)
                        <tr>
                            <td>{{ $keywords->keyword }}</td>
                            <td>{{ \App\Library\Tool::formatHumanTime($keywords->created_at) }}</td>
                            <td>
                                <span class='action-delete-optin-keyword text-danger cursor-pointer' data-id='{{$keywords->uid}}' data-bs-toggle='tooltip' data-placement='top' title='{{__('locale.buttons.delete')}}'><x-ds-icon name="trash" class="feather-24" /></span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </x-card>
        </div>
    </div>

</div>
