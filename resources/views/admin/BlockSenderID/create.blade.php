@extends('layouts/contentLayoutMaster')
@section('title', __('locale.block_senderid.block_new'))

@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('locale.block_senderid.block_new') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">
                            <form class="form form-vertical" action="{{ route('admin.block-senderid.store') }}"
                                  method="post">
                                @csrf

                                <div class="row">

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="sender_id"
                                                   class="form-label required">{{ __('locale.labels.sender_id') }}</label>
                                            <input type="text" id="sender_id"
                                                   class="form-control @error('sender_id') is-invalid @enderror"
                                                   value="{{ old('sender_id')}}"
                                                   name="sender_id"
                                                   required
                                            >
                                            @error('sender_id')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="reason"
                                                   class="form-label">{{ __('locale.labels.reason') }}</label>
                                            <input type="text" id="reason"
                                                   class="form-control @error('reason') is-invalid @enderror"
                                                   value="{{ old('reason')}}"
                                                   name="reason">
                                            @error('reason')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>


                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary mr-1 mb-1"><i
                                                    data-feather="save"></i> {{ __('locale.buttons.save') }}
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

@section('page-script')

    <script>
      $(document).ready(function() {

        let firstInvalid = $("form").find(".is-invalid").eq(0);

        if (firstInvalid.length) {
          $("body, html").stop(true, true).animate({
            "scrollTop": firstInvalid.offset().top - 200 + "px"
          }, 200);
        }

      });
    </script>
@endsection
