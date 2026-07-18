<div class="card">
    <div class="card-body">
        <form class="form form-vertical" action="{{ route('user.account.dlt-entity-id') }}"
              method="post">
            @csrf
            <div class="row">
                <div class="col-6">

                    <div class="mb-1">
                        <label class="form-label required"
                               for="dlt_entity_id">{{ __('locale.labels.entity_id') }}</label>
                        <input type="text" id="dlt_entity_id"
                               class="form-control @error('dlt_entity_id') is-invalid @enderror"
                               value="{{ $user->dlt_entity_id }}" name="dlt_entity_id">
                        @error('dlt_entity_id')
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
