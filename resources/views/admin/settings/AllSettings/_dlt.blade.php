<div class="col-md-6 col-12">
    <div class="form-body">
        <form class="form form-vertical" action="{{ route('admin.settings.dlt') }}" method="post">
            @csrf
            <div class="row">

                <div class="col-12">
                    <div class="mb-1">
                        <label for="trai_dlt" class="form-label required">{{__('locale.labels.trai_dlt')}}</label>
                        <select class="form-select" id="trai_dlt" name="trai_dlt">
                            <option value="1" @if(config('app.trai_dlt') === true) selected @endif>{{__('locale.labels.yes')}}</option>
                            <option value="0" @if(config('app.trai_dlt') === false) selected @endif>{{__('locale.labels.no')}}</option>
                        </select>
                    </div>
                    @error('trai_dlt')
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

