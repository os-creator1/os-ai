@extends('layouts.contentLayoutMaster')

@section('title', __('locale.menu.Overview'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/dataTables.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/responsive.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/buttons.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">

@endsection

@section('page-style')
    <!-- Page css files -->
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/charts/chart-apex.css')) }}">
@endsection

@section('content')
    <section class="campaign-overview">
        <div class="row">
            <div class="col-12">

                <ul class="nav nav-pills mb-2" role="tablist">
                    <!-- overview -->
                    <li class="nav-item">
                        <a class="nav-link active" id="account-tab" data-bs-toggle="tab" href="#overview"
                           aria-controls="overview" role="tab" aria-selected="true">
                            <i data-feather="pie-chart" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{__('locale.menu.Overview')}}</span>
                        </a>
                    </li>

                    <!-- contacts -->
                    <li class="nav-item">
                        <a class="nav-link" id="contacts-tab" data-bs-toggle="tab" href="#contacts"
                           aria-controls="contacts" role="tab" aria-selected="false">
                            <i data-feather="bar-chart" class="font-medium-3 me-50"></i>
                            <span class="fw-bold">{{ __('locale.menu.SMS History') }}</span>
                        </a>
                    </li>

                </ul>


                <div class="tab-content">

                    <div class="tab-pane active" id="overview" aria-labelledby="overview-tab" role="tabpanel">
                        @include('customer.Campaigns._overview')
                    </div>

                    <div class="tab-pane" id="contacts" aria-labelledby="contacts-tab" role="tabpanel">
                        @include('customer.Campaigns._contacts')
                    </div>

                </div>
            </div>
        </div>
    </section>
@endsection

@section('vendor-script')
    <!-- vendor files -->
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
    <script src="{{ asset(mix('vendors/js/charts/apexcharts.min.js')) }}"></script>

@endsection


@section('page-script')

    <script>
      $(document).ready(function() {

        $("#contacts-tab").on("click", function(e) {
          $($.fn.dataTable.tables(true)).DataTable()
            .columns.adjust()
            .responsive.recalc();
        });

        $(window).on("load", function() {

          let chartColors = {
            column: {
              series1: PlatformTheme.chartPalette()[0],
              series2: PlatformTheme.chartPalette()[1],
              bg: PlatformTheme.chartNeutral()
            },
            success: {
              shade_100: "#7eefc7",
              shade_200: "#06774f"
            },
            donut: {
              delivered: "#00d4bd",   // Correcting color for delivered
              enroute: "#2b9bf4",
              expired: "#ffe700",
              undelivered: "#ffa1a1",
              rejected: "#f64e60",
              accepted: "#50cd89",
              skipped: "#f64e60",
              failed: "#e74c3c"
            },
            area: {
              series3: PlatformTheme.chartPalette()[2],
              series2: PlatformTheme.chartPalette()[3],
              series1: PlatformTheme.chartPalette()[4]
            }
          };


          // Customer Chart
          // -----------------------------

          let Delivered = "{{ $reportStatusCounts->delivered_count }}",
            Enroute = "{{ $reportStatusCounts->enroute_count }}",
            Expired = "{{ $reportStatusCounts->expired_count }}",
            Undelivered = "{{ $reportStatusCounts->undelivered_count }}",
            Rejected = "{{ $reportStatusCounts->rejected_count }}",
            Accepted = "{{ $reportStatusCounts->accepted_count }}",
            Skipped = "{{ $reportStatusCounts->skipped_count }}",
            Failed = "{{ $reportStatusCounts->failed_count }}";

          let smsReports = {
            chart: {
              type: "pie",
              height: 350,
              toolbar: {
                show: false
              }
            },
            labels: ["{{ __('locale.labels.delivered') }}", "{{ __('locale.labels.enroute') }}", "{{ __('locale.labels.expired') }}", "{{ __('locale.labels.undelivered') }}", "{{ __('locale.labels.rejected') }}", "{{ __('locale.labels.accepted') }}", "{{ __('locale.labels.skipped') }}", "{{ __('locale.labels.failed') }}"],
            series: [parseInt(Delivered), parseInt(Enroute), parseInt(Expired), parseInt(Undelivered), parseInt(Rejected), parseInt(Accepted), parseInt(Skipped), parseInt(Failed)],
            dataLabels: {
              enabled: false
            },
            legend: { show: false },
            stroke: {
              width: 4
            },
            colors: [
              chartColors.donut.delivered,
              chartColors.donut.enroute,
              chartColors.donut.expired,
              chartColors.donut.undelivered,
              chartColors.donut.rejected,
              chartColors.donut.accepted,
              chartColors.donut.skipped,
              chartColors.donut.failed
            ]
          };

          let customerChart = new ApexCharts(
            document.querySelector("#sms-reports"),
            smsReports
          );

          customerChart.render();


          let DeliveredPercentage = parseFloat("{{ $reportStatusPercentages['delivered_percentage'] }}").toFixed(2),
            EnroutePercentage = parseFloat("{{ $reportStatusPercentages['enroute_percentage'] }}").toFixed(2),
            ExpiredPercentage = parseFloat("{{ $reportStatusPercentages['expired_percentage'] }}").toFixed(2),
            UndeliveredPercentage = parseFloat("{{ $reportStatusPercentages['undelivered_percentage'] }}").toFixed(2),
            RejectedPercentage = parseFloat("{{ $reportStatusPercentages['rejected_percentage'] }}").toFixed(2),
            AcceptedPercentage = parseFloat("{{ $reportStatusPercentages['accepted_percentage'] }}").toFixed(2),
            SkippedPercentage = parseFloat("{{ $reportStatusPercentages['skipped_percentage'] }}").toFixed(2),
            FailedPercentage = parseFloat("{{ $reportStatusPercentages['failed_percentage'] }}").toFixed(2);


          let reportsRatio = document.querySelector("#reports-ratio"),
            reportsRatioChartConfig = {
              chart: {
                height: 350,
                type: "donut"
              },
              legend: {
                show: false,
                position: "bottom"
              },
              labels: ["{{ __('locale.labels.delivered') }}", "{{ __('locale.labels.enroute') }}", "{{ __('locale.labels.expired') }}", "{{ __('locale.labels.undelivered') }}", "{{ __('locale.labels.rejected') }}", "{{ __('locale.labels.accepted') }}", "{{ __('locale.labels.skipped') }}", "{{ __('locale.labels.failed') }}"],
              series: [
                parseFloat(DeliveredPercentage),
                parseFloat(EnroutePercentage),
                parseFloat(ExpiredPercentage),
                parseFloat(UndeliveredPercentage),
                parseFloat(RejectedPercentage),
                parseFloat(AcceptedPercentage),
                parseFloat(SkippedPercentage),
                parseFloat(FailedPercentage)
              ],
              colors: [
                chartColors.donut.delivered,
                chartColors.donut.enroute,
                chartColors.donut.expired,
                chartColors.donut.undelivered,
                chartColors.donut.rejected,
                chartColors.donut.accepted,
                chartColors.donut.skipped,
                chartColors.donut.failed
              ],
              dataLabels: {
                enabled: false,
                formatter: function(val, opt) {
                  return parseFloat(val).toFixed(2) + "%";
                }
              },
              plotOptions: {
                pie: {
                  donut: {
                    labels: {
                      show: true,
                      name: {
                        fontSize: "2rem",
                        fontFamily: "Montserrat"
                      },
                      value: {
                        fontSize: "1rem",
                        fontFamily: "Montserrat",
                        formatter: function(val) {
                          return parseInt(val) + "%";
                        }
                      },
                      total: {
                        show: true,
                        fontSize: "1.5rem",
                        label: '{{ __('locale.labels.delivered') }}',
                        formatter: function(w) {
                          // You can customize the total here
                          return parseFloat(DeliveredPercentage) + "%";
                        }
                      }
                    }
                  }
                }
              },
              responsive: [
                {
                  breakpoint: 992,
                  options: {
                    chart: {
                      height: 380
                    }
                  }
                },
                {
                  breakpoint: 576,
                  options: {
                    chart: {
                      height: 320
                    },
                    plotOptions: {
                      pie: {
                        donut: {
                          labels: {
                            show: true,
                            name: {
                              fontSize: "1.5rem"
                            },
                            value: {
                              fontSize: "1rem"
                            },
                            total: {
                              fontSize: "1.5rem"
                            }
                          }
                        }
                      }
                    }
                  }
                }
              ]
            };

          if (typeof reportsRatio !== "undefined" && reportsRatio !== null) {
            let reportsRatioChart = new ApexCharts(reportsRatio, reportsRatioChartConfig);
            reportsRatioChart.render();
          }


        });


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
            "url": "{{ route('customer.reports.campaign.reports', $campaign->uid) }}",
            "dataType": "json",
            "type": "POST",
            "data": { _token: "{{csrf_token()}}" }
          },
          "columns": [
            { "data": "responsive_id", orderable: false, searchable: false },
            { "data": "uid" },
            { "data": "uid" },
            { "data": "uid" },
            { "data": "created_at" },
            { "data": "from" },
            { "data": "to" },
            { "data": "sms_count" },
            { "data": "cost" },
            { "data": "customer_status" },
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
              // Actions
              targets: -1,
              title: '{{ __('locale.labels.actions') }}',
              orderable: false,
              render: function(data, type, full) {
                let $actions = "";
                if (full["can_delete"]) {
                  $actions += "<span class=\"action-delete text-danger pe-1 cursor-pointer\" data-id=" + full["uid"] + ">" +
                    feather.icons["trash"].toSvg({ class: "font-medium-4" }) +
                    "</span>";
                }


                if (full["dlr"]) {
                  $actions += "<span class=\"action-dlr text-primary pe-1 cursor-pointer\" data-bs-toggle=\"tooltip\" data-bs-placement=\"top\" title=\"DLR\" data-id=" + full["uid"] + ">" +
                    feather.icons["file-text"].toSvg({ class: "font-medium-4" }) +
                    "</span>";
                }

                return (
                  $actions +
                  "<span class=\"action-view text-primary pe-1 cursor-pointer\" data-id=" + full["uid"] + ">" +
                  feather.icons["eye"].toSvg({ class: "font-medium-4" }) +
                  "</span>"
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
                  return "Details of " + data["uid"];
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


        // On view
        Table.delegate(".action-view", "click", function(e) {
          e.stopPropagation();
          let id = $(this).data("id");

          // Show loader first
          Swal.fire({
            title: "{{ __('locale.labels.loading') }}",
            html: `<div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">{{ __('locale.labels.loading') }}...</span>
               </div>`,
            showConfirmButton: false,
            allowOutsideClick: false,
            didOpen: () => {
              $.ajax({
                url: "{{ url('/reports')}}" + "/" + id + "/view",
                type: "POST",
                data: { _token: "{{csrf_token()}}" },
                success: function(data) {

                  // Ensure JSON object
                  if (typeof data === "string") {
                    try {
                      data = JSON.parse(data);
                    } catch (e) {
                      console.error("Invalid JSON response", e);
                      return;
                    }
                  }

                  let d = data?.data ?? {}; // safe access

                  Swal.fire({
                    html: `<div class="table-responsive">
                   <table class="table">

                           <tbody>
                               <tr>
                                   <td width="35%">{{ __('locale.labels.from') }}</td>
                                   <td>` + d.from + `</td>
                               </tr>
                               <tr>
                                   <td width="35%">{{ __('locale.labels.to') }}</td>
                                   <td>` + d.to + `</td>
                               </tr>
                               <tr>
                                   <td width="35%">{{ __('locale.labels.message') }}</td>
                                   <td>` + d.message + `</td>
                               </tr>
                               <tr>
                                   <td width="35%">{{ __('locale.labels.type') }}</td>
                                   <td>` + d.sms_type + `</td>
                               </tr>
                               <tr>
                                   <td width="35%">{{ __('locale.labels.status') }}</td>
                                   <td>` + d.customer_status + `</td>
                               </tr>
                               <tr>
                                   <td width="35%">{{ __('locale.labels.cost') }}</td>
                                   <td>` + d.cost + `</td>
                               </tr>

                   </tbody>
                   </table>
                   </div>
                   `
                  });
                },
                error: function(reject) {
                  Swal.close(); // close loader
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
                url: "{{ url('/reports')}}" + "/" + id + "/destroy",
                type: "POST",
                data: {
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
            }
          });
        });

        //Bulk Delete
        $(".bulk-delete").on("click", function(e) {

          e.preventDefault();

          Swal.fire({
            title: "{{__('locale.labels.are_you_sure')}}",
            text: "{{__('locale.campaigns.delete_sms')}}",
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
              let sms_ids = [];
              let rows_selected = dataListView.column(1).checkboxes.selected();

              $.each(rows_selected, function(index, rowId) {
                sms_ids.push(rowId);
              });

              if (sms_ids.length > 0) {

                $.ajax({
                  url: "{{ route('customer.reports.batch_action') }}",
                  type: "POST",
                  data: {
                    _token: "{{csrf_token()}}",
                    action: "destroy",
                    ids: sms_ids
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


        // On DLR
        Table.delegate(".action-dlr", "click", function(e) {
          e.stopPropagation();
          let id = $(this).data("id");
          Swal.fire({
            title: "Check Delivery Report",
            text: "Are you sure you want to check delivery report?",
            icon: "success",
            showCancelButton: true,
            confirmButtonText: "{{ __('locale.labels.yes') }}",
            customClass: {
              confirmButton: "btn btn-primary",
              cancelButton: "btn btn-outline-danger ms-1"
            },
            buttonsStyling: false
          }).then(function(result) {
            if (result.value) {
              e.stopPropagation();
              $.ajax({
                url: "{{ url('/reports')}}" + "/" + id + "/dlr",
                type: "POST",
                data: {
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
                      closeButton: true,
                      positionClass: "toast-top-right",
                      progressBar: true,
                      newestOnTop: true,
                      rtl: isRtl
                    });
                  }
                }
              });

            }
          });
        });

      });


    </script>
@endsection
