@extends('layouts/contentLayoutMaster')

@section('title', __('locale.contacts.import_contact'))


@section('content')

    <section class="import-contacts">

        <div class="row">
            <div class="col-12">

                <ul class="nav nav-pills mb-2 text-uppercase" role="tablist">

                    <li class="nav-item">
                        <a class="nav-link @if ($tab == 'import_file') active @endif"
                           href="{{ route('customer.contact.import', ['tab' => 'import_file', 'contact' => $contact->uid]) }}">
                            <i data-feather="upload" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{__('locale.labels.import_file')}}</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link {{ $tab == 'paste_text' ? 'active':null }}"
                           href="{{ route('customer.contact.paste-text', ['tab' => 'paste_text', 'contact' => $contact->uid]) }}">
                            <i data-feather="file-text" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.labels.paste_text') }}</span>
                        </a>
                    </li>


                </ul>

                <div class="tab-content">

                    <div class="tab-pane  @if (old('tab') == 'import_file' || old('tab') == null) active @endif"
                         id="import-file" aria-labelledby="import-file-tab" role="tabpanel">

                        @include('customer.Contacts.import_file')
                    </div>


                </div>
            </div>
        </div>
    </section>

@endsection
