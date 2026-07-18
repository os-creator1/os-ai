<div class="card">
    <div class="card-body">
        <form class="form form-vertical" action="{{ route('admin.customers.dlt-telemarketer-id', $customer->uid) }}"
              method="post">
            @csrf
            <div class="row">
                <div class="col-6">

                    <div class="mb-1">
                        <label class="form-label required"
                               for="dlt_telemarketer_id">{{ __('locale.labels.telemarketer_id') }}</label>
                        <input type="text" id="dlt_telemarketer_id"
                               class="form-control @error('dlt_telemarketer_id') is-invalid @enderror"
                               value="{{ $customer->dlt_telemarketer_id }}" name="dlt_telemarketer_id">
                        @error('dlt_telemarketer_id')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                        @enderror
                    </div>


                    <div class="col-12 mt-1">
                        <button type="submit" class="btn btn-primary mt-1 me-1"><i
                                    data-feather="save"></i> {{__('locale.buttons.save')}}</button>
                    </div>

                </div>
            </div>

        </form>
    </div>
</div>
