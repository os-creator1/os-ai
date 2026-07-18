<div class="card">
    <div class="card-body py-2 my-25">
        <form class="form form-vertical" action="{{ route('customer.developer.webhook') }}" method="post">
            @csrf
            <div class="row">
                <div class="col-12 col-sm-6">

                    <div class="col-12">
                        <div class="mb-1">
                            <label class="form-label required"
                                   for="password">{{ __('locale.developers.webhook_url') }}</label>
                            <input type="url" id="webhook_url"
                                   class="form-control @error('webhook_url') is-invalid @enderror"
                                   value="{{ old('webhook_url',  Auth::user()->webhook_url ?? null) }}"
                                   name="webhook_url">

                        </div>

                        @error('webhook_url')
                        <p><small class="text-danger">{{ $errors->first('webhook_url') }}</small></p>
                        @enderror

                        <p><small class="text-primary"> {{__('locale.developers.add_webhook')}} </small></p>
                    </div>
                </div>

            </div>


            <div class="col-12 d-flex flex-sm-row flex-column mt-1">
                <button type="submit" class="btn btn-primary glow mb-1 mb-sm-0"><i
                            data-feather="save"></i> {{__('locale.buttons.save_changes')}}</button>
            </div>

        </form>
    </div>
</div>
