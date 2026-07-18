@extends('layouts/contentLayoutMaster')

@section('title', __('locale.contacts.update_contact'))
@section('vendor-style')
    {{-- vendor css files --}}
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/pickers/flatpickr/flatpickr.min.css')) }}">

@endsection

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/forms/pickers/form-flat-pickr.css')) }}">
@endsection



@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('locale.contacts.update_contact') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">
                            <form class="form form-vertical"
                                  action="{{ route('customer.contact.update', ['contact' => $contact->uid , 'contact_id' => $subscriber->uid]) }}"
                                  method="post">
                                @csrf
                                <div class="row">

                                    <div class="col-12">

                                        @foreach($contact->getFields as $field)

                                            @if($field->visible)
                                                @if ($field->tag != 'PHONE')
                                                    @if($field->type == 'number')

                                                        <div class="mb-1">
                                                            <label for="{{ $field->tag }}"
                                                                   class="form-label {{ $field->required ? 'required' : '' }}">{{ $field->label }}
                                                            </label>

                                                            <input type="number" id="{{ $field->tag }}"
                                                                   class="form-control @error($field->tag) is-invalid @enderror"
                                                                   value="{{ $values[$field->tag] ?? $field->default_value }}"
                                                                   name="{{ $field->tag }}"
                                                                    {{ $field->required ? 'required' : '' }}>
                                                            @error($field->tag)
                                                            <div class="invalid-feedback">
                                                                {{ $message }}
                                                            </div>
                                                            @enderror
                                                        </div>

                                                    @elseif($field->type == 'textarea')
                                                        <div class="mb-1">
                                                            <label for="{{ $field->tag }}"
                                                                   class="form-label {{ $field->required ? 'required' : '' }}">{{ $field->label }}
                                                            </label>

                                                            <textarea id="{{ $field->tag }}"
                                                                      class="form-control @error($field->tag) is-invalid @enderror"
                                                                      name="{{ $field->tag }}"
                                                                    {{ $field->required ? 'required' : '' }}>{{ $values[$field->tag] ?? $field->default_value }}</textarea>
                                                            @error($field->tag)
                                                            <div class="invalid-feedback">
                                                                {{ $message }}
                                                            </div>
                                                            @enderror
                                                        </div>
                                                    @elseif($field->type == 'date')
                                                        <div class="mb-1">
                                                            <label for="{{ $field->tag }}"
                                                                   class="form-label {{ $field->required ? 'required' : '' }}">{{ $field->label }}
                                                            </label>

                                                            <input type="text" id="{{ $field->tag }}"
                                                                   class="form-control date @error($field->tag) is-invalid @enderror"
                                                                   value="{{ $values[$field->tag] ?? $field->default_value }}"
                                                                   name="{{ $field->tag }}"
                                                                    {{ $field->required ? 'required' : '' }}>
                                                            @error($field->tag)
                                                            <div class="invalid-feedback">
                                                                {{ $message }}
                                                            </div>
                                                            @enderror
                                                        </div>
                                                    @elseif($field->type == 'datetime')
                                                        <div class="mb-1">
                                                            <label for="{{ $field->tag }}"
                                                                   class="form-label {{ $field->required ? 'required' : '' }}">{{ $field->label }}
                                                            </label>

                                                            <input type="text" id="{{ $field->tag }}"
                                                                   class="form-control datetime @error($field->tag) is-invalid @enderror"
                                                                   value="{{ $values[$field->tag] ?? $field->default_value }}"
                                                                   name="{{ $field->tag }}"
                                                                    {{ $field->required ? 'required' : '' }}>
                                                            @error($field->tag)
                                                            <div class="invalid-feedback">
                                                                {{ $message }}
                                                            </div>
                                                            @enderror
                                                        </div>
                                                    @else
                                                        <div class="mb-1">
                                                            <label for="{{ $field->tag }}"
                                                                   class="form-label {{ $field->required ? 'required' : '' }}">{{ $field->label }}
                                                            </label>

                                                            <input type="text" id="{{ $field->tag }}"
                                                                   class="form-control @error($field->tag) is-invalid @enderror"
                                                                   value="{{ $values[$field->tag] ?? $field->default_value }}"
                                                                   name="{{ $field->tag }}"
                                                                    {{ $field->required ? 'required' : '' }}>
                                                            @error($field->tag)
                                                            <div class="invalid-feedback">
                                                                {{ $message }}
                                                            </div>
                                                            @enderror
                                                        </div>
                                                    @endif
                                                @else
                                                    <div class="mb-1">
                                                        <label for="phone"
                                                               class="form-label required">{{ $field->label }}</label>
                                                        <input type="text" id="phone"
                                                               class="form-control @error('PHONE') is-invalid @enderror"
                                                               value="{{ $subscriber->phone ?? $field->default_value  }}"

                                                               name="{{ $field->tag }}" required>
                                                        @error('PHONE')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror

                                                        <p>
                                                            <small class="text-primary @error('PHONE') hidden @enderror"> {!! __('locale.contacts.include_country_code') !!} </small>
                                                        </p>

                                                    </div>
                                                @endif
                                            @endif
                                        @endforeach

                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12">
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


        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection


@section('vendor-script')
    {{-- vendor files --}}
    <script src="{{ asset(mix('vendors/js/pickers/flatpickr/flatpickr.min.js')) }}"></script>
@endsection




@section('page-script')

    <script>
        let firstInvalid = $('form').find('.is-invalid').eq(0);

        if (firstInvalid.length) {
            $('body, html').stop(true, true).animate({
                'scrollTop': firstInvalid.offset().top - 200 + 'px'
            }, 200);
        }

        $(".datetime").flatpickr({
            enableTime: true,
            dateFormat: "Y-m-d H:i"
        });

        $(".date").flatpickr({
            enableTime: false,
            dateFormat: "Y-m-d"
        });


    </script>
@endsection


