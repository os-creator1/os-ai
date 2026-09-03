@extends('layouts/contentLayoutMaster')

@section('title', __('locale.blacklist.add_new_blacklist'))

@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">
                <x-card :title="__('locale.blacklist.add_new_blacklist')">

                            <p>{!!  __('locale.description.blacklist') !!} {{config('app.name')}}</p>

                            <form class="form form-vertical" action="{{ route('customer.blacklists.store') }}" method="post">
                                @csrf

                                <div class="row">

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="number" class="form-label required">{{ __('locale.labels.paste_numbers') }}</label>
                                            <textarea id="number" class="form-control @error('number') is-invalid @enderror" name="number" required autofocus></textarea>
                                            @error('number')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">

                                            <div class="btn-group" role="group">
                                                <input type="radio" class="btn-check" name="delimiter" value="," id="comma" autocomplete="off" checked/>
                                                <label class="btn btn-outline-primary" for="comma">, ({{ __('locale.labels.comma') }})</label>

                                                <input type="radio" class="btn-check" name="delimiter" value=";" id="semicolon" autocomplete="off"/>
                                                <label class="btn btn-outline-primary" for="semicolon">; ({{ __('locale.labels.semicolon') }})</label>

                                                <input type="radio" class="btn-check" name="delimiter" value="|" id="bar" autocomplete="off"/>
                                                <label class="btn btn-outline-primary" for="bar">| ({{ __('locale.labels.bar') }})</label>

                                                <input type="radio" class="btn-check" name="delimiter" value="tab" id="tab" autocomplete="off"/>
                                                <label class="btn btn-outline-primary" for="tab">{{ __('locale.labels.tab') }}</label>

                                                <input type="radio" class="btn-check" name="delimiter" value="new_line" id="new_line" autocomplete="off"/>
                                                <label class="btn btn-outline-primary" for="new_line">{{ __('locale.labels.new_line') }}</label>

                                            </div>

                                            @error('delimiter')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>

                                    </div>
                                    <div class="col-12">
                                        <x-input
                                            name="reason"
                                            :label="__('locale.labels.reason')"
                                            value="{{ old('reason') }}"
                                            :error="$errors->first('reason')"
                                        />
                                    </div>


                                    <div class="col-12">
                                        <x-button type="submit" icon="save" class="me-1 mb-1">{{ __('locale.buttons.save') }}</x-button>
                                        <button type="reset" class="btn btn-outline-warning mb-1"><x-ds-icon name="refresh-cw" /> {{ __('locale.buttons.reset') }}</button>
                                    </div>

                                </div>

                            </form>
                </x-card>
            </div>
        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection

@section('page-script')

    <script>
        $(document).ready(function () {

            let firstInvalid = $('form').find('.is-invalid').eq(0);

            if (firstInvalid.length) {
                $('body, html').stop(true, true).animate({
                    'scrollTop': firstInvalid.offset().top - 200 + 'px'
                }, 200);
            }

        });
    </script>
@endsection
