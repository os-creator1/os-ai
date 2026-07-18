@extends('layouts/contentLayoutMaster')

@section('title', __('locale.contacts.import_contact'))

@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">

            <div class="col-12">
                <ul class="nav nav-pills mb-2 text-uppercase" role="tablist">

                    <li class="nav-item">
                        <a class="nav-link @if ($tab == 'import_file') active @endif"
                           href="{{ route('customer.contact.import', ['tab' => 'import_file', 'contact' => $contact->uid]) }}">
                            <i data-feather="upload" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{__('locale.labels.import_file')}}</span>
                        </a>
                    </li>

                    <!-- sendByEmail -->
                    <li class="nav-item">
                        <a class="nav-link {{ $tab == 'paste_text' ? 'active':null }}"
                           href="{{ route('customer.contact.paste-text', ['tab' => 'paste_text', 'contact' => $contact->uid]) }}">
                            <i data-feather="file-text" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.labels.paste_text') }}</span>
                        </a>
                    </li>


                </ul>

                <div class="col-md-8 col-12">

                    <div class="card mb-3">
                        <div class="card-header"></div>
                        <div class="card-content">
                            <div class="card-body">

                                <form class="form form-vertical"
                                      action="{{ route('customer.contact.import', $contact->uid) }}" method="post">
                                    @csrf

                                    <div class="mb-1">
                                        <label for="recipients"
                                               class="form-label">{{ __('locale.labels.paste_text') }}</label>
                                        <span class="text-uppercase float-end">{{ __('locale.labels.total_number_of_recipients') }}:
                                                    <span class="number_of_recipients bold text-success me-5">0</span></span>
                                        <textarea class="form-control" id="recipients" name="recipients"
                                                  rows="6"></textarea>
                                        <p>
                                            <small class="text-primary">{!! __('locale.description.paste_text') !!} {!! __('locale.contacts.include_country_code_for_successful_import') !!}</small>
                                        </p>
                                    </div>

                                    <div class="mb-1">
                                        <div class="btn-group btn-group-sm recipients" role="group">
                                            <input type="radio" class="btn-check" name="delimiter" value="," id="comma"
                                                   autocomplete="off" checked/>
                                            <label class="btn btn-outline-primary" for="comma">,
                                                ({{ __('locale.labels.comma') }})</label>

                                            <input type="radio" class="btn-check" name="delimiter" value=";"
                                                   id="semicolon" autocomplete="off"/>
                                            <label class="btn btn-outline-primary" for="semicolon">;
                                                ({{ __('locale.labels.semicolon') }})</label>

                                            <input type="radio" class="btn-check" name="delimiter" value="|" id="bar"
                                                   autocomplete="off"/>
                                            <label class="btn btn-outline-primary" for="bar">|
                                                ({{ __('locale.labels.bar') }})</label>

                                            <input type="radio" class="btn-check" name="delimiter" value="tab" id="tab"
                                                   autocomplete="off"/>
                                            <label class="btn btn-outline-primary"
                                                   for="tab">{{ __('locale.labels.tab') }}</label>

                                            <input type="radio" class="btn-check" name="delimiter" value="new_line"
                                                   id="new_line" autocomplete="off"/>
                                            <label class="btn btn-outline-primary"
                                                   for="new_line">{{ __('locale.labels.new_line') }}</label>

                                        </div>

                                        @error('delimiter')
                                        <p><small class="text-danger">{{ $message }}</small></p>
                                        @enderror
                                    </div>

                                    <div class="row">
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary mt-2 mb-1">
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
        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection

@section('page-script')
    <script>


        let number_of_recipients_ajax = 0,
            number_of_recipients_manual = 0,
            $get_recipients = $('#recipients'),
            firstInvalid = $('form').find('.is-invalid').eq(0);

        if (firstInvalid.length) {
            $('body, html').stop(true, true).animate({
                'scrollTop': firstInvalid.offset().top - 200 + 'px'
            }, 200);
        }

        function get_delimiter() {
            return $('input[name=delimiter]:checked').val();
        }

        function get_recipients_count() {

            let recipients_value = $get_recipients[0].value.trim();

            if (recipients_value) {
                let delimiter = get_delimiter();

                if (delimiter === ';') {
                    number_of_recipients_manual = recipients_value.split(';').length;
                } else if (delimiter === ',') {
                    number_of_recipients_manual = recipients_value.split(',').length;
                } else if (delimiter === '|') {
                    number_of_recipients_manual = recipients_value.split('|').length;
                } else if (delimiter === 'tab') {
                    number_of_recipients_manual = recipients_value.split(' ').length;
                } else if (delimiter === 'new_line') {
                    number_of_recipients_manual = recipients_value.split('\n').length;
                } else {
                    number_of_recipients_manual = 0;
                }
            } else {
                number_of_recipients_manual = 0;
            }
            let total = number_of_recipients_manual + Number(number_of_recipients_ajax);

            $('.number_of_recipients').text(total);
        }

        $get_recipients.keyup(get_recipients_count);


        $("input[name='delimiter']").change(function () {
            get_recipients_count();
        });

    </script>
@endsection
