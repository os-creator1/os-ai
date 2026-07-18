@extends('layouts/contentLayoutMaster')
@if(isset($announcement))
    @section('title', __('locale.announcements.update_announcement'))
@else
    @section('title', __('locale.announcements.send_announcement'))
@endif

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('page-style')

    <style>
        .customized_select2 .select2-selection--multiple {
            border-left: 0;
            border-radius: 0 4px 4px 0;
            min-height: calc(1.5em + 0.75rem + 7px) !important;
        }

    </style>

@endsection


@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">

            <ul class="nav nav-pills mb-2 text-uppercase" role="tablist">

                @can('view announcement')

                    <!-- Announcements -->
                    <li class="nav-item">
                        <a class="nav-link @if ($tab == 'announcements') active @endif"
                           href="{{ route('admin.announcements.index') }}">
                            <i data-feather="tv" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{__('locale.menu.Announcements')}}</span>
                        </a>
                    </li>
                @endcan

                @can('create announcement')
                    <!-- sendByEmail -->
                    <li class="nav-item">
                        <a class="nav-link {{ $tab == 'send_by_email' ? 'active':null }}"
                           href="{{ route('admin.announcements.create', ['tab' => 'send_by_email']) }}">
                            <i data-feather="send" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.announcements.send_announcement') }}</span>
                        </a>
                    </li>


                    <!-- sendBySMS -->
                    <li class="nav-item">
                        <a class="nav-link {{ $tab == 'send_by_sms' ? 'active':null }}"
                           href="{{ route('admin.announcements.create', ['tab' => 'send_by_sms']) }}">
                            <i data-feather="message-square" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.labels.send_by_sms') }}</span>
                        </a>
                    </li>
                @endcan


            </ul>

            <div class="col-md-8 col-12">

                <div class="card mb-3 mt-2">
                    <div class="card-header"></div>
                    <div class="card-content">
                        <div class="card-body">
                            <form class="form form-vertical"
                                  @if(isset($announcement)) action="{{ route('admin.announcements.update',  $announcement->uid) }}"
                                  @else action="{{ route('admin.announcements.store') }}" @endif method="post">
                                @if(isset($announcement))
                                    {{ method_field('PUT') }}
                                @endif
                                @csrf


                                <div class="row">

                                    @if(!isset($announcement))

                                        <div class="col-12">
                                            <p class="text-uppercase">{{ __('locale.labels.select_customer') }}</p>
                                        </div>


                                        <div class="col-md-6 col-12">
                                            <div class="mb-1">

                                                <div class="input-group">
                                                    <div class="input-group-text">
                                                        <div class="form-check">
                                                            <input type="radio" class="form-check-input select_all"
                                                                   name="customer" checked value="0"
                                                                   id="select_all" />
                                                            <label class="form-check-label"
                                                                   for="select_all">{{ __('locale.labels.all') }}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>


                                        <div class="col-md-6 col-12 customized_select2">

                                            <div class="mb-1">
                                                <div class="input-group">
                                                    <div class="input-group-text">
                                                        <div class="form-check">
                                                            <input type="radio" class="form-check-input select_multiple"
                                                                   name="customer" value="select_multiple"
                                                                   id="select_multiple" />
                                                            <label class="form-check-label"
                                                                   for="select_multiple"></label>
                                                        </div>
                                                    </div>

                                                    <select class="form-select users_id" disabled name="users_id[]"
                                                            multiple
                                                            id="user_id">
                                                        @foreach($customers as $customer)
                                                            <option value="{{$customer->id}}">{{$customer->displayName()}}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('user_id')
                                                    <p><small class="text-danger">{{ $message }}</small></p>
                                                    @enderror

                                                </div>
                                            </div>

                                        </div>


                                        @if($tab == 'send_by_sms')

                                            @if($sendingServers->count() > 0)
                                                <div class="col-12">
                                                    <div class="mb-1">
                                                        <label for="sending_server"
                                                               class="form-label required">{{ __('locale.labels.sending_server') }}</label>
                                                        <select class="select2 form-select" name="sending_server"
                                                                id="sending_server">
                                                            @foreach($sendingServers as $server)

                                                                <option value="{{$server->id}}"> {{ $server->name }}</option>

                                                            @endforeach
                                                        </select>

                                                        @error('sending_server')
                                                        <p><small class="text-danger">{{ $message }}</small></p>
                                                        @enderror
                                                    </div>
                                                </div>

                                            @endif

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="sender_id"
                                                           class="form-label">{{__('locale.labels.sender_id')}}</label>
                                                    <input type="text" id="sender_id"
                                                           class="form-control @error('sender_id') is-invalid @enderror"
                                                           name="sender_id"
                                                           value="{{ old('sender_id',  $announcement->sender_id ?? null) }}"
                                                           placeholder="{{__('locale.labels.sender_id')}}">
                                                    @error('sender_id')
                                                    <p><small class="text-danger">{{ $message }}</small></p>
                                                    @enderror
                                                </div>
                                            </div>

                                        @endif
                                    @endif

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="title"
                                                   class="form-label required">{{ __('locale.labels.title') }}</label>
                                            <input type="text" id="title"
                                                   class="form-control @error('title') is-invalid @enderror"
                                                   value="{{ old('title',  $announcement->title ?? null) }}"
                                                   name="title"
                                                   required placeholder="{{__('locale.labels.required')}}">
                                            @error('title')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                            @if($tab == 'send_by_sms')
                                                <p>
                                                    <small class="text-primary text-uppercase">{{ __('locale.announcements.title_count_as_sms') }}</small>
                                                </p>
                                            @endif
                                        </div>
                                    </div>


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="description"
                                                   class="form-label required">{{ __('locale.labels.description') }}</label>
                                            <textarea id="description"
                                                      class="form-control @error('description') is-invalid @enderror"
                                                      name="description"
                                                      required>{{$announcement->description ?? null}}</textarea>
                                            @error('description')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>


                                    @if(!isset($announcement) && $tab == 'send_by_email')
                                        <div class="col-12">
                                            <div class="mb-1">
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" id="send_email"
                                                           value="yes" name="send_email">
                                                    <label class="form-check-label text-uppercase text-primary"
                                                           for="send_email">{{ __('locale.announcements.send_as_email') }}</label>
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    <div class="col-12 mt-1">
                                        <input type="hidden" name="send_by" value="{{$tab}}">
                                        <button type="submit" class="btn btn-primary"><i
                                                    data-feather="save"></i> {{ isset($announcement) ? __('locale.buttons.update') : __('locale.buttons.send') }}
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
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection



@section('page-script')

    <script>
      $(document).ready(function() {


        $(".select_all").on("click", function() {
          $("#select_multiple").prop("disabled", !this.checked);
          $("#user_id").prop("disabled", this.checked);
        });

        $(".select_multiple").on("click", function() {
          $("#select_all").prop("disabled", !this.checked);
          $("#user_id").prop("disabled", !this.checked);
        });

        // Basic Select2 select
        $(".select2").each(function() {
          let $this = $(this);
          $this.wrap("<div class=\"position-relative\"></div>");
          $this.select2({
            // the following code is used to disable x-scrollbar when click in select input and
            // take 100% width in responsive also
            dropdownAutoWidth: true,
            width: "100%",
            autofocus: true,
            dropdownParent: $this.parent()
          });
        });

        // Basic Select2 select
        $(".users_id").each(function() {
          let $this = $(this);
          $this.wrap("<div class=\"position-relative\"></div>");
          $this.select2({
            // the following code is used to disable x-scrollbar when click in select input and
            // take 100% width in responsive also
            dropdownAutoWidth: true,
            width: "100%",
            placeholder: "{{__('locale.labels.select_one_or_multiple')}}",
            autofocus: true,
            dropdownParent: $this.parent()
          });
        });

        let firstInvalid = $("form").find(".is-invalid").eq(0);

        if (firstInvalid.length) {
          $("body, html").stop(true, true).animate({
            "scrollTop": firstInvalid.offset().top - 200 + "px"
          }, 200);
        }

      });
    </script>
@endsection
