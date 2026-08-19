/*=========================================================================================
    File Name: theme-settings.js
    Description: Design System M2 Slice 1 contract §9 item 35. Preset picker wiring,
    Simple/Advanced toggle (native Bootstrap tabs), optimistic client-side preview
    (direct base-token values only — no contrast/derivation math duplicated here,
    §6.7/§6.12), debounced server-side contrast-check calls, unsaved-change guard,
    preset switching/create/duplicate/rename/delete-confirm wiring.
==========================================================================================*/
$(document).ready(function () {
    "use strict";

    var $section = $("#admin-theme-settings-index");
    var $form = $("#theme-preset-form");
    var csrfToken = $('meta[name="csrf-token"]').attr("content");
    var baseUrl = window.location.origin + window.location.pathname.replace(/\/theme-presets.*$/, "").replace(/\/?$/, "");
    var presetsIndexUrl = window.location.pathname;
    var currentPreset = null;
    var isDirty = false;
    var validateDebounceTimer = null;

    var HEX_FIELDS = [
        "primary", "secondary", "canvas", "sidebar", "surface", "surface_secondary",
        "input", "border", "header",
        "status_success", "status_warning", "status_danger", "status_info", "status_pending",
        "chart_1", "chart_2", "chart_3", "chart_4", "chart_5", "chart_6", "chart_7", "chart_8", "chart_neutral",
    ];

    function ajaxHeaders() {
        return { "X-CSRF-TOKEN": csrfToken };
    }

    function presetUrl(uid, suffix) {
        return presetsIndexUrl.replace(/\/$/, "") + "/" + uid + (suffix ? "/" + suffix : "");
    }

    function markDirty() {
        isDirty = true;
    }

    function markClean() {
        isDirty = false;
    }

    window.addEventListener("beforeunload", function (e) {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = "";
        }
    });

    function setField(name, value) {
        var $field = $form.find('[name="' + name + '"]');
        if ($field.length) {
            $field.val(value === null || value === undefined ? "" : value);
        }
    }

    function populateForm(preset) {
        currentPreset = preset;
        markClean();

        $("#theme-preset-uid").val(preset.uid);
        $("#theme-preset-name-heading").text(preset.name);

        var badgeHtml = "";
        if (preset.is_factory) {
            badgeHtml += '<span class="badge rounded-pill bg-light-primary text-primary me-1">Factory</span>';
        }
        if (preset.status === "active") {
            badgeHtml += '<span class="badge rounded-pill bg-light-success text-success">Active</span>';
        } else if (preset.status === "saved") {
            badgeHtml += '<span class="badge rounded-pill bg-light-secondary text-secondary">Saved</span>';
        } else {
            badgeHtml += '<span class="badge rounded-pill bg-light-warning text-warning">Draft</span>';
        }
        $("#theme-preset-status-badge").html(badgeHtml);

        var auditParts = [];
        if (preset.updated_at) {
            auditParts.push("Last updated " + preset.updated_at);
        } else if (preset.created_at) {
            auditParts.push("Created " + preset.created_at);
        }
        $("#theme-preset-audit-info").text(auditParts.join(" — "));

        var tokens = preset.input_tokens || {};
        HEX_FIELDS.forEach(function (field) {
            if (field === "header") {
                return;
            }
            if (tokens[field]) {
                setField(field, tokens[field]);
            }
        });

        if (tokens.header) {
            setField("header_mode", "custom");
            setField("header", tokens.header);
        } else {
            setField("header_mode", "follow");
        }

        setField("font_choice", preset.font_choice || "bundled");
        setField("font_bundled_key", preset.font_bundled_key || "geist");
        setField("font_uploaded_id", preset.font_uploaded_id || "");

        var $tbody = $("#theme-override-table-body");
        $tbody.empty();
        var overrides = preset.overrides || {};
        Object.keys(overrides).forEach(function (key) {
            addOverrideRow(key, overrides[key]);
        });

        var isLocked = preset.is_factory || preset.status === "active";
        $("#theme-preset-readonly-notice").toggleClass("d-none", !isLocked);
        $form.find("input, select").not("#theme-preset-uid").prop("disabled", isLocked);
        $("#theme-preset-save-btn").prop("disabled", isLocked);
        $("#theme-preset-delete-btn").prop("disabled", preset.is_factory || preset.status === "active");
        $("#theme-preset-activate-btn").prop("disabled", preset.status !== "saved");

        // Field-specific show/hide + disabled state applied last so it
        // always wins over the blanket isLocked pass above.
        syncHeaderField();
        toggleFontFields();
        if (isLocked) {
            $("#theme-header-custom-field").find("input, select").prop("disabled", true);
            $("#theme-font-bundled-field, #theme-font-uploaded-field").find("input, select").prop("disabled", true);
        }

        $(".theme-preset-list-item").removeClass("active");
        $('.theme-preset-list-item[data-preset-uid="' + preset.uid + '"]').addClass("active");

        $("#theme-preset-rename-form").attr("action", presetUrl(preset.uid, "rename"));
        $("#theme-preset-duplicate-form").attr("action", presetUrl(preset.uid, "duplicate"));
        $("#theme-preset-activate-form").attr("action", presetUrl(preset.uid, "activate"));
        $("#theme-preset-delete-form").attr("action", presetUrl(preset.uid));
        $form.attr("action", presetUrl(preset.uid));

        $("#theme-contrast-feedback").empty();
        updatePreview();
    }

    function syncHeaderField() {
        var isCustom = $form.find('[name="header_mode"]').val() === "custom";
        $("#theme-header-custom-field").toggleClass("d-none", !isCustom);
        $form.find('[name="header"]').prop("disabled", !isCustom);
    }

    function toggleFontFields() {
        var choice = $form.find('[name="font_choice"]').val();
        $("#theme-font-bundled-field").toggleClass("d-none", choice !== "bundled");
        $("#theme-font-uploaded-field").toggleClass("d-none", choice !== "uploaded");
        $form.find('[name="font_bundled_key"]').prop("disabled", choice !== "bundled");
        $form.find('[name="font_uploaded_id"]').prop("disabled", choice !== "uploaded");
    }

    function addOverrideRow(key, value) {
        var $tbody = $("#theme-override-table-body");
        var rowId = "override-row-" + key.replace(/[^a-z0-9-]/gi, "");
        var $row = $(
            '<tr id="' + rowId + '">' +
            '<td><code>' + key + '</code><input type="hidden" name="overrides[' + key + ']" value="' + value + '"></td>' +
            '<td><input type="color" value="' + value + '" data-override-key="' + key + '" class="theme-override-value-field"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger theme-override-remove-btn">Remove</button></td>' +
            "</tr>"
        );
        $tbody.append($row);
    }

    $("#theme-override-add-btn").on("click", function () {
        var key = $("#theme-override-key-select").val();
        var value = $("#theme-override-value-input").val();
        if (!key) {
            return;
        }
        if ($('#theme-override-table-body input[name="overrides[' + key + ']"]').length) {
            return;
        }
        addOverrideRow(key, value);
        markDirty();
        updatePreview();
        scheduleValidate();
    });

    $("#theme-override-table-body").on("click", ".theme-override-remove-btn", function () {
        $(this).closest("tr").remove();
        markDirty();
        updatePreview();
        scheduleValidate();
    });

    $("#theme-override-table-body").on("input", ".theme-override-value-field", function () {
        var key = $(this).data("override-key");
        $('input[name="overrides[' + key + ']"]').val($(this).val());
        markDirty();
        updatePreview();
        scheduleValidate();
    });

    $form.on("change", '[name="header_mode"]', syncHeaderField);

    $form.on("change", '[name="font_choice"]', toggleFontFields);

    $form.on("input change", "input, select", function () {
        markDirty();
        updatePreview();
        scheduleValidate();
    });

    function currentOverrides() {
        var overrides = {};
        $("#theme-override-table-body tr").each(function () {
            var $hidden = $(this).find('input[type="hidden"]');
            var name = $hidden.attr("name") || "";
            var match = name.match(/^overrides\[(.+)\]$/);
            if (match) {
                overrides[match[1]] = $hidden.val();
            }
        });
        return overrides;
    }

    /**
     * Direct base-token application only — hover/active/soft-bg variants
     * and contrast-based foreground selection are never recomputed here
     * (§6.7/§6.12: that math stays server-side, single source of truth).
     */
    function updatePreview() {
        var v = function (name) {
            return $form.find('[name="' + name + '"]').val();
        };
        var overrides = currentOverrides();

        var primary = overrides["color-primary"] || v("primary");
        var secondary = overrides["color-secondary"] || v("secondary");
        var surfaceSecondary = overrides["color-chat-bubble-in"] || v("surface_secondary");
        var chatOut = overrides["color-chat-bubble-out"] || primary;

        $(".ds-preview-btn-primary").css({ "background-color": primary, "border-color": primary, color: "#fff" });
        $(".ds-preview-btn-secondary").css({ "background-color": secondary, "border-color": secondary, color: "#fff" });
        $("#theme-preview").css("background-color", v("canvas"));

        ["success", "warning", "danger", "info", "pending"].forEach(function (status) {
            var color = overrides["color-status-" + status] || v("status_" + status);
            $('.ds-preview-status[data-status="' + status + '"]').css({ "background-color": color, color: "#262522" });
        });

        for (var i = 1; i <= 8; i++) {
            var chartColor = overrides["color-chart-" + i] || v("chart_" + i);
            $('.ds-preview-chart-bar[data-chart="' + i + '"]').css("background-color", chartColor);
        }

        $(".ds-preview-chat-bubble-in").css({ "background-color": surfaceSecondary });
        $(".ds-preview-chat-bubble-out").css({ "background-color": chatOut, color: "#fff" });
    }

    function scheduleValidate() {
        clearTimeout(validateDebounceTimer);
        validateDebounceTimer = setTimeout(runValidate, 500);
    }

    function runValidate() {
        var payload = $form.serializeArray().reduce(function (acc, field) {
            if (field.name.indexOf("overrides[") === 0 || field.name === "_token" || field.name === "_method") {
                return acc;
            }
            acc[field.name] = field.value;
            return acc;
        }, {});
        payload.overrides = currentOverrides();

        $.ajax({
            url: baseUrl + "/theme-presets/validate",
            method: "POST",
            headers: ajaxHeaders(),
            data: payload,
            success: function (result) {
                renderFeedback(result.errors || [], result.warnings || []);
            },
        });
    }

    function renderFeedback(errors, warnings) {
        var $feedback = $("#theme-contrast-feedback");
        $feedback.empty();
        if (errors.length) {
            var $errBox = $('<div class="alert alert-danger"><strong>Fix before saving:</strong><ul class="mb-0"></ul></div>');
            errors.forEach(function (e) { $errBox.find("ul").append("<li></li>").children().last().text(e); });
            $feedback.append($errBox);
        }
        if (warnings.length) {
            var $warnBox = $('<div class="alert alert-warning"><strong>Consider reviewing:</strong><ul class="mb-0"></ul></div>');
            warnings.forEach(function (w) { $warnBox.find("ul").append("<li></li>").children().last().text(w); });
            $feedback.append($warnBox);
        }
    }

    function loadPreset(uid) {
        $.ajax({
            url: presetUrl(uid),
            method: "GET",
            headers: ajaxHeaders(),
            success: function (preset) {
                populateForm(preset);
                var url = new URL(window.location.href);
                url.searchParams.set("preset", uid);
                window.history.replaceState({}, "", url);
            },
        });
    }

    $(document).on("click", ".theme-preset-list-item", function () {
        if (isDirty && !window.confirm("Discard unsaved changes?")) {
            return;
        }
        loadPreset($(this).data("preset-uid"));
    });

    $("#theme-preset-cancel-btn").on("click", function () {
        if (currentPreset) {
            loadPreset(currentPreset.uid);
        }
    });

    $("#theme-preset-duplicate-btn").on("click", function () {
        markClean();
        $("#theme-preset-duplicate-form").trigger("submit");
    });

    $("#theme-preset-activate-btn").on("click", function () {
        markClean();
        $("#theme-preset-activate-form").trigger("submit");
    });

    $("#theme-preset-delete-btn").on("click", function () {
        if (window.confirm("Delete this preset? This cannot be undone.")) {
            markClean();
            $("#theme-preset-delete-form").trigger("submit");
        }
    });

    $("#theme-preset-rename-modal").on("show.bs.modal", function () {
        $(this).find('[name="name"]').val(currentPreset ? currentPreset.name : "");
    });

    $("#theme-preset-rename-form").on("submit", function () {
        markClean();
    });

    $form.on("submit", function () {
        markClean();
    });

    // Initial load: explicit ?preset= param, else the active preset, else the first in the list.
    var initialUid =
        new URL(window.location.href).searchParams.get("preset") ||
        $section.data("active-preset-uid") ||
        $(".theme-preset-list-item").first().data("preset-uid");

    if (initialUid) {
        loadPreset(initialUid);
    }
});
