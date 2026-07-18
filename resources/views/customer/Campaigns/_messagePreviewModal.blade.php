<div class="modal fade " id="messagePreview" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exampleModalLabel">{{ __('locale.menu.Overview') }}</h5>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('locale.labels.parts') }}</th>
                                <th scope="col">{{ __('locale.labels.length') }}</th>
                                <th scope="col">{{ __('locale.labels.recipients') }}</th>
                                <th scope="col">{{ __('locale.labels.message') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th id="msgCost"></th>
                                <td id="msgLength"></td>
                                <td id="msgRecepients" data-loading-text="<i data-feather='loader'></i>"></td>
                                <td id="msg"></td>
                            </tr>

                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button id="closeMessagePreview" type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('locale.buttons.close') }}</button>
                <button type="button" class="btn btn-primary" id="finalSend" data-loading-text="<i data-feather='loader'></i> {{ __('locale.buttons.please_wait') }}">{{ __('locale.buttons.proceed') }}</button>
            </div>
        </div>
    </div>
</div>
