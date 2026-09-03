@extends('layouts.contentLayoutMaster')

@section('title', $contact->name)

@section('vendor-style')
    {{-- vendor css files --}}
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/dataTables.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/responsive.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/tables/datatable/buttons.bootstrap5.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/extensions/sweetalert2.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/pickers/flatpickr/flatpickr.min.css')) }}">

@endsection

@section('page-style')

    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/forms/pickers/form-flat-pickr.css')) }}">

    <style>
        .customized_select2 .select2-selection--single {
            border-left: 0;
            border-radius: 0 4px 4px 0;
            min-height: 2.75rem !important;
        }

        .input-group > div.position-relative {
            flex-grow: 1;
        }
    </style>

@endsection

@section('content')

    <section id="nav-justified">
        <div class="row">
            <div class="col-12">

                <ul class="nav nav-pills mb-2 text-uppercase" role="tablist">

                    {{-- contact --}}
                    @can('view_contact')
                        <li class="nav-item">
                            <a class="nav-link @if (old('tab') == 'contact' || old('tab') == null) active @endif"
                               id="contact-tab-justified"
                               data-bs-toggle="tab"
                               href="#contact"
                               role="tab"
                               aria-controls="contact"
                               aria-selected="true">
                                <i data-feather="users"></i> {{ __('locale.contacts.contacts') }}
                            </a>
                        </li>
                    @endcan

                    @can('update_contact_group')
                        <li class="nav-item">
                            <a class="nav-link {{ old('tab') == 'settings' ? 'active':null }}"
                               id="settings-tab-justified"
                               data-bs-toggle="tab"
                               href="#settings"
                               role="tab"
                               aria-controls="settings"
                               aria-selected="true">
                                <i data-feather="settings"></i> {{ __('locale.labels.settings') }}
                            </a>
                        </li>
                    @endcan


                    {{-- settings --}}
                    @can('update_contact_group')

                        {{-- message --}}
                        <li class="nav-item">
                            <a class="nav-link {{ old('tab') == 'message' ? 'active':null }}"
                               id="message-tab-justified"
                               data-bs-toggle="tab"
                               href="#message"
                               role="tab"
                               aria-controls="message"
                               aria-selected="true">
                                <i data-feather="message-circle"></i> {{ __('locale.labels.message') }}
                            </a>
                        </li>

                        {{--Version 3.9--}}
                        {{-- Segments --}}
                        {{--                        <li class="nav-item">--}}
                        {{--                            <a class="nav-link {{ old('tab') == 'segments' ? 'active':null }}"--}}
                        {{--                               id="segments-tab-justified"--}}
                        {{--                               data-bs-toggle="tab"--}}
                        {{--                               href="#segments"--}}
                        {{--                               role="tab"--}}
                        {{--                               aria-controls="segments"--}}
                        {{--                               aria-selected="true">--}}
                        {{--                                <i data-feather="server"></i> {{ __('locale.labels.segments') }}--}}
                        {{--                            </a>--}}
                        {{--                        </li>--}}

                        {{-- Manage Fields --}}
                        <li class="nav-item">
                            <a class="nav-link {{ old('tab') == 'fields' ? 'active':null }}"
                               id="fields-tab-justified"
                               data-bs-toggle="tab"
                               href="#fields"
                               role="tab"
                               aria-controls="fields"
                               aria-selected="true">
                                <i data-feather="edit-3"></i> {{ __('locale.labels.manage_fields') }}
                            </a>
                        </li>
                        {{--End Version 3.9--}}

                        {{-- opt in keywords --}}
                        <li class="nav-item dropdown">

                            <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#" role="button"
                               aria-expanded="false"><i data-feather="hash"></i> {{ __('locale.menu.Keywords') }}</a>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="nav-link dropdown-item {{ old('tab') == 'opt_in_keywords' ? 'active':null }}"
                                       id="opt_in_keywords-tab-justified"
                                       data-bs-toggle="tab"
                                       href="#opt_in_keywords"
                                       role="tab"
                                       aria-controls="opt_in_keywords"
                                       aria-selected="true">
                                        <i data-feather="user-check"></i> {{ __('locale.contacts.opt_in_keywords') }}
                                    </a></li>

                                <li>
                                    <a class="nav-link dropdown-item {{ old('tab') == 'opt_out_keywords' ? 'active':null }}"
                                       id="opt_out_keywords-tab-justified"
                                       data-bs-toggle="tab"
                                       href="#opt_out_keywords"
                                       role="tab"
                                       aria-controls="opt_out_keywords"
                                       aria-selected="true">
                                        <i data-feather="user-minus"></i> {{ __('locale.contacts.opt_out_keywords') }}
                                    </a></li>
                            </ul>

                        </li>

                    @endcan

                    {{-- import history --}}
                    @can('create_contact_group')
                        <li class="nav-item">
                            <a class="nav-link {{ old('tab') == 'import_history' ? 'active':null }}"
                               id="import-history-tab-justified"
                               data-bs-toggle="tab"
                               href="#import-history"
                               role="tab"
                               aria-controls="import-history"
                               aria-selected="true">
                                <i data-feather="pie-chart"></i> {{ __('locale.contacts.import_history') }}
                            </a>
                        </li>
                    @endcan

                </ul>

                {{-- Tab panes --}}
                <div class="tab-content pt-1">


                    {{-- cotnacts --}}
                    @can('view_contact')
                        <div class="tab-pane @if (old('tab') == 'contact' || old('tab') == null) active @endif"
                             id="contact" role="tabpanel" aria-labelledby="contact-tab-justified">
                            @include('customer.contactGroups._contacts')
                        </div>
                    @endcan

                    @can('update_contact_group')
                        <div class="tab-pane {{ old('tab') == 'settings' ? 'active':null }}" id="settings"
                             role="tabpanel" aria-labelledby="settings-tab-justified">
                            @include('customer.contactGroups._settings')
                        </div>
                    @endcan


                    {{-- settings --}}
                    @can('update_contact_group')
                        {{-- message --}}
                        <div class="tab-pane {{ old('tab') == 'message' ? 'active':null }}" id="message" role="tabpanel"
                             aria-labelledby="message-tab-justified">
                            @include('customer.contactGroups._message')
                        </div>

                        {{--Version 3.9--}}
                        {{-- segments --}}
                        <div class="tab-pane {{ old('tab') == 'segments' ? 'active':null }}" id="segments"
                             role="tabpanel" aria-labelledby="segments-tab-justified">
                            @include('customer.contactGroups._segments')
                        </div>

                        {{-- Manage Fields --}}
                        <div class="tab-pane {{ old('tab') == 'fields' ? 'active':null }}" id="fields"
                             role="tabpanel" aria-labelledby="fields-tab-justified">
                            @include('customer.contactGroups._fields')
                        </div>
                        {{--End Version 3.9--}}


                        {{-- opt in keywords --}}
                        <div class="tab-pane {{ old('tab') == 'opt_in_keywords' ? 'active':null }}" id="opt_in_keywords"
                             role="tabpanel" aria-labelledby="opt_in_keywords-tab-justified">
                            @include('customer.contactGroups._opt_in_keywords')
                        </div>

                        {{-- opt in out keywords --}}
                        <div class="tab-pane {{ old('tab') == 'opt_out_keywords' ? 'active':null }}"
                             id="opt_out_keywords" role="tabpanel" aria-labelledby="opt_out_keywords-tab-justified">
                            @include('customer.contactGroups._opt_out_keywords')
                        </div>
                    @endcan

                    {{-- import history --}}
                    @can('create_contact_group')
                        <div class="tab-pane {{ old('tab') == 'import_history' ? 'active':null }}" id="import-history"
                             role="tabpanel" aria-labelledby="import-history-tab-justified">
                            @include('customer.contactGroups._import_history')
                        </div>
                    @endcan


                </div>
            </div>
        </div>
    </section>

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
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>

    <script src="{{ asset(mix('js/scripts/extensions/copy-to-clipboard.js')) }}"></script>

    <script src="{{ asset(mix('vendors/js/pickers/flatpickr/flatpickr.min.js')) }}"></script>
@endsection



@section('page-script')

    <script>
        $(document).ready(function () {

            $("#contact-tab-justified").on("click", function () {
                $($.fn.dataTable.tables(true)).DataTable()
                    .columns.adjust()
                    .responsive.recalc();
            });

            $(".sender_id").on("click", function () {
                $("#sender_id").prop("disabled", !this.checked);
                $("#phone_number").prop("disabled", this.checked);
            });

            $(".phone_number").on("click", function () {
                $("#phone_number").prop("disabled", !this.checked);
                $("#sender_id").prop("disabled", this.checked);
            });

            $(".datetime").flatpickr({
                enableTime: true,
                dateFormat: "Y-m-d H:i"
            });

            $(".date").flatpickr({
                enableTime: false,
                dateFormat: "Y-m-d"
            });


            // Basic Select2 select
            $(".select2").each(function () {
                let $this = $(this),
                    $placeholder = "{{ __('locale.labels.select_one') }}",
                    $allowClear = false;
                if ($this.prop("multiple")) {
                    $placeholder = "{{ __('locale.labels.select_one_or_multiple') }}";
                    $allowClear = true;
                }

                $this.wrap('<div class="position-relative"></div>')
                $this.select2({
                    // the following code is used to disable x-scrollbar when click in select input and
                    // take 100% width in responsive also
                    dropdownAutoWidth: true,
                    width: '100%',
                    dropdownParent: $this.parent(),
                    placeholder: $placeholder,
                    allowClear: $allowClear
                });
            });
            $('.select2-search__field').width('100%')

            let $get_msg = $("#text_message"),
                merge_state = $("#available_tag");

            merge_state.on("change", function () {
                const caretPos = $get_msg[0].selectionStart;
                const textAreaTxt = $get_msg.val();
                let txtToAdd = this.value;
                if (txtToAdd) {
                    txtToAdd = "{" + txtToAdd + "}";
                }

                $get_msg.val(textAreaTxt.substring(0, caretPos) + txtToAdd + textAreaTxt.substring(caretPos));
            });

            let showUnsubscribeURL = $(".show-unsubscribe-url");
            showUnsubscribeURL.hide();

            $("#message_form").on("change", function () {
                let smsForm = $(this).val(),
                    showSubscribeURL = $(".show-subscribe-url");

                if (smsForm === "signup_sms") {
                    showSubscribeURL.show();
                } else {
                    showSubscribeURL.hide();
                }

                if (smsForm === "unsubscribe_sms") {
                    showUnsubscribeURL.show();
                } else {
                    showUnsubscribeURL.hide();
                }


                $.ajax({
                    type: "POST",
                    url: "{{ route('customer.contacts.message_form', $contact->uid) }}",
                    data: {
                        _token: "{{csrf_token()}}",
                        sms_form: smsForm
                    },
                    cache: false,
                    success: function (data) {
                        $get_msg.val(data.message).val();
                    }
                });
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
                } else if (data.status === "error") {
                    toastr["warning"](data.message, '{{__('locale.labels.warning')}}!!', {
                        closeButton: true,
                        positionClass: "toast-top-right",
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    });
                } else {
                    toastr["warning"]("{{__('locale.exceptions.something_went_wrong')}}", '{{ __('locale.labels.warning') }}!', {
                        closeButton: true,
                        positionClass: "toast-top-right",
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    });
                }
            }


            let firstInvalid = $("form").find(".is-invalid").eq(0);

            if (firstInvalid.length) {
                $("body, html").stop(true, true).animate({
                    "scrollTop": firstInvalid.offset().top - 200 + "px"
                }, 200);
            }


            // init table dom
            let Table = $("table");

            // init list view datatable
            let dataListView = $(".datatables-basic").DataTable({

                "processing": true,
                "serverSide": true,
                "ajax": {
                    "url": "{{ route('customer.contact.search', $contact->uid) }}",
                    "dataType": "json",
                    "type": "POST",
                    "data": {_token: "{{csrf_token()}}"}
                },
                "columns": [
                    {"data": "responsive_id", orderable: false, searchable: false},
                    {"data": "uid"},
                    {"data": "uid"},
                    {"data": "phone"},
                    {"data": "updated_at", orderable: false, searchable: false},
                    {"data": "status"},
                        @foreach ($contact->getFields as $key => $field)
                        @if ($field->tag != "PHONE")
                    {
                        data: "{{ $field->tag }}"
                    },
                        @endif
                        @endforeach
                    {
                        "data": "action", orderable: false, searchable: false
                    }
                ],

                searchDelay: 1500,
                columnDefs: [
                    {
                        targets: 1,
                        className: 'noVis'
                    },
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
                        render: function (data) {
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
                        // Avatar image/badge, Name and post
                        targets: 3,
                        responsivePriority: 1,
                        render: function (data, type, full) {
                            return (
                                "<a href=\"" + full["show"] + "\">" + full["phone"] + "</a>"
                            );
                        }
                    },
                    {
                        // Actions
                        targets: -1,
                        title: '{{ __('locale.labels.actions') }}',
                        orderable: false,
                        render: function (data, type, full) {
                            let $actions = "<a href=\"" + full["conversion"] + "\" class=\"text-info me-1\" data-bs-toggle=\"tooltip\" data-bs-placement=\"top\" title=\"" + full["conversion_label"] + "\">" +
                                feather.icons["message-square"].toSvg({class: "font-medium-4"}) +
                                "</a>" +
                                "<a href=\"" + full["send_sms"] + "\" class=\"text-success me-1\" data-bs-toggle=\"tooltip\" data-bs-placement=\"top\" title=\"" + full["send_sms_label"] + "\">" +
                                feather.icons["send"].toSvg({class: "font-medium-4"}) +
                                "</a>" +
                                "<a href=\"" + full["show"] + "\" class=\"text-primary me-1\" data-bs-toggle=\"tooltip\" data-bs-placement=\"top\" title=\"" + full["show_label"] + "\">" +
                                feather.icons["edit"].toSvg({class: "font-medium-4"}) +
                                "</a>";


                            if (full['can_delete']) {
                                $actions += "<span class=\"action-delete text-danger cursor-pointer\" data-bs-toggle=\"tooltip\" data-bs-placement=\"top\" title=\"" + full["delete"] + "\"  data-id=" + full["uid"] + ">" +
                                    feather.icons["trash"].toSvg({class: "font-medium-4"}) +
                                    "</span>"
                            }
                            return (
                                $actions
                            );
                        }
                    }
                ],
                dom: "<\"d-flex justify-content-between align-items-center mx-0 row\"<\"col-sm-12 col-md-6\"l><\"col-sm-12 col-md-6\"f>>t<\"d-flex justify-content-between mx-0 row\"<\"col-sm-12 col-md-6\"i><\"col-sm-12 col-md-6\"p>>",
                layout: {
                    topStart: {
                        buttons: [
                            {
                                extend: 'colvis',
                                columns: ':not(.noVis)',
                                popoverTitle: 'Column visibility selector'
                            }
                        ]
                    }
                },
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
                            header: function (row) {
                                let data = row.data();
                                return "Details of " + data["uid"];
                            }
                        }),
                        type: "column",
                        renderer: function (api, rowIdx, columns) {
                            let data = $.map(columns, function (col) {
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


            $(".opt-in-keywords").DataTable({
                "processing": true,
                "columns": [
                    {"data": "keyword"},
                    {"data": "created_at", orderable: false, searchable: false},
                    {"data": "action", orderable: false, searchable: false}
                ],
                responsive: false,
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
                aLengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
                order: [[0, "desc"]],
                displayLength: 10
            });

            $(".opt-out-keywords").DataTable({
                "processing": true,
                "columns": [
                    {"data": "keyword"},
                    {"data": "created_at", orderable: false, searchable: false},
                    {"data": "action", orderable: false, searchable: false}
                ],

                responsive: false,
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
                aLengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
                order: [[0, "desc"]],
                displayLength: 10
            });

            //change status
            Table.delegate(".get_status", "click", function () {
                let contact_id = $(this).data("id");
                let url = "{{ route('customer.contact.status', [ 'contact' => $contact->uid, 'id' => "contact_id"]) }}";

                $.ajax({
                    url: url.replace("contact_id", contact_id),
                    type: "POST",
                    data: {
                        _token: "{{csrf_token()}}"
                    },
                    success: function (data) {
                        dataListView.draw(false);
                        showResponseMessage(data);
                    }
                });
            });

            // On Delete
            Table.delegate(".action-delete", "click", function (e) {
                e.stopPropagation();

                let contact_id = $(this).data("id");
                let url = "{{ route('customer.contact.delete', [ 'contact' => $contact->uid, 'id' => "contact_id"]) }}";

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
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: url.replace("contact_id", contact_id),
                            type: "POST",
                            data: {
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {
                                dataListView.draw(false);
                                showResponseMessage(data);
                            },
                            error: function (reject) {
                                if (reject.status === 422) {
                                    let errors = reject.responseJSON.errors;
                                    $.each(errors, function (key, value) {
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

            // On Delete Field
            $('.field-list').delegate(".action-delete-fields", "click", function (e) {
                e.stopPropagation();

                let field_id = $(this).data("field-id");

                let url = "{{ route('customer.contact.delete-contact-field', [ 'contact' => $contact->uid, 'field_id' => "field_id"]) }}";

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
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: url.replace("field_id", field_id),
                            type: "POST",
                            data: {
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {

                                $('.field-list tbody tr[data-remove-id="' + field_id + '"]').remove();

                                showResponseMessage(data);
                            },
                            error: function (reject) {
                                if (reject.status === 422) {
                                    let errors = reject.responseJSON.errors;
                                    $.each(errors, function (key, value) {
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

            //Bulk subscribe
            $(".bulk-subscribe").on("click", function (e) {
                e.preventDefault();

                Swal.fire({
                    title: "{{__('locale.labels.are_you_sure')}}",
                    text: "{{__('locale.contacts.subscribe_contacts')}}",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonText: "{{__('locale.labels.subscribe')}}",
                    customClass: {
                        confirmButton: "btn btn-primary",
                        cancelButton: "btn btn-outline-danger ms-1"
                    },
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.value) {
                        let contacts_ids = [];
                        let rows_selected = dataListView.column(1).checkboxes.selected();

                        $.each(rows_selected, function (index, rowId) {
                            contacts_ids.push(rowId);
                        });

                        if (contacts_ids.length > 0) {

                            $.ajax({
                                url: "{{ route('customer.contact.batch_action', $contact->uid) }}",
                                type: "POST",
                                data: {
                                    _token: "{{csrf_token()}}",
                                    action: "subscribe",
                                    ids: contacts_ids
                                },
                                success: function (data) {
                                    dataListView.draw(false);
                                    showResponseMessage(data);
                                },
                                error: function (reject) {
                                    if (reject.status === 422) {
                                        let errors = reject.responseJSON.errors;
                                        $.each(errors, function (key, value) {
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

            //bulk-unsubscribe
            $(".bulk-unsubscribe").on("click", function (e) {
                e.preventDefault();

                Swal.fire({
                    title: "{{__('locale.labels.are_you_sure')}}",
                    text: "{{__('locale.contacts.unsubscribe_contacts')}}",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonText: "{{__('locale.labels.unsubscribe')}}",
                    customClass: {
                        confirmButton: "btn btn-primary",
                        cancelButton: "btn btn-outline-danger ms-1"
                    },
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.value) {
                        let contacts_ids = [];
                        let rows_selected = dataListView.column(1).checkboxes.selected();

                        $.each(rows_selected, function (index, rowId) {
                            contacts_ids.push(rowId);
                        });

                        if (contacts_ids.length > 0) {

                            $.ajax({
                                url: "{{ route('customer.contact.batch_action', $contact->uid) }}",
                                type: "POST",
                                data: {
                                    _token: "{{csrf_token()}}",
                                    action: "unsubscribe",
                                    ids: contacts_ids
                                },
                                success: function (data) {
                                    dataListView.draw(false);
                                    showResponseMessage(data);
                                },
                                error: function (reject) {
                                    if (reject.status === 422) {
                                        let errors = reject.responseJSON.errors;
                                        $.each(errors, function (key, value) {
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

            //Bulk copy
            $(".bulk-copy").on("click", function (e) {
                e.preventDefault();

                let array = {!! \Illuminate\Support\Js::from($contact_groups) !!}, options;
                $.each(array, function (key, value) {
                    options = `${options}<option value="${value.uid}">${value.name}</option>`;
                });

                let html = `<select id="my-select2">${options}</select>`;

                Swal.fire({
                    title: "{{__('locale.labels.are_you_sure')}}",
                    html: html,
                    didOpen: function () {
                        $("#my-select2").select2({
                            dropdownAutoWidth: true,
                            width: "100%"
                        });
                    },
                    preConfirm: function () {
                        return $("#my-select2").val();
                    },

                    showCancelButton: true,
                    confirmButtonText: "{{ __('locale.labels.copy') }}",
                    customClass: {
                        confirmButton: "btn btn-primary",
                        cancelButton: "btn btn-outline-danger ms-1"
                    },
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.value) {
                        let contacts_ids = [];
                        let rows_selected = dataListView.column(1).checkboxes.selected();

                        $.each(rows_selected, function (index, rowId) {
                            contacts_ids.push(rowId);
                        });
                        if (contacts_ids.length > 0) {

                            $.ajax({
                                url: "{{ route('customer.contact.batch_action', $contact->uid) }}",
                                type: "POST",
                                data: {
                                    _method: "POST",
                                    action: "copy",
                                    ids: contacts_ids,
                                    target_group: result.value,
                                    _token: "{{csrf_token()}}"
                                },
                                success: function (data) {
                                    showResponseMessage(data);
                                },
                                error: function (reject) {
                                    if (reject.status === 422) {
                                        let errors = reject.responseJSON.errors;
                                        $.each(errors, function (key, value) {
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

            //Bulk move
            $(".bulk-move").on("click", function (e) {
                e.preventDefault();

                let array = {!! \Illuminate\Support\Js::from($contact_groups) !!}, options;
                $.each(array, function (key, value) {
                    options = `${options}<option value="${value.uid}">${value.name}</option>`;
                });

                let html = `<select id="my-select2">${options}</select>`;

                Swal.fire({
                    title: "{{__('locale.labels.are_you_sure')}}",
                    html: html,
                    didOpen: function () {
                        $("#my-select2").select2({
                            dropdownAutoWidth: true,
                            width: "100%"
                        });
                    },
                    preConfirm: function () {
                        return $("#my-select2").val();
                    },

                    showCancelButton: true,
                    confirmButtonText: "{{ __('locale.labels.move') }}",
                    customClass: {
                        confirmButton: "btn btn-primary",
                        cancelButton: "btn btn-outline-danger ms-1"
                    },
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.value) {
                        let contacts_ids = [];
                        let rows_selected = dataListView.column(1).checkboxes.selected();

                        $.each(rows_selected, function (index, rowId) {
                            contacts_ids.push(rowId);
                        });
                        if (contacts_ids.length > 0) {

                            $.ajax({
                                url: "{{ route('customer.contact.batch_action', $contact->uid) }}",
                                type: "POST",
                                data: {
                                    _method: "POST",
                                    action: "move",
                                    ids: contacts_ids,
                                    target_group: result.value,
                                    _token: "{{csrf_token()}}"
                                },
                                success: function (data) {
                                    dataListView.draw(false);
                                    showResponseMessage(data);
                                },
                                error: function (reject) {
                                    if (reject.status === 422) {
                                        let errors = reject.responseJSON.errors;
                                        $.each(errors, function (key, value) {
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

            //Bulk Delete
            $(".bulk-delete").on("click", function (e) {

                e.preventDefault();

                Swal.fire({
                    title: "{{__('locale.labels.are_you_sure')}}",
                    text: "{{__('locale.contacts.delete_contacts')}}",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonText: "{{__('locale.labels.delete_selected')}}",
                    customClass: {
                        confirmButton: "btn btn-primary",
                        cancelButton: "btn btn-outline-danger ms-1"
                    },
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.value) {
                        let contacts_ids = [];
                        let rows_selected = dataListView.column(1).checkboxes.selected();

                        $.each(rows_selected, function (index, rowId) {
                            contacts_ids.push(rowId);
                        });

                        if (contacts_ids.length > 0) {

                            $.ajax({
                                url: "{{ route('customer.contact.batch_action', $contact->uid) }}",
                                type: "POST",
                                data: {
                                    _token: "{{csrf_token()}}",
                                    action: "destroy",
                                    ids: contacts_ids
                                },
                                success: function (data) {
                                    dataListView.draw(false);
                                    showResponseMessage(data);
                                },
                                error: function (reject) {
                                    if (reject.status === 422) {
                                        let errors = reject.responseJSON.errors;
                                        $.each(errors, function (key, value) {
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

            //add opt in keyword
            $(".add_opt_in_keyword").on("click", function (e) {
                e.preventDefault();

                let remainOptinKeywords = {!! \Illuminate\Support\Js::from($remain_opt_in_keywords) !!}, options;
                $.each(remainOptinKeywords, function (key, value) {
                    options = `${options}<option value="${value.keyword_name}">${value.keyword_name}</option>`;
                });

                let html = `<select id="opt-in-select2">${options}</select>`;

                Swal.fire({
                    title: "{{__('locale.labels.are_you_sure')}}",
                    html: html,
                    didOpen: function () {
                        $("#opt-in-select2").select2({
                            width: "100%"
                        });
                    },
                    preConfirm: function () {
                        return $("#opt-in-select2").val();
                    },

                    showCancelButton: true,
                    confirmButtonText: "{{ __('locale.labels.yes') }}",
                    customClass: {
                        confirmButton: "btn btn-primary",
                        cancelButton: "btn btn-outline-danger ms-1"
                    },
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: "{{ route('customer.contacts.optin_keyword', $contact->uid) }}",
                            type: "POST",
                            data: {
                                _method: "POST",
                                keyword_name: result.value,
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {
                                showResponseMessage(data);
                                setTimeout(function () {
                                    window.location.reload();
                                }, 3000);
                            },

                            error: function (reject) {
                                if (reject.status === 422) {
                                    let errors = reject.responseJSON.errors;
                                    $.each(errors, function (key, value) {
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

            //add opt out keyword
            $(".add_opt_out_keyword").on("click", function (e) {
                e.preventDefault();

                let remainOptOutKeywords = {!! \Illuminate\Support\Js::from($remain_opt_out_keywords) !!}, options;
                $.each(remainOptOutKeywords, function (key, value) {
                    options = `${options}<option value="${value.keyword_name}">${value.keyword_name}</option>`;
                });

                let html = `<select id="opt-out-select2">${options}</select>`;

                Swal.fire({
                    title: "{{__('locale.labels.are_you_sure')}}",
                    html: html,
                    didOpen: function () {
                        $("#opt-out-select2").select2({
                            width: "100%"
                        });
                    },
                    preConfirm: function () {
                        return $("#opt-out-select2").val();
                    },

                    showCancelButton: true,
                    confirmButtonText: "{{ __('locale.labels.yes') }}",
                    customClass: {
                        confirmButton: "btn btn-primary",
                        cancelButton: "btn btn-outline-danger ms-1"
                    },
                    buttonsStyling: false
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: "{{ route('customer.contacts.optout_keyword', $contact->uid) }}",
                            type: "POST",
                            data: {
                                _method: "POST",
                                keyword_name: result.value,
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {
                                showResponseMessage(data);
                                setTimeout(function () {
                                    window.location.reload();
                                }, 3000);
                            },

                            error: function (reject) {
                                if (reject.status === 422) {
                                    let errors = reject.responseJSON.errors;
                                    $.each(errors, function (key, value) {
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
            Table.delegate(".action-delete-optin-keyword", "click", function (e) {

                e.stopPropagation();

                let keyword_id = $(this).data("id");
                let url = "{{ route('customer.contacts.delete_optin_keyword', [ 'contact' => $contact->uid, 'id' => "keyword_id"]) }}";

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
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: url.replace("keyword_id", keyword_id),
                            type: "POST",
                            data: {
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {
                                showResponseMessage(data);
                                setTimeout(function () {
                                    window.location.reload();
                                }, 3000);
                            },

                            error: function (reject) {
                                if (reject.status === 422) {
                                    let errors = reject.responseJSON.errors;
                                    $.each(errors, function (key, value) {
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
            Table.delegate(".action-delete-optout-keyword", "click", function (e) {

                e.stopPropagation();

                let keyword_id = $(this).data("id");
                let url = "{{ route('customer.contacts.delete_optout_keyword', [ 'contact' => $contact->uid, 'id' => "keyword_id"]) }}";

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
                }).then(function (result) {
                    if (result.value) {
                        $.ajax({
                            url: url.replace("keyword_id", keyword_id),
                            type: "POST",
                            data: {
                                _token: "{{csrf_token()}}"
                            },
                            success: function (data) {
                                showResponseMessage(data);
                                setTimeout(function () {
                                    window.location.reload();
                                }, 3000);
                            },

                            error: function (reject) {
                                if (reject.status === 422) {
                                    let errors = reject.responseJSON.errors;
                                    $.each(errors, function (key, value) {
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

        $(document).on("click", ".add-custom-field-button", function (e) {
            e.preventDefault();
            let type_name = $(this).attr("type_name");
            let sample_url = $(this).attr("sample-url");

            // ajax update custom sort
            $.ajax({
                method: "GET",
                url: sample_url,
                data: {
                    type: type_name
                }
            })
                .done(function (msg) {
                    let index = $(".field-list tr").length;

                    msg = msg.replace(/__index__/g, index);
                    msg = msg.replace(/__type__/g, type_name);

                    $(".field-list").append($("<div>").html(msg).find("table tbody").html());

                    feather.replace();
                    $(".datetime").flatpickr({
                        enableTime: true,
                        dateFormat: "Y-m-d H:i"
                    });

                    $(".date").flatpickr({
                        enableTime: false,
                        dateFormat: "Y-m-d"
                    });
                });
        });

        $(document).on("click", ".remove-not-saved-field", function (e) {

            e.preventDefault();

            $("tr[parent=\"" + $(this).parents("tr").attr("rel") + "\"]").remove();
            $(this).parents("tr").remove();
        });

        let includePhone = $('#include_phone'),
            contactFields = $('#contact_fields'),
            selectAllButtons = $('#select-all-btn');

        // Update tag while typing label
        $(document).on('input', 'input[name^="fields"][name$="[label]"]', function () {
            const labelValue = $(this).val().toUpperCase();
            const namePrefix = $(this).attr('name').replace('[label]', '');
            $('input[name="' + namePrefix + '[tag]"]').val(labelValue);
        });

        $('#export-contact').on('click', function () {
            $('#exportContactModal').modal("show");
        });

        togglePhoneOption();

        includePhone.on('change', togglePhoneOption);

        selectAllButtons.on('click', function () {
            toggleSelectAll(contactFields, selectAllButtons)
        });

        contactFields.on('change', function () {
            let selectedValues = contactFields.val();
            selectAllButtons.html('<i data-feather="check-square"></i> {{__('locale.labels.select_all')}}');

            if (contactFields.find('option:selected').length === contactFields.find('option').length) {
                selectAllButtons.html('<i data-feather="square"></i> {{__('locale.labels.unselect_all')}}');
            }
            feather.replace();

            if (selectedValues.includes('PHONE')) {
                includePhone.prop('checked', true)
            } else {
                includePhone.prop('checked', false)
            }
        });

        function toggleSelectAll(select2Element, buttonElement) {
            // Check if all options are selected
            const allSelected = $(select2Element).find('option:selected').length === $(select2Element).find('option').length;
            if (allSelected) {
                // Unselect all options
                $(select2Element).val([]);

                // uncheck 'include phone' checkbox
                includePhone.prop('checked', false)
                $(buttonElement).html('<i data-feather="check-square"></i> {{__('locale.labels.select_all')}}');
                feather.replace();
            } else {
                // Select all options
                togglePhoneOption()
                $(select2Element).val($(select2Element).find('option').map(function () {
                    return this.value;
                }).get());

                // check 'include phone' checkbox
                includePhone.prop('checked', true)
                $(buttonElement).html('<i data-feather="square"></i> {{__('locale.labels.unselect_all')}}');
                feather.replace();
            }
            // Trigger change event to update Select2
            $(select2Element).trigger('change');
        }

        function togglePhoneOption() {
            if (includePhone.is(':checked')) {
                contactFields.val(function (i, values) {
                    // Add "PHONE" to the selected values if it's not already selected
                    if (!values.includes('PHONE')) {
                        values.push('PHONE');
                    }
                    return values;
                }).trigger('change');
            } else {
                contactFields.val(function (i, values) {
                    // Remove "PHONE" from the selected values if the checkbox is unchecked
                    return values.filter(function (value) {
                        return value !== 'PHONE';
                    });
                }).trigger('change');
            }
        }

    </script>
@endsection
