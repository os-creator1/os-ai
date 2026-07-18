<div class="row match-height">
    <div class="col-12">

        <div class="card">
            <div class="card-header">
                <h4 class="card-title"></h4>
            </div>
            <div class="card-content">
                <div class="card-body import-file">
                    <form class="form form-vertical import-customers" method="post">
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
                                                <select name="{{ $header }}" class="form-select select2 width-100">
                                                    @foreach (config('app.customer_db_fields') as $key => $value)
                                                        <option value="{{ $key }}">{{ __('locale.labels.'.$key) }}</option>
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

<script>


  // Basic Select2 select
  $(".select2").each(function() {
    let $this = $(this);
    $this.wrap("<div class=\"position-relative\"></div>");
    $this.select2({
      // the following code is used to disable x-scrollbar when click in select input and
      // take 100% width in responsive also
      width: "100%"
    });
  });


  // Toastr Helper
  const showError = (message) => {
    toastr["error"](message, '{{ __('locale.labels.opps') }}!', {
      closeButton: true,
      positionClass: "toast-top-right",
      progressBar: true,
      newestOnTop: true,
      rtl: isRtl
    });
  };

  // Click handler
  const runBtn = $(".run");

  runBtn.on("click", function() {
    runBtn.hide();

    const formData = $(".import-customers :input:not([name='_token'])").serializeArray();

    const { getData, fieldIds } = formData.reduce((acc, field) => {
      if (field.value !== "--") {
        acc.getData[field.name] = field.value;
        acc.fieldIds.push(field.value);
      }
      return acc;
    }, { getData: {}, fieldIds: [] });

    const requiredFields = {
      email: '{{ __('locale.filezone.email_column_require') }}',
      first_name: '{{ __('locale.filezone.first_name_column_require') }}',
      password: '{{ __('locale.filezone.password_column_require') }}',
      phone: '{{ __('locale.filezone.phone_number_column_require') }}'
    };

    for (const [field, message] of Object.entries(requiredFields)) {
      if (!fieldIds.includes(field)) {
        showError(message);
        runBtn.show();
        return;
      }
    }

    // All validations passed, proceed with AJAX request
    $.ajax({
      url: '{{ route('admin.customers.import-run') }}',
      type: "POST",
      data: {
        _token: '{{ csrf_token() }}',
        filepath: '{{ $filepath }}',
        mapping: getData
      },
      success: function(response) {
        if (response.status === "success") {
          toastr["success"](response.message, '{{ __('locale.labels.success') }}!', {
            closeButton: true,
            positionClass: "toast-top-right",
            progressBar: true,
            newestOnTop: true,
            rtl: isRtl
          });
          setTimeout(() => {
            window.location.href = response.redirectUrl;
          }, 2000);
        } else {
          showError(response.message);
        }
      },
      error: function(jqXHR, textStatus, errorThrown) {
        showError(errorThrown);
      },
      complete: function() {
        feather.replace();
        runBtn.show();
      }
    });
  });

</script>
