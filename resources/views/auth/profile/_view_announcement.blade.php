@extends('layouts.contentLayoutMaster')

@section('title', __('locale.menu.Announcements'))


@section('content')

    <section class="announcements">

        <div class="row">
            <div class="col-12">
                <div class="card">

                    <div class="card-header">
                        <h4 class="card-title">{{ $announcement->title }}</h4>
                    </div>

                    <div class="card-body">
                        <p class="card-text">{!! $announcement->description !!}</p>
                    </div>

                    <div class="card-footer">
                        <p class="card-text"><small class="text-muted">{{ __('locale.labels.created_at') }}
                                : {{ $announcement->created_at->diffForHumans() }}</small></p>
                        @php $isRead = Auth::user()->announcements->find($announcement->id)->pivot->read_at !== null; @endphp

                        @if(!$isRead)
                            <button class="btn btn-success btn-sm mark_read" data-id="{{$announcement->uid}}"><i
                                        data-feather="check"></i> {{ __('locale.labels.mark_as_read') }}</button>
                        @endif

                        <a href="{{ route('user.account.announcement') }}" class="btn btn-primary btn-sm"><i
                                    data-feather="list"></i> {{ __('locale.buttons.back') }}</a>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('page-script')
    <script>
        $(".mark_read").on("click", function (e) {
            e.stopPropagation();
            let id = $(this).data("id");

            $.ajax({
                url: "{{ route('user.account.announcement.mark-as-read') }}",
                type: "POST",
                data: {
                    uid: id,
                    _token: "{{csrf_token()}}"
                },
                success: function (response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        toastr['warning'](response.message, "{{__('locale.labels.attention')}}", {
                            closeButton: true,
                            positionClass: 'toast-top-right',
                            progressBar: true,
                            newestOnTop: true,
                            rtl: isRtl
                        });
                    }
                },

                error: function () {
                    toastr['warning'](error.responseText, "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    });
                }

            });

        });


    </script>
@endsection
