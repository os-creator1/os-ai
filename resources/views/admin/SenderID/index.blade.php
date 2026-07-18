@php use App\Models\Senderid; @endphp
@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Sender ID'))

@section('vendor-style')
    {{-- vendor css files --}}
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/dataTables.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/responsive.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/buttons.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">

@endsection

@section('content')

    <!-- Basic table -->
    <section id="datatables-basic">
        <div class="mb-3 mt-2">
            @can('view sender_id')
                <div class="btn-group">
                    <button
                            class="btn btn-warning fw-bold dropdown-toggle"
                            type="button"
                            id="bulk_actions"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                    >
                        {{ __('locale.labels.actions') }}
                    </button>
                    <div class="dropdown-menu" aria-labelledby="bulk_actions">

                        <a class="dropdown-item bulk-active" href="#"><i
                                    data-feather="check"></i> {{ __('locale.labels.active') }}</a>
                        <a class="dropdown-item bulk-block" href="#"><i
                                    data-feather="stop-circle"></i> {{ __('locale.labels.block') }}</a>
                        <a class="dropdown-item bulk-delete" href="#"><i
                                    data-feather="trash"></i> {{ __('locale.datatables.bulk_delete') }}</a>

                    </div>
                </div>
            @endcan

            @can('create sender_id')
                <div class="btn-group">
                    <a href="{{route('admin.senderid.create')}}"
                       class="btn btn-success waves-light waves-effect fw-bold mx-1"> {{__('locale.buttons.create')}} <i
                                data-feather="plus-circle"></i></a>
                </div>
            @endcan

            @can('view sender_id')
                <div class="btn-group">
                    <a href="{{route('admin.senderid.export')}}"
                       class="btn btn-info waves-light waves-effect fw-bold"> {{__('locale.buttons.export')}} <i
                                data-feather="file-text"></i></a>
                </div>

                <div class="btn-group">
                    <a href="{{route('admin.senderid.plan')}}"
                       class="btn btn-primary waves-light waves-effect fw-bold mx-1"> {{__('locale.labels.create_plan')}}
                        <i data-feather="shopping-cart"></i></a>
                </div>
            @endcan

        </div>

        <div class="row">
            <div class="col-12">
                <div class="alert alert-info" role="alert">
                    <div class="alert-body d-flex align-items-center">
                        <i data-feather="info" class="me-50"></i>
                        <span> Without creating Sender ID plan, your customer can't send Sender ID request from their portal.</span>
                    </div>
                </div>
            </div>
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
                            <th>{{__('locale.menu.Sender ID')}} </th>
                            <th>{{__('locale.labels.assign_to')}} </th>
                            <th>{{__('locale.plans.price')}}</th>
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
    {{-- Page js files --}}
    <script>
      $(document).ready(function() {
        "use strict"

        //show response message
        function showResponseMessage(data) {

          if (data.status === 'success') {
            toastr['success'](data.message, '{{__('locale.labels.success')}}!!', {
              closeButton: true,
              positionClass: 'toast-top-right',
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });
            dataListView.draw(false);
          } else if (data.status === 'error') {
            toastr['error'](data.message, '{{ __('locale.labels.opps') }}!', {
              closeButton: true,
              positionClass: 'toast-top-right',
              progressBar: true,
              newestOnTop: true,
              rtl: isRtl
            });
            dataListView.draw(false);
          } else {
            toastr['warning']("{{__('locale.exceptions.something_went_wrong')}}", '{{ __('locale.labels.warning') }}!', {
              closeButton: true,
              positionClass: 'toast-top-right',
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
        let dataListView = $('.datatables-basic').DataTable({

          "processing": true,
          "serverSide": true,
          "ajax": {
            "url": "{{ route('admin.senderid.search') }}",
            "dataType": "json",
            "type": "POST",
            "data": { _token: "{{csrf_token()}}" }
          },
          "columns": [
            { "data": 'responsive_id', orderable: false, searchable: false },
            { "data": "uid" },
            { "data": "uid" },
            { "data": "sender_id" },
            { "data": "user_id" },
            { "data": "price" },
            { "data": "status" },
            { "data": "action", orderable: false, searchable: false }
          ],

          searchDelay: 1500,
          columnDefs: [
            {
              // For Responsive
              className: 'control',
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
                  '<div class="form-check"> <input class="form-check-input dt-checkboxes" type="checkbox" value="" id="' +
                  data +
                  '" /><label class="form-check-label" for="' +
                  data +
                  '"></label></div>'
                );
              },
              checkboxes: {
                selectAllRender:
                  '<div class="form-check"> <input class="form-check-input" type="checkbox" value="" id="checkboxSelectAll" /><label class="form-check-label" for="checkboxSelectAll"></label></div>',
                selectRow: true
              }
            },
            {
              targets: 2,
              visible: false
            },
            {
              // sender id
              targets: 3,
              responsivePriority: 1,
              render: function(data, type, full) {
                const $created_at = full['created_at'],
                  $sender_id = full['sender_id'];

                return '<div class="d-flex flex-column">' +
                  '<span class="emp_name text-truncate fw-bold">' +
                  $sender_id +
                  '</span>' +
                  '<small class="emp_post text-truncate text-muted">' +
                  $created_at +
                  '</small>' +
                  '</div>';
              }
            },
            {
              // Avatar image/badge, Name and post
              targets: 4,
              responsivePriority: 1,
              render: function(data, type, full) {
                const $user_img = full['avatar'],
                  $name = full['user_id'],
                  $email = full['email'],
                  $output = '<img src="' + $user_img + '" alt="Avatar" width="32" height="32">';

                let colorClass = $user_img === '' ? ' bg-light-' + $state + ' ' : '';
                // Creates full output for row
                return '<div class="d-flex justify-content-left align-items-center">' +
                  '<div class="avatar ' +
                  colorClass +
                  ' me-1">' +
                  $output +
                  '</div>' +
                  '<div class="d-flex flex-column">' +
                  '<span class="emp_name text-truncate fw-bold">' +
                  $name +
                  '</span>' +
                  '<small class="emp_post text-truncate text-muted">' +
                  $email +
                  '</small>' +
                  '</div>' +
                  '</div>';
              }
            },
            {
              // Actions
              targets: -1,
              title: '{{ __('locale.labels.actions') }}',
              orderable: false,
              render: function(data, type, full) {
                let actions = '',
                  $status = { label: '', btn_color: '', btn_border: '', status: '' },
                  $status_dropdown_list = '';

                if (full['actual_status'] !== "{{ Senderid::STATUS_ACTIVE }}") {
                  $status.label = "{{ __('locale.buttons.activate') }}"
                  $status.btn_color = "btn-success"
                  $status.btn_border = "btn-outline-success"
                  $status.status = "{{ Senderid::STATUS_ACTIVE }}"
                } else {
                  $status.label = "{{ __('locale.buttons.block') }}"
                  $status.btn_color = "btn-warning"
                  $status.btn_border = "btn-outline-warning"
                  $status.status = "{{ Senderid::STATUS_BLOCKED }}"
                }

                full['status_dropdown_list'].forEach(el => {
                  if (full['actual_status'] === el) {
                    return;
                  }
                  $status_dropdown_list += `<li><a class="dropdown-item action-change-status text-capitalize" data-id="${full['uid']}" data-new-status="${el.value}"> ${el.label.replace(/_/g, ' ')} </a></li>`
                });

                feather.replace();

                actions += `
														<div class="btn-group">
															<button class="btn btn-sm ${$status.btn_color} ${$status.btn_border} action-change-status" type="button" data-id="${full['uid']}" data-new-status="${$status.status}">
																${$status.label}
															</button>
															<button type="button" class="btn btn-sm btn-default ${$status.btn_border}  dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" title="{{ __('locale.labels.change_status') }}">
																<span class="visually-hidden">Toggle Dropdown</span>
															</button>
															<ul class="dropdown-menu">
																${$status_dropdown_list}
															</ul>
														</div>`;

                actions += `
														<div class="btn-group">
															<button class="btn btn-default btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
																<i data-feather="more-vertical"></i>
															</button>
															<ul class="dropdown-menu p-1">
																<li class="mb-1">
																	<a href="${full['edit']}" class="btn btn-sm btn-primary d-block w-100 cursor-pointer">
																		<i data-feather="edit-2"></i> {{ __('locale.buttons.edit') }}
                </a>
            </li>
            <li>
                <a class="btn btn-sm action-delete btn-danger d-block w-100 cursor-pointer" data-id="${full['uid']}">
																		<i data-feather="trash"></i>  {{ __('locale.buttons.delete') }}
                </a>
            </li>
        </ul>
    </div>`;

                return actions;
              }
            }
          ],
          dom: '<"d-flex justify-content-between align-items-center mx-0 row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>t<"d-flex justify-content-between mx-0 row"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6"p>>',

          language: {
            paginate: {
              // remove previous & next text from pagination
              previous: '&nbsp;',
              next: '&nbsp;'
            },
            sLengthMenu: "_MENU_",
            sZeroRecords: "{{ __('locale.datatables.no_results') }}",
            sSearch: "{{ __('locale.datatables.search') }}",
            sProcessing: "{{ __('locale.datatables.processing') }}",
            sInfo: "{{ __('locale.datatables.showing_entries', ['start' => '_START_', 'end' => '_END_', 'total' => '_TOTAL_']) }}"
          },
          responsive: {
            details: {
              type: 'column',
              renderer: function(api, rowIdx, columns) {
                let data = $.map(columns, function(col) {
                  return col.title !== '' // ? Do not show row in modal popup if title is blank (for check box)
                    ? '<tr data-dt-row="' +
                    col.rowIdx +
                    '" data-dt-column="' +
                    col.columnIndex +
                    '">' +
                    '<td>' +
                    col.title +
                    ':' +
                    '</td> ' +
                    '<td>' +
                    col.data +
                    '</td>' +
                    '</tr>'
                    : '';
                }).join('');
                return data ? $('<table class="table"/>').append('<tbody>' + data + '</tbody>') : false;
              }
            }
          },
          aLengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
          select: {
            style: "multi"
          },
          order: [[2, "desc"]],
          displayLength: 10,
        });

        dataListView.on('page.dt', function() {
          feather.replace();
        });

        dataListView.on('order.dt', function() {
          feather.replace();
        });

        dataListView.on('search.dt', function() {
          feather.replace();
        });

        dataListView.on('xhr.dt', function() { // Triggered on AJAX calls
          feather.replace();
        });
        dataListView.on('draw', function() { // Triggered on AJAX calls
          feather.replace();
        });

        // On Delete
        Table.delegate(".action-delete", "click", function(e) {
          e.stopPropagation();
          let id = $(this).data('id');
          Swal.fire({
            title: "{{ __('locale.labels.are_you_sure') }}",
            text: "{{ __('locale.labels.able_to_revert') }}",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: "{{ __('locale.labels.delete_it') }}",
            customClass: {
              confirmButton: 'btn btn-primary',
              cancelButton: 'btn btn-outline-danger ms-1'
            },
            buttonsStyling: false,
          }).then(function(result) {
            if (result.value) {
              $.ajax({
                url: "{{ url(config('app.admin_path').'/senderid')}}" + '/' + id,
                type: "POST",
                data: {
                  _method: 'DELETE',
                  _token: "{{csrf_token()}}"
                },
                success: function(data) {
                  showResponseMessage(data);
                },
                error: function(reject) {
                  if (reject.status === 422) {
                    let errors = reject.responseJSON.errors;
                    $.each(errors, function(key, value) {
                      toastr['warning'](value[0], "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    });
                  } else {
                    toastr['warning'](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                      positionClass: 'toast-top-right',
                      containerId: 'toast-top-right',
                      progressBar: true,
                      closeButton: true,
                      newestOnTop: true
                    });
                  }
                }
              })
            }
          })
        });


        //Bulk Active
        $(".bulk-active").on('click', function(e) {
          e.preventDefault();

          Swal.fire({
            title: "{{__('locale.labels.are_you_sure')}}",
            text: "{{__('locale.sender_id.active_senderids')}}",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: "{{__('locale.labels.active')}}",
            customClass: {
              confirmButton: 'btn btn-primary',
              cancelButton: 'btn btn-outline-danger ms-1'
            },
            buttonsStyling: false,

          }).then(function(result) {
            if (result.value) {
              let sender_ids = [];
              let rows_selected = dataListView.column(1).checkboxes.selected();

              $.each(rows_selected, function(index, rowId) {
                sender_ids.push(rowId)
              });

              if (sender_ids.length > 0) {

                $.ajax({
                  url: "{{ route('admin.senderid.batch_action') }}",
                  type: "POST",
                  data: {
                    _token: "{{csrf_token()}}",
                    action: 'active',
                    ids: sender_ids
                  },
                  success: function(data) {
                    showResponseMessage(data);
                  },
                  error: function(reject) {
                    if (reject.status === 422) {
                      let errors = reject.responseJSON.errors;
                      $.each(errors, function(key, value) {
                        toastr['warning'](value[0], "{{__('locale.labels.attention')}}", {
                          closeButton: true,
                          positionClass: 'toast-top-right',
                          progressBar: true,
                          newestOnTop: true,
                          rtl: isRtl
                        });
                      });
                    } else {
                      toastr['warning'](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    }
                  }
                })
              } else {
                toastr['warning']("{{ __('locale.labels.at_least_one_data') }}", "{{ __('locale.labels.attention') }}", {
                  closeButton: true,
                  positionClass: 'toast-top-right',
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }
            }
          })
        });

        //Bulk block
        $(".bulk-block").on('click', function(e) {
          e.preventDefault();

          Swal.fire({
            title: "{{__('locale.labels.are_you_sure')}}",
            text: "{{__('locale.sender_id.block_senderids')}}",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: "{{__('locale.labels.block')}}",
            customClass: {
              confirmButton: 'btn btn-primary',
              cancelButton: 'btn btn-outline-danger ms-1'
            },
            buttonsStyling: false,

          }).then(function(result) {
            if (result.value) {
              let sender_ids = [];
              let rows_selected = dataListView.column(1).checkboxes.selected();

              $.each(rows_selected, function(index, rowId) {
                sender_ids.push(rowId)
              });

              if (sender_ids.length > 0) {

                $.ajax({
                  url: "{{ route('admin.senderid.batch_action') }}",
                  type: "POST",
                  data: {
                    _token: "{{csrf_token()}}",
                    action: 'block',
                    ids: sender_ids
                  },
                  success: function(data) {
                    showResponseMessage(data);
                  },
                  error: function(reject) {
                    if (reject.status === 422) {
                      let errors = reject.responseJSON.errors;
                      $.each(errors, function(key, value) {
                        toastr['warning'](value[0], "{{__('locale.labels.attention')}}", {
                          closeButton: true,
                          positionClass: 'toast-top-right',
                          progressBar: true,
                          newestOnTop: true,
                          rtl: isRtl
                        });
                      });
                    } else {
                      toastr['warning'](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    }
                  }
                })
              } else {
                toastr['warning']("{{ __('locale.labels.at_least_one_data') }}", "{{ __('locale.labels.attention') }}", {
                  closeButton: true,
                  positionClass: 'toast-top-right',
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }
            }
          })
        });

        //Bulk Delete
        $(".bulk-delete").on('click', function(e) {

          e.preventDefault();

          Swal.fire({
            title: "{{__('locale.labels.are_you_sure')}}",
            text: "{{__('locale.sender_id.delete_senderids')}}",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: "{{__('locale.labels.delete_selected')}}",
            customClass: {
              confirmButton: 'btn btn-primary',
              cancelButton: 'btn btn-outline-danger ms-1'
            },
            buttonsStyling: false,
          }).then(function(result) {
            if (result.value) {
              let sender_ids = [];
              let rows_selected = dataListView.column(1).checkboxes.selected();

              $.each(rows_selected, function(index, rowId) {
                sender_ids.push(rowId)
              });

              if (sender_ids.length > 0) {

                $.ajax({
                  url: "{{ route('admin.senderid.batch_action') }}",
                  type: "POST",
                  data: {
                    _token: "{{csrf_token()}}",
                    action: 'destroy',
                    ids: sender_ids
                  },
                  success: function(data) {
                    showResponseMessage(data);
                  },
                  error: function(reject) {
                    if (reject.status === 422) {
                      let errors = reject.responseJSON.errors;
                      $.each(errors, function(key, value) {
                        toastr['warning'](value[0], "{{__('locale.labels.attention')}}", {
                          closeButton: true,
                          positionClass: 'toast-top-right',
                          progressBar: true,
                          newestOnTop: true,
                          rtl: isRtl
                        });
                      });
                    } else {
                      toastr['warning'](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    }
                  }
                })
              } else {
                toastr['warning']("{{__('locale.labels.at_least_one_data')}}", "{{__('locale.labels.attention')}}", {
                  closeButton: true,
                  positionClass: 'toast-top-right',
                  progressBar: true,
                  newestOnTop: true,
                  rtl: isRtl
                });
              }

            }
          })
        });


        // On Change Status
        $("body").on('click', 'button.action-change-status, a.action-change-status', function(e) {
          e.stopPropagation();
          let id = $(this).data('id'), new_status = $(this).data('new-status'), $button = $(this);

          console.log(id, new_status);

          Swal.fire({
            title: "{{ __('locale.labels.are_you_sure') }}",
            text: "{{ __('locale.labels.able_to_revert') }}",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: "{{ __('locale.labels.yes') }}",
            customClass: {
              confirmButton: 'btn btn-primary',
              cancelButton: 'btn btn-outline-danger ms-1'
            },
            buttonsStyling: false,
          }).then(function(result) {
            if (result.value) {
              $button.prepend('<i class="fas fa-spinner fa-spin me-2"></i>');
              $button.prop('disabled', true);

              $.ajax({
                url: "{{ url(config('app.admin_path').'/senderid')}}" + '/' + id + '/update-status',
                type: "PUT",
                data: {
                  _token: "{{csrf_token()}}",
                  status: new_status
                },
                success: function(data) {
                  $button.find('i').remove();
                  $button.prop('disabled', false);
                  showResponseMessage(data);
                },
                error: function(reject) {
                  $button.find('i').remove();
                  $button.prop('disabled', false);
                  if (reject.status === 422) {
                    let errors = reject.responseJSON.errors;
                    $.each(errors, function(key, value) {
                      toastr['warning'](value[0], "{{__('locale.labels.attention')}}", {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                      });
                    });
                  } else {
                    toastr['warning'](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                      positionClass: 'toast-top-right',
                      containerId: 'toast-top-right',
                      progressBar: true,
                      closeButton: true,
                      newestOnTop: true
                    });
                  }
                },
                complete: function() {
                  $button.find('i').remove();
                  $button.prop('disabled', false);
                  $button.closest('.modal').modal('hide');
                }
              })
            }
          })
        });
      });

    </script>
@endsection
