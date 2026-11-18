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
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/crm/mgx-contacts.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('css/crm/mgx-group-detail.css') }}?v=1">

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

    @php
        $gdTab = old('tab');
        $gdKeywordsActive = in_array($gdTab, ['opt_in_keywords', 'opt_out_keywords', 'keywords'], true);
        $gdCanContacts = auth()->user()->can('view_contact');
        $gdCanManage = auth()->user()->can('update_contact_group');
        $gdCanImport = auth()->user()->can('create_contact_group');
        // The first tab the actor may see is the landing tab, as before.
        $gdFirst = $gdCanContacts ? 'contact' : ($gdCanManage ? 'settings' : ($gdCanImport ? 'import_history' : null));
        $gdActive = $gdTab === null || $gdTab === '' ? $gdFirst : ($gdKeywordsActive ? 'keywords' : $gdTab);
    @endphp

    <section id="nav-justified" class="mgx-contacts mgx-gd">
        @include('customer.contactGroups._group_header')

        {{-- Tab panes --}}
        <div class="tab-content mgx-gd-panes">

            {{-- contacts --}}
            @can('view_contact')
                <div class="tab-pane @if ($gdActive === 'contact') active @endif"
                     id="contact" role="tabpanel" aria-labelledby="contact-tab-justified">
                    @include('customer.contactGroups._contacts')
                </div>
            @endcan

            @can('update_contact_group')
                {{-- message --}}
                <div class="tab-pane @if ($gdActive === 'message') active @endif" id="message" role="tabpanel"
                     aria-labelledby="message-tab-justified">
                    @include('customer.contactGroups._message')
                </div>

                {{-- manage fields --}}
                <div class="tab-pane @if ($gdActive === 'fields') active @endif" id="fields"
                     role="tabpanel" aria-labelledby="fields-tab-justified">
                    @include('customer.contactGroups._fields')
                </div>

                {{-- keywords: join and leave share one tab --}}
                <div class="tab-pane @if ($gdActive === 'keywords') active @endif" id="keywords"
                     role="tabpanel" aria-labelledby="keywords-tab-justified">
                    <div data-kw-pane="opt_in_keywords" @if ($gdTab === 'opt_out_keywords') hidden @endif>
                        @include('customer.contactGroups._opt_in_keywords')
                    </div>
                    <div data-kw-pane="opt_out_keywords" @if ($gdTab !== 'opt_out_keywords') hidden @endif>
                        @include('customer.contactGroups._opt_out_keywords')
                    </div>
                </div>

                {{-- settings --}}
                <div class="tab-pane @if ($gdActive === 'settings') active @endif" id="settings"
                     role="tabpanel" aria-labelledby="settings-tab-justified">
                    @include('customer.contactGroups._settings')
                </div>
            @endcan

            {{-- import history --}}
            @can('create_contact_group')
                <div class="tab-pane @if ($gdActive === 'import_history') active @endif" id="import-history"
                     role="tabpanel" aria-labelledby="import-history-tab-justified">
                    @include('customer.contactGroups._import_history')
                </div>
            @endcan

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
                    url: "{{ \App\Library\CrmRouting::route('contacts.message_form', $contact->uid) }}",
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
                    "url": "{{ \App\Library\CrmRouting::route('contact.search', $contact->uid) }}",
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
                let url = "{{ \App\Library\CrmRouting::route('contact.status', [ 'contact' => $contact->uid, 'id' => "contact_id"]) }}";

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
                let url = "{{ \App\Library\CrmRouting::route('contact.delete', [ 'contact' => $contact->uid, 'id' => "contact_id"]) }}";

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

                let url = "{{ \App\Library\CrmRouting::route('contact.delete-contact-field', [ 'contact' => $contact->uid, 'field_id' => "field_id"]) }}";

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
                                url: "{{ \App\Library\CrmRouting::route('contact.batch_action', $contact->uid) }}",
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
                                url: "{{ \App\Library\CrmRouting::route('contact.batch_action', $contact->uid) }}",
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
                                url: "{{ \App\Library\CrmRouting::route('contact.batch_action', $contact->uid) }}",
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
                                url: "{{ \App\Library\CrmRouting::route('contact.batch_action', $contact->uid) }}",
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
                                url: "{{ \App\Library\CrmRouting::route('contact.batch_action', $contact->uid) }}",
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
                            url: "{{ \App\Library\CrmRouting::route('contacts.optin_keyword', $contact->uid) }}",
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
                            url: "{{ \App\Library\CrmRouting::route('contacts.optout_keyword', $contact->uid) }}",
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
                let url = "{{ \App\Library\CrmRouting::route('contacts.delete_optin_keyword', [ 'contact' => $contact->uid, 'id' => "keyword_id"]) }}";

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
                let url = "{{ \App\Library\CrmRouting::route('contacts.delete_optout_keyword', [ 'contact' => $contact->uid, 'id' => "keyword_id"]) }}";

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
            selectAllButtons.html(feather.icons['check-square'].toSvg() + ' ' + @json(__('locale.labels.select_all')));

            if (contactFields.find('option:selected').length === contactFields.find('option').length) {
                selectAllButtons.html(feather.icons['square'].toSvg() + ' ' + @json(__('locale.labels.unselect_all')));
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
                $(buttonElement).html(feather.icons['check-square'].toSvg() + ' ' + @json(__('locale.labels.select_all')));
                feather.replace();
            } else {
                // Select all options
                togglePhoneOption()
                $(select2Element).val($(select2Element).find('option').map(function () {
                    return this.value;
                }).get());

                // check 'include phone' checkbox
                includePhone.prop('checked', true)
                $(buttonElement).html(feather.icons['square'].toSvg() + ' ' + @json(__('locale.labels.unselect_all')));
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

    {{-- Group Details presentation behaviour: every control below drives an existing route or form. --}}
    <script>
        (function () {
            'use strict';
            var root = document.getElementById('nav-justified');
            if (!root) { return; }

            // Links such as "Edit the message" open the matching tab.
            root.addEventListener('click', function (event) {
                var go = event.target.closest('[data-goto-tab]');
                if (!go) { return; }
                event.preventDefault();
                var tab = document.getElementById(go.getAttribute('data-goto-tab').replace(/_/g, '-') + '-tab-justified');
                if (tab) { tab.click(); }
            });

            // Join / Leave keywords share one tab.
            root.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-kw-show]');
                if (!btn) { return; }
                var want = btn.getAttribute('data-kw-show');
                root.querySelectorAll('[data-kw-pane]').forEach(function (pane) {
                    pane.hidden = pane.getAttribute('data-kw-pane') !== want;
                });
            });

            // Copy the sign-up / leave links.
            root.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-copy-target]');
                if (!btn) { return; }
                var input = root.querySelector(btn.getAttribute('data-copy-target'));
                if (!input) { return; }
                var done = function () {
                    var label = btn.querySelector('[data-copy-label]');
                    if (!label) { return; }
                    var original = label.textContent;
                    label.textContent = 'Copied';
                    setTimeout(function () { label.textContent = original; }, 1600);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).then(done, function () { input.select(); document.execCommand('copy'); done(); });
                } else {
                    input.select(); document.execCommand('copy'); done();
                }
            });

            // Message editor: insert existing personalisation tags and show the SMS length.
            var message = document.getElementById('text_message');
            var counter = root.querySelector('[data-role="message-count"]');
            // Mirror SMSCounter::count(): GSM extension chars consume two septets,
            // while non-GSM text uses UTF-16 code units (including surrogate pairs).
            var gsmBasic = /^[\n\f\r\x20-\x5A_\x61-\x7A¡£¤¥§¿ÄÅÆÇÉÑÖØÜßàäåæèéìñòöøùüΓΔΘΛΞΠΣΦΨΩ]$/u;
            var gsmExtension = /^[\f\[\]\\^{}|~€]$/u;
            function updateCount() {
                if (!message || !counter) { return; }
                var value = message.value, gsmUnits = 0, unicode = false;
                for (var char of value) {
                    if (gsmExtension.test(char)) { gsmUnits += 2; }
                    else if (gsmBasic.test(char)) { gsmUnits++; }
                    else { unicode = true; }
                }
                var length = unicode ? value.length : gsmUnits;
                var single = unicode ? 70 : 160, multi = unicode ? 67 : 153;
                var parts = length === 0 ? 0 : (length <= single ? 1 : Math.ceil(length / multi));
                counter.textContent = length + ' / ' + (parts <= 1 ? single : parts * multi) + ' · ' + parts + (parts === 1 ? ' text' : ' texts');
            }
            if (message) {
                message.addEventListener('input', updateCount);
                if (window.jQuery) { window.jQuery(document).on('ajaxComplete', updateCount); }
                updateCount();
            }
            root.addEventListener('click', function (event) {
                var chip = event.target.closest('[data-insert-tag]');
                if (!chip || !message) { return; }
                var at = message.selectionStart === undefined ? message.value.length : message.selectionStart;
                var end = message.selectionEnd === undefined ? at : message.selectionEnd;
                var tag = '{' + chip.getAttribute('data-insert-tag') + '}';
                message.value = message.value.substring(0, at) + tag + message.value.substring(end);
                message.focus();
                message.setSelectionRange(at + tag.length, at + tag.length);
                updateCount();
            });

            // Import template: the group's own field labels as a CSV header row.
            root.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-role="imports-template"]');
                if (!btn) { return; }
                var headers = [];
                try { headers = JSON.parse(btn.getAttribute('data-template-headers') || '[]'); } catch (e) { headers = []; }
                var csv = headers.map(function (h) { return '"' + String(h).replace(/"/g, '""') + '"'; }).join(',') + '\r\n';
                var link = document.createElement('a');
                link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
                link.download = btn.getAttribute('data-template-name') || 'contacts-template.csv';
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
            });

            // Delete the group: always behind a confirmation, via the existing destroy route.
            root.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-role="group-delete"]');
                if (!btn) { return; }
                Swal.fire({
                    title: @json(__('locale.labels.are_you_sure')),
                    text: @json(__('locale.labels.able_to_revert')),
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: @json(__('locale.labels.delete_it')),
                    customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-outline-danger ms-1' },
                    buttonsStyling: false
                }).then(function (result) {
                    if (!result.value) { return; }
                    var body = new FormData();
                    body.append('_token', @json(csrf_token()));
                    body.append('_method', 'DELETE');
                    fetch(btn.getAttribute('data-delete-url'), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.status === 'success') { window.location.href = btn.getAttribute('data-back-url'); return; }
                            toastr.warning(data.message || @json(__('locale.exceptions.something_went_wrong')));
                        })
                        .catch(function () { toastr.warning(@json(__('locale.exceptions.something_went_wrong'))); });
                });
            });
        })();
    </script>

@endsection
