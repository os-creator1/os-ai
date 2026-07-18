@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Automations'))

@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-12">
                <div class="row">

                    <div class="col-md-6 col-xl-4 text-center">
                        <a href="{{ route('customer.automations.say.happy.birthday') }}">
                            <div class="card shadow-none bg-transparent border-primary">
                                <div class="card-body">
                                    <h4 class="card-title"><i class="feather-20" data-feather="gift"></i> {{ __('locale.automations.say_happy_birthday') }}</i></h4>
                                    <p class="card-text">{{ __('locale.automations.say_happy_birthday_description') }}</p>
                                </div>
                            </div>
                        </a>
                    </div>


{{--                    <div class="col-md-6 col-xl-4 text-center">--}}
{{--                        <a href="{{ route('customer.automations.say.goodbye') }}">--}}
{{--                            <div class="card shadow-none bg-transparent border-primary">--}}
{{--                                <div class="card-body">--}}
{{--                                    <h4 class="card-title"><i class="feather-20" data-feather="frown"></i> {{ __('locale.automations.say_goodbye_to_subscriber') }}</h4>--}}
{{--                                    <p class="card-text">{{ __('locale.automations.say_goodbye_to_subscriber_description') }}</p>--}}
{{--                                </div>--}}
{{--                            </div>--}}
{{--                        </a>--}}
{{--                    </div>--}}

                </div>
            </div>
        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection
