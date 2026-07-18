<div class="col-md-6 col-12">
    <div class="form-body">
        <form class="form form-vertical" action="{{ route('admin.settings.gateway-wise-billing') }}" method="post">
            @csrf
            <div class="row">

                <div class="col-12">
                    <div class="mb-1">
                        <label for="gateway_wise_billing" class="form-label required">{{__('locale.permission.gateway_wise_billing')}}</label>
                        <select class="form-select" id="gateway_wise_billing" name="gateway_wise_billing">
                            <option value="1" @if(config('app.gateway_wise_billing') === true) selected @endif>{{__('locale.labels.yes')}}</option>
                            <option value="0" @if(config('app.gateway_wise_billing') === false) selected @endif>{{__('locale.labels.no')}}</option>
                        </select>
                    </div>
                    @error('gateway_wise_billing')
                    <p><small class="text-danger">{{ $message }}</small></p>
                    @enderror
                </div>

                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-primary mb-1">
                        <i data-feather="save"></i> {{__('locale.buttons.save')}}
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>

