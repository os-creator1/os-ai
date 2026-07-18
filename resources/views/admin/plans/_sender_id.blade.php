<div class="card">
    <div class="card-body py-2 my-25">
        <p class="ml-2">{!! __('locale.description.sender_id') !!} {{ config('app.name') }}</p>
        <div class="col-md-6 col-12">
            <div class="form-body">

                <form class="form form-vertical" action="{{ route('admin.plans.settings.sender_id', $plan->uid) }}" method="post">
                    @csrf
                    <div class="row">


                        <div class="col-12">
                            <div class="mb-1">
                                <label for="sender_id" class="form-label">{{ __('locale.menu.Sender ID') }}</label>
                                <input type="text" id="sender_id" class="form-control @error('sender_id') is-invalid @enderror"
                                       @if(isset($options['sender_id'])) value="{{ $options['sender_id'] }}" @endif
                                       name="sender_id">
                                @error('sender_id')
                                <p><small class="text-danger">{{ $message }}</small></p>
                                @enderror
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="mb-1">
                                <label for="sender_id_price" class="form-label">{{ __('locale.plans.price') }}</label>
                                <input type="text" id="sender_id_price" class="form-control @error('sender_id_price') is-invalid @enderror"
                                       @if(isset($options['sender_id_price'])) value="{{ $options['sender_id_price'] ?? 0 }}" @endif
                                       name="sender_id_price">
                                @error('sender_id_price')
                                <p><small class="text-danger">{{ $message }}</small></p>
                                @enderror
                            </div>
                        </div>


                        <div class="col-12">
                            <div class="mb-1">
                                <label for="sender_id_billing_cycle" class="form-label">{{__('locale.plans.billing_cycle')}}</label>
                                <select class="form-select" id="sender_id_billing_cycle" name="sender_id_billing_cycle">
                                    <option value="daily" @if(isset($options['sender_id_billing_cycle']) && $options['sender_id_billing_cycle'] == 'daily') selected @endif> {{__('locale.labels.daily')}}</option>
                                    <option value="monthly" @if(isset($options['sender_id_billing_cycle']) && $options['sender_id_billing_cycle'] == 'monthly') selected @endif>  {{__('locale.labels.monthly')}}</option>
                                    <option value="yearly" @if(isset($options['sender_id_billing_cycle']) && $options['sender_id_billing_cycle'] == 'yearly') selected @endif>  {{__('locale.labels.yearly')}}</option>
                                    <option value="custom" @if(isset($options['sender_id_billing_cycle']) && $options['sender_id_billing_cycle'] == 'custom') selected @endif>  {{__('locale.labels.custom')}}</option>
                                </select>
                            </div>
                            @error('sender_id_billing_cycle')
                            <p><small class="text-danger">{{ $message }}</small></p>
                            @enderror
                        </div>


                        <div class="col-sm-6 col-12 sender-id-show-custom">
                            <div class="mb-1">
                                <label for="sender_id_frequency_amount" class="form-label">{{__('locale.plans.frequency_amount')}}</label>
                                <input type="text" id="sender_id_frequency_amount" class="form-control text-right @error('sender_id_frequency_amount') is-invalid @enderror"
                                       @if(isset($options['sender_id_frequency_amount'])) value="{{ $options['sender_id_frequency_amount'] }}" @endif
                                       name="sender_id_frequency_amount">
                                @error('sender_id_frequency_amount')
                                <p><small class="text-danger">{{ $message }}</small></p>
                                @enderror
                            </div>
                        </div>

                        <div class="col-sm-6 col-12 sender-id-show-custom">
                            <div class="mb-1">
                                <label for="sender_id_frequency_unit" class="form-label">{{__('locale.plans.frequency_unit')}}</label>
                                <select class="form-select" id="sender_id_frequency_unit" name="sender_id_frequency_unit">
                                    <option value="day" @if(isset($options['sender_id_frequency_unit']) && $options['sender_id_frequency_unit'] == 'day' )  selected @endif> {{__('locale.labels.day')}}</option>
                                    <option value="week" @if(isset($options['sender_id_frequency_unit']) && $options['sender_id_frequency_unit'] == 'week' )  selected @endif>  {{__('locale.labels.week')}}</option>
                                    <option value="month" @if(isset($options['sender_id_frequency_unit']) && $options['sender_id_frequency_unit'] == 'month' )  selected @endif>  {{__('locale.labels.month')}}</option>
                                    <option value="year" @if(isset($options['sender_id_frequency_unit']) && $options['sender_id_frequency_unit'] == 'year' )  selected @endif>  {{__('locale.labels.year')}}</option>
                                </select>
                            </div>
                            @error('sender_id_frequency_unit')
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
    </div>
</div>
