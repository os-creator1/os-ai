@php use App\Library\Tool; @endphp
@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Customers'))

@section('vendor-style')
    {{-- vendor css files --}}
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/dataTables.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/responsive.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/buttons.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">

@endsection

@section('content')

    @if ($currentJob)
        @php
            $progress = Auth::user()->getProgress($currentJob);
        @endphp

        <div class="row match-height" id="import-status-container">
            {{-- Progress Bar (Full Width) --}}
            @if ($progress['status'] === 'running')
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="progress progress-bar-primary h-50 my-2">
                                <div
                                        class="progress-bar progress-bar-striped progress-bar-animated"
                                        role="progressbar"
                                        aria-valuenow="{{ $progress['percentage'] }}"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        style="width: {{ $progress['percentage'] }}%"
                                >
                                    {{ $progress['percentage'] }}%
                                </div>
                            </div>
                            <p>{{ $progress['message'] }}</p>
                        </div>
                    </div>
                </div>
            @elseif ($progress['status'] === 'done')
                {{-- Summary Card (Dismissible) --}}
                <div class="col-lg-4 col-md-6 col-12" id="import-done-alert">
                    <div class="card card-transaction">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0">{{ __('locale.labels.import_summary') }}</h4>
                            <div class="dropdown">
                                <i data-feather="more-vertical"
                                   class="font-medium-3 cursor-pointer"
                                   id="importOptions" data-bs-toggle="dropdown"
                                   aria-expanded="false"></i>
                                <ul class="dropdown-menu dropdown-menu-end"
                                    aria-labelledby="importOptions">
                                    <li>
                                        <a class="dropdown-item text-danger" href="#"
                                           onclick="dismissImportAlert()">
                                            <i data-feather="x" class="me-50"></i> {{ __('locale.labels.dismiss') }}
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="transaction-item">
                                <div class="d-flex flex-row">
                                    <div class="avatar bg-light-primary rounded">
                                        <div class="avatar-content">
                                            <i data-feather="users" class="avatar-icon font-medium-3"></i>
                                        </div>
                                    </div>
                                    <div class="transaction-info">
                                        <h6 class="transaction-title">{{ __('locale.buttons.import') }}</h6>
                                        <small>{{ __('locale.labels.total') }}</small>
                                    </div>
                                </div>
                                <div class="fw-bolder text-primary">{{ $progress['total'] }}</div>
                            </div>

                            <div class="transaction-item">
                                <div class="d-flex flex-row">
                                    <div class="avatar bg-light-success rounded">
                                        <div class="avatar-content">
                                            <i data-feather="check" class="avatar-icon font-medium-3"></i>
                                        </div>
                                    </div>
                                    <div class="transaction-info">
                                        <h6 class="transaction-title">{{ __('locale.buttons.proceed') }}</h6>
                                        <small>{{ __('locale.labels.total') }}</small>
                                    </div>
                                </div>
                                <div class="fw-bolder text-success">{{ $progress['processed'] }}</div>
                            </div>

                            <div class="transaction-item">
                                <div class="d-flex flex-row">
                                    <div class="avatar bg-light-danger rounded">
                                        <div class="avatar-content">
                                            <i data-feather="x-circle" class="avatar-icon font-medium-3"></i>
                                        </div>
                                    </div>
                                    <div class="transaction-info">
                                        <h6 class="transaction-title">{{ __('locale.labels.failed') }}</h6>
                                        <small>{{ __('locale.labels.total') }}</small>
                                    </div>
                                </div>
                                <div class="fw-bolder text-danger">{{ $progress['failed'] }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif



    <div class="row match-height">

        <div class="col-lg-4 col-sm-6 col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2 class="fw-bolder mb-0">
                            <sup>{{ $customer_stats->active_customers }}</sup> / {{ $customer_stats->total_customers }}
                        </h2>
                        <p class="card-text">{{ __('locale.labels.active_customers') }}</p>
                    </div>

                    <div>
                        <i class="font-large-3 text-success" data-feather="user-check"></i>
                    </div>
                </div>
            </div>
        </div>


        <div class="col-lg-4 col-sm-6 col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2 class="fw-bolder mb-0">
                            <sup>{{ $customer_stats->inactive_customers }}</sup>
                            / {{ $customer_stats->total_customers }}
                        </h2>
                        <p class="card-text">{{ __('locale.labels.inactive_customers') }}</p>
                    </div>
                    <div>
                        <i class="font-large-3 text-danger" data-feather="user-x"></i>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-lg-4 col-sm-6 col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2 class="fw-bolder mb-0">{{ Tool::number_with_delimiter($customer_stats->total_user_balances) }}</h2>
                        <p class="card-text">{{ __('locale.labels.total_user_balances') }}</p>
                    </div>
                    <div>
                        <i class="font-large-3 text-primary" data-feather="credit-card"></i>
                    </div>

                </div>
            </div>
        </div>

    </div>

    <!-- Basic table -->
    <section id="datatables-basic">
        <div class="mb-3 mt-2">
            @can('view customer')
                <div class="btn-group">
                    <button
                            class="btn btn-primary fw-bold dropdown-toggle"
                            type="button"
                            id="bulk_actions"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                    >
                        {{ __('locale.labels.actions') }}
                    </button>
                    <div class="dropdown-menu" aria-labelledby="bulk_actions">
                        <a class="dropdown-item bulk-enable" href="#"><i
                                    data-feather="check"></i> {{ __('locale.datatables.bulk_enable') }}</a>
                        <a class="dropdown-item bulk-disable" href="#"><i
                                    data-feather="stop-circle"></i> {{ __('locale.datatables.bulk_disable') }}</a>
                        <a class="dropdown-item bulk-delete" href="#"><i
                                    data-feather="trash"></i> {{ __('locale.datatables.bulk_delete') }}</a>
                    </div>
                </div>
            @endcan

            @can('create customer')
                <div class="btn-group">
                    <a href="{{route('admin.customers.create')}}"
                       class="btn btn-success waves-light waves-effect fw-bold mx-1"> {{__('locale.buttons.add_new')}}
                        <i data-feather="plus-circle"></i></a>
                </div>

                <div class="btn-group">
                    <a href="{{route('admin.customers.import')}}"
                       class="btn btn-secondary waves-light waves-effect fw-bold"> {{__('locale.buttons.import')}} <i
                                data-feather="upload"></i></a>
                </div>
            @endcan

            @can('view customer')
                <div class="btn-group">
                    <a href="{{route('admin.customers.export')}}"
                       class="btn btn-info waves-light waves-effect fw-bold mx-1"> {{__('locale.buttons.export')}} <i
                                data-feather="download"></i></a>
                </div>
            @endcan

        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <table class="table datatables-basic">
                        <thead>
                        <tr>
                            <th></th>
                            <th></th>
                            <th>{{ __('locale.labels.id') }}</th>
                            <th>{{__('locale.labels.name')}} </th>
                            <th>{{__('locale.labels.parent')}} </th>
                            <th>{{__('locale.labels.current_plan')}}</th>
                            <th>{{__('locale.labels.sms')}} {{__('locale.labels.units')}}</th>
                            <th>{{__('locale.labels.status')}}</th>
                            <th>{{__('locale.labels.actions')}}</th>
                        </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </section>
    <!--/ Basic table -->

@endsection


@section('vendor-script')
    {{-- vendor files --}}
    <script src="{{ asset(mix('vendors/js/tables/datatable/jquery.dataTables.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/dataTables.bootstrap5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/dataTables.responsive.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/responsive.bootstrap5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/datatables.checkboxes.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/datatables.buttons.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/buttons.html5.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/tables/datatable/dataTables.rowGroup.min.js')) }}"></script>

    <script src="{{ asset(mix('vendors/js/extensions/sweetalert2.all.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/extensions/polyfill.min.js')) }}"></script>

@endsection
@section('page-script')

    @if ($currentJob && isset($progress) && $progress['status'] === 'running')
        <script>
          localStorage.removeItem("importDismissed");
        </script>
    @endif

    {{-- Page js files --}}
    <script>
      $(document).ready(function() {
        "use strict";

        //show response message
        function showResponseMessage(data) {

          if (data.status === "success") {
            toastr["success"](data.message, '{{__('locale.labels.success')}}!!', {
              closeButton: true,
              positionClass: "toast-top-right",
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });
            dataListView.draw(false);
          } else if (data.status === "error") {
            toastr["error"](data.message, '{{ __('locale.labels.opps') }}!', {
              closeButton: true,
              positionClass: "toast-top-right",
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });
            dataListView.draw(false);
          } else {
            toastr["warning"]("{{__('locale.exceptions.something_went_wrong')}}", '{{ __('locale.labels.warning') }}!', {
              closeButton: true,
              positionClass: "toast-top-right",
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });
          }


          // Fix: Replace icons after redraw
          setTimeout(() => {
            feather.replace();
          }, 100); // slight delay to ensure DOM update completes
        }

        // init table dom
        let Table = $("table");

        // init list view datatable
        let dataListView = $(".datatables-basic").DataTable({

          "processing": true,
          "serverSide": true,
          "ajax": {
            "url": "{{ route('admin.customers.search') }}",
            "dataType": "json",
            "type": "POST",
            "data": { _token: "{{csrf_token()}}" }
          },
          "columns": [
            { "data": "responsive_id", orderable: false, searchable: false },
            { "data": "uid" },
            { "data": "uid" },
            { "data": "name" },
            { "data": "parent", orderable: false, searchable: false },
            { "data": "subscription", orderable: false, searchable: false },
            { "data": "sms_unit", orderable: false, searchable: false },
            { "data": "status", orderable: false, searchable: false },
            { "data": "action", orderable: false, searchable: false }
          ],

          searchDelay: 1500,
          columnDefs: [
            {
              // For Responsive
              className: "control",
              orderable: false,
              responsivePriority: 2,
              targets: 0
            },
            {
              // For Checkboxes
              targets: 1,
              orderable: false,
              responsivePriority: 3,
              render: function(data) {
                return (
                  "<div class=\"form-check\"> <input class=\"form-check-input dt-checkboxes\" type=\"checkbox\" value=\"\" id=\"" +
                  data +
                  "\" /><label class=\"form-check-label\" for=\"" +
                  data +
                  "\"></label></div>"
                );
              },
              checkboxes: {
                selectAllRender:
                  "<div class=\"form-check\"> <input class=\"form-check-input\" type=\"checkbox\" value=\"\" id=\"checkboxSelectAll\" /><label class=\"form-check-label\" for=\"checkboxSelectAll\"></label></div>",
                selectRow: true
              }
            },
            {
              targets: 2,
              visible: false
            },

            {
              targets: 3,
              responsivePriority: 1,
              render: function(data, type, full) {
                return renderAvatarCard({
                  avatar: full["avatar"],
                  name: full["name"],
                  email: full["email"],
                  created_at: full["created_at"],
                  profileUrl: full["show"],
                  status: full["status"]
                });
              }
            },
            {
              targets: 4,
              render: function(data, type, full) {
                // If no parent URL, return empty (no avatar or link)
                if (!full["parent"] || full["parent"] === "--") {
                  return "<span>--</span>";
                }

                return renderAvatarCard({
                  avatar: full["parent_avatar"],
                  name: full["parent_name"],
                  email: full["parent_email"],
                  created_at: full["parent_created_at"],
                  profileUrl: full["parent"],
                  status: full["status"]
                });
              }
            },

            {
              // Actions
              targets: -1,
              title: '{{ __('locale.labels.actions') }}',
              orderable: false,
              render: function(data, type, full) {
                let $super_user = "";

                if (full["super_user"] === false) {
                  $super_user = "<span class=\"action-delete text-danger pe-1 cursor-pointer\" data-bs-toggle=\"tooltip\" data-bs-placement=\"top\" title=" + full["delete_label"] + " data-id=" + full["delete"] + ">" +
                    feather.icons["trash"].toSvg({ class: "font-medium-4" }) +
                    "</span>";
                }
                return (
                  $super_user +

                  "<a href=\"" + full["show"] + "\" class=\"text-primary pe-1\" data-bs-toggle=\"tooltip\" data-bs-placement=\"top\" title=" + full["show_label"] + ">" +
                  feather.icons["edit"].toSvg({ class: "font-medium-4" }) +
                  "</a>" +
                  "<a href=\"" + full["assign_plan"] + "\" class=\"text-info pe-1\" data-bs-toggle=\"tooltip\" data-bs-placement=\"top\" title=\"" + full["assign_plan_label"] + "\">" +
                  feather.icons["shopping-cart"].toSvg({ class: "font-medium-4" }) +
                  "</a>" +
                  "<a href=\"" + full["login_as"] + "\" class=\"text-success\" data-bs-toggle=\"tooltip\" data-bs-placement=\"top\" title=\"" + full["login_as_label"] + "\">" +
                  feather.icons["log-in"].toSvg({ class: "font-medium-4" }) +
                  "</a>"
                );
              }
            }
          ],
          dom: "<\"d-flex justify-content-between align-items-center mx-0 row\"<\"col-sm-12 col-md-6\"l><\"col-sm-12 col-md-6\"f>>t<\"d-flex justify-content-between mx-0 row\"<\"col-sm-12 col-md-6\"i><\"col-sm-12 col-md-6\"p>>",

          language: {
            paginate: {
              // remove previous & next text from pagination
              previous: "&nbsp;",
              next: "&nbsp;"
            },
            sLengthMenu: "_MENU_",
            sZeroRecords: "{{ __('locale.datatables.no_results') }}",
            sSearch: "{{ __('locale.datatables.search') }}",
            sProcessing: "{{ __('locale.datatables.processing') }}",
            sInfo: "{{ __('locale.datatables.showing_entries', ['start' => '_START_', 'end' => '_END_', 'total' => '_TOTAL_']) }}"
          },
          responsive: {
            details: {
              display: $.fn.dataTable.Responsive.display.modal({
                header: function(row) {
                  let data = row.data();
                  return "Details of " + data["name"];
                }
              }),
              type: "column",
              renderer: function(api, rowIdx, columns) {
                let data = $.map(columns, function(col) {
                  return col.title !== "" // ? Do not show row in modal popup if title is blank (for check box)
                    ? "<tr data-dt-row=\"" +
                    col.rowIdx +
                    "\" data-dt-column=\"" +
                    col.columnIndex +
                    "\">" +
                    "<td>" +
                    col.title +
                    ":" +
                    "</td> " +
                    "<td>" +
                    col.data +
                    "</td>" +
                    "</tr>"
                    : "";
                }).join("");

                return data ? $("<table class=\"table\"/>").append("<tbody>" + data + "</tbody>") : false;
              }
            }
          },
          aLengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
          select: {
            style: "multi"
          },
          order: [[2, "desc"]],
          displayLength: 10
        });


        // Reusable avatar rendering function
        function renderAvatarCard({ avatar, name, email, created_at, profileUrl, status }) {
          let output, initials = "", state = "primary";

          const states = ["success", "danger", "warning", "info", "dark", "primary", "secondary"];

          if (avatar && avatar !== "avatar.jpg") {
            // Avatar image exists
            output = `<img src="${avatar}" alt="Avatar" width="32" height="32">`;
          } else {
            // Use initials badge
            const nameInitials = (name.match(/\b\w/g) || []);
            initials = ((nameInitials.shift() || "") + (nameInitials.pop() || "")).toUpperCase();
            state = states[status] || "primary";
            output = `<span class="avatar-content">${initials}</span>`;
          }

          const colorClass = (!avatar || avatar === "avatar.jpg") ? ` bg-light-${state} ` : "";

          return `
    <a href="${profileUrl}" class="text-primary text-decoration-none">
      <div class="d-flex justify-content-left align-items-center">
        <div class="avatar ${colorClass} me-1">
          ${output}
        </div>
        <div class="d-flex flex-column">
          <span class="emp_name text-truncate fw-bold">${name}</span>
          <small class="emp_post text-truncate text-muted">${email}</small>
          <small class="emp_post text-truncate text-muted">${created_at}</small>
        </div>
      </div>
    </a>`;
        }

// Column renderers

        Table.delegate(".get_status", "click", function() {
          let customer = $(this).data("id");
          $.ajax({
            url: "{{ url(config('app.admin_path').'/customers')}}" + "/" + customer + "/active",
            type: "POST",
            data: {
              _token: "{{csrf_token()}}"
            },
            success: function(data) {
              showResponseMessage(data);
            }
          });
        });

        // On Delete
        Table.delegate(".action-delete", "click", function(e) {
          e.stopPropagation();
          let id = $(this).data("id");
          Swal.fire({
            title: "{{ __('locale.labels.are_you_sure') }}",
            text: "{{ __('locale.labels.able_to_revert') }}",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "{{ __('locale.labels.delete_it') }}",
            customClass: {
              confirmButton: "btn btn-primary",
              cancelButton: "btn btn-outline-danger ms-1"
            },
            buttonsStyling: false
          }).then(function(result) {
            if (result.value) {
              $.ajax({
                url: "{{ url(config('app.admin_path').'/customers')}}" + "/" + id,
                type: "POST",
                data: {
                  _method: "DELETE",
                  _token: "{{csrf_token()}}"
                },
                success: function(data) {
                  showResponseMessage(data);
                },
                error: function(reject) {
                  if (reject.status === 422) {
                    let errors = reject.responseJSON.errors;
                    $.each(errors, function(key, value) {
                      toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: "toast-top-right",
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    });
                  } else {
                    toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                      positionClass: "toast-top-right",
                      containerId: "toast-top-right",
                      progressBar: true,
                      closeButton: true,
                      newestOnTop: true
                    });
                  }
                }
              });
            }
          });
        });


        //Bulk Enable
        $(".bulk-enable").on("click", function(e) {
          e.preventDefault();

          Swal.fire({
            title: "{{__('locale.labels.are_you_sure')}}",
            text: "{{__('locale.customer.customers_enabled')}}",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "{{__('locale.labels.enable_selected')}}",
            customClass: {
              confirmButton: "btn btn-primary",
              cancelButton: "btn btn-outline-danger ms-1"
            },
            buttonsStyling: false

          }).then(function(result) {
            if (result.value) {
              let customer_ids = [];
              let rows_selected = dataListView.column(1).checkboxes.selected();

              $.each(rows_selected, function(index, rowId) {
                customer_ids.push(rowId);
              });

              if (customer_ids.length > 0) {

                $.ajax({
                  url: "{{ route('admin.customers.batch_action') }}",
                  type: "POST",
                  data: {
                    _token: "{{csrf_token()}}",
                    action: "enable",
                    ids: customer_ids
                  },
                  success: function(data) {
                    showResponseMessage(data);
                  },
                  error: function(reject) {
                    if (reject.status === 422) {
                      let errors = reject.responseJSON.errors;
                      $.each(errors, function(key, value) {
                        toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                          closeButton: true,
                          positionClass: "toast-top-right",
                          progressBar: true,
                          newestOnTop: true,
                          rtl: isRtl
                        });
                      });
                    } else {
                      toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: "toast-top-right",
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    }
                  }
                });
              } else {
                toastr["warning"]("{{ __('locale.labels.at_least_one_data') }}", "{{ __('locale.labels.attention') }}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }
            }
          });
        });

        //Bulk disable
        $(".bulk-disable").on("click", function(e) {

          e.preventDefault();

          Swal.fire({
            title: "{{__('locale.labels.are_you_sure')}}",
            text: "{{__('locale.customer.disable_customers')}}",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "{{__('locale.labels.disable_selected')}}",
            customClass: {
              confirmButton: "btn btn-primary",
              cancelButton: "btn btn-outline-danger ms-1"
            },
            buttonsStyling: false
          }).then(function(result) {
            if (result.value) {
              let customer_ids = [];
              let rows_selected = dataListView.column(1).checkboxes.selected();

              $.each(rows_selected, function(index, rowId) {
                customer_ids.push(rowId);
              });

              if (customer_ids.length > 0) {

                $.ajax({
                  url: "{{ route('admin.customers.batch_action') }}",
                  type: "POST",
                  data: {
                    _token: "{{csrf_token()}}",
                    action: "disable",
                    ids: customer_ids
                  },
                  success: function(data) {
                    showResponseMessage(data);
                  },
                  error: function(reject) {
                    if (reject.status === 422) {
                      let errors = reject.responseJSON.errors;
                      $.each(errors, function(key, value) {
                        toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                          closeButton: true,
                          positionClass: "toast-top-right",
                          progressBar: true,
                          newestOnTop: true,
                          rtl: isRtl
                        });
                      });
                    } else {
                      toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: "toast-top-right",
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    }
                  }
                });
              } else {
                toastr["warning"]("{{__('locale.labels.at_least_one_data')}}", "{{__('locale.labels.attention')}}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }

            }
          });
        });

        //Bulk delete
        $(".bulk-delete").on("click", function(e) {

          e.preventDefault();

          Swal.fire({
            title: "{{__('locale.labels.are_you_sure')}}",
            text: "{{__('locale.customer.delete_customers')}}",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "{{__('locale.labels.delete_selected')}}",
            customClass: {
              confirmButton: "btn btn-primary",
              cancelButton: "btn btn-outline-danger ms-1"
            },
            buttonsStyling: false
          }).then(function(result) {
            if (result.value) {
              let customer_ids = [];
              let rows_selected = dataListView.column(1).checkboxes.selected();

              $.each(rows_selected, function(index, rowId) {
                customer_ids.push(rowId);
              });

              if (customer_ids.length > 0) {

                $.ajax({
                  url: "{{ route('admin.customers.batch_action') }}",
                  type: "POST",
                  data: {
                    _token: "{{csrf_token()}}",
                    action: "delete",
                    ids: customer_ids
                  },
                  success: function(data) {
                    showResponseMessage(data);
                  },
                  error: function(reject) {
                    if (reject.status === 422) {
                      let errors = reject.responseJSON.errors;
                      $.each(errors, function(key, value) {
                        toastr["warning"](value[0], "{{__('locale.labels.attention')}}", {
                          closeButton: true,
                          positionClass: "toast-top-right",
                          progressBar: true,
                          newestOnTop: true,
                          rtl: isRtl
                        });
                      });
                    } else {
                      toastr["warning"](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: "toast-top-right",
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    }
                  }
                });
              } else {
                toastr["warning"]("{{__('locale.labels.at_least_one_data')}}", "{{__('locale.labels.attention')}}", {
                  closeButton: true,
                  positionClass: "toast-top-right",
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }

            }
          });
        });


        if (localStorage.getItem("importDismissed") === "true") {
          $("#import-done-alert").hide();
        }
      });

      function dismissImportAlert() {
        localStorage.setItem("importDismissed", "true");
        $("#import-done-alert").remove();
      }

    </script>
@endsection
