<!-- Basic Vertical form layout section start -->
<section id="basic-vertical-layouts">
    <div class="row match-height">
        <div class="col-12">

            <div class="card">
                <div class="card-header">
                    <h4 class="card-title"></h4>
                </div>
                <div class="card-content">
                    <div class="card-body import-file">
                        <form class="form form-vertical import-contacts" method="post">
                            @csrf
                            <div class="row">
                                <div class="table-responsive">
                                    <table class="table table-borderless">
                                        <thead>
                                        @foreach ($headers as $key => $header)
                                            <td>{{ $header }}</td>
                                        @endforeach
                                        </thead>
                                        <tbody>
                                        <tr>
                                            @foreach ($headers as $key => $header)
                                                <td>
                                                    <select name="{{ $header }}" class="form-select select2">
                                                        <option value="--">--</option>
                                                        @foreach ($list->getFields()->get() as $field)
                                                            <option value="{{ $field->id }}">{{ $field->label }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                            @endforeach
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12">


                                    <a href="javascript:"
                                       class="btn btn-primary mt-2 mx-2 mb-1 run">
                                        <i data-feather="save"></i> {{__('locale.buttons.import')}}
                                    </a>
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

<script>

    // Basic Select2 select
    $(".select2").each(function () {
        let $this = $(this);
        $this.wrap('<div class="position-relative"></div>');
        $this.select2({
            // the following code is used to disable x-scrollbar when click in select input and
            width: '100%',
        });
    });

    let run = $('.run');
    let phoneFieldId = '{{ $list->getPhoneField()->id }}';

    run.on('click', function () {
        run.hide();

        let formData = $('.import-contacts :input:not([name="_token"])').serializeArray();

        // Use map to get an array of selected values
        let { getData, fieldIds } = formData
            .filter(field => field.value !== '--')
            .reduce((acc, field) => {
                acc.getData[field.name] = field.value;
                acc.fieldIds.push(field.value);
                return acc;
            }, { getData: {}, fieldIds: [] });

        // Use includes directly in the if statement
        if (!fieldIds.includes(phoneFieldId)) {
            toastr['error']('{{__('locale.filezone.phone_number_column_require')}}', '{{ __('locale.labels.opps') }}!', {
                closeButton: true,
                positionClass: 'toast-top-right',
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
            });

            run.show();
        } else {

            $.ajax({
                url: '{{ route('customer.contact.import-run', $list->uid) }}',
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    filepath: '{{ $filepath }}',
                    mapping: getData,

                }
            }).done(function (response) {


                if (response.status === 'success') {
                    toastr['success'](response.message, '{{ __('locale.labels.success') }}!', {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    })

                    setTimeout(function () {
                        window.location.href = response.redirectUrl;
                    }, 2000);


                } else {
                    toastr['error'](response.message, '{{ __('locale.labels.opps') }}!', {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    })
                }
            }).fail(function (jqXHR, textStatus, errorThrown) {
                toastr['error'](errorThrown, '{{ __('locale.labels.opps') }}!', {
                    closeButton: true,
                    positionClass: 'toast-top-right',
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                })
            }).always(function () {
                run.show();
            });
        }

    });

</script>
