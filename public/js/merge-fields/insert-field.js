// Business OS — the ONE "Insert field" merge-field picker behaviour.
//
// Markup comes from resources/views/components/merge-field-picker.blade.php:
//   [data-merge-picker][data-default-target="<selector>"]   the picker
//   [data-merge-insert="{{contact.first_name}}"]            one canonical token
//   [data-merge-group][data-merge-requires]                 a group (may be hidden)
//   [data-merge-warning]                                    "Unknown merge field" note
//
// Clicking a token inserts it at the cursor of the field the person last used
// next to the picker (or the picker's default target). It works for markup that
// is added later (the Automations builder clones its forms from <template>s),
// because everything is delegated from `document`.
//
// The same script flags a `{{...}}` that is not in the picker's vocabulary — a
// typo, or a field the editor cannot supply — so people see "Unknown merge
// field" while editing. It is advice only; the server resolves tokens itself and
// renders anything unknown as blank, never as raw braces.
(function () {
    if (window.__mergeFieldPicker) {
        return
    }

    window.__mergeFieldPicker = true

    var TOKEN = /\{\{([^{}]{1,80})\}\}/g
    var lastFocused = new WeakMap()
    var TEXT_FIELD = 'textarea, input[type="text"], input:not([type])'

    function scopeOf(picker) {
        var target = picker.getAttribute('data-default-target')
        var node = picker.parentElement

        while (node && node !== document.body) {
            if (!target || node.querySelector(target)) {
                return node
            }

            node = node.parentElement
        }

        return picker.parentElement
    }

    function targetFor(picker) {
        var scope = scopeOf(picker)
        var remembered = lastFocused.get(scope)

        if (remembered && scope.contains(remembered)) {
            return remembered
        }

        var selector = picker.getAttribute('data-default-target')

        return selector ? scope.querySelector(selector) : scope.querySelector(TEXT_FIELD)
    }

    function knownTokens(picker) {
        var known = {}

        picker.querySelectorAll('[data-merge-insert]').forEach(function (item) {
            var group = item.closest('[data-merge-group]')

            if (!group || !group.hidden) {
                known[item.getAttribute('data-merge-insert')] = true
            }
        })

        try {
            JSON.parse(picker.getAttribute('data-extra') || '[]').forEach(function (token) {
                known[token] = true
            })
        } catch (error) {
            // Advice only: a malformed hint must never break the editor.
        }

        return known
    }

    function rescan(picker) {
        var warning = picker.parentElement && picker.parentElement.querySelector('[data-merge-warning]')
        var scope = scopeOf(picker)

        if (!warning) {
            return
        }

        var known = knownTokens(picker)
        var unknown = []

        scope.querySelectorAll(TEXT_FIELD).forEach(function (field) {
            var match

            TOKEN.lastIndex = 0

            while ((match = TOKEN.exec(field.value)) !== null) {
                var token = '{{' + match[1].trim() + '}}'

                if (!known[token] && unknown.indexOf(token) === -1) {
                    unknown.push(token)
                }
            }
        })

        warning.hidden = unknown.length === 0
        warning.textContent = unknown.length === 0 ? '' : 'Unknown merge field: ' + unknown.join(', ') + ' — it will be left blank.'
    }

    function rescanAll(root) {
        (root || document).querySelectorAll('[data-merge-picker]').forEach(rescan)
    }

    document.addEventListener('focusin', function (event) {
        var field = event.target

        if (!(field instanceof Element) || !field.matches(TEXT_FIELD)) {
            return
        }

        var picker = field.closest('form, [data-merge-scope]') && field.closest('form, [data-merge-scope]').querySelector('[data-merge-picker]')

        if (picker) {
            lastFocused.set(scopeOf(picker), field)
        }
    })

    document.addEventListener('input', function (event) {
        var field = event.target

        if (field instanceof Element && field.matches(TEXT_FIELD)) {
            var holder = field.closest('form, [data-merge-scope]')

            if (holder) {
                rescanAll(holder)
            }
        }
    })

    document.addEventListener('merge-fields:rescan', function (event) {
        rescanAll(event.target instanceof Element ? event.target : document)
    })

    document.addEventListener('click', function (event) {
        var item = event.target instanceof Element ? event.target.closest('[data-merge-insert]') : null

        if (!item) {
            return
        }

        var picker = item.closest('[data-merge-picker]')
        var field = picker && targetFor(picker)

        if (!field) {
            return
        }

        var token = item.getAttribute('data-merge-insert')
        var start = field.selectionStart == null ? field.value.length : field.selectionStart
        var end = field.selectionEnd == null ? field.value.length : field.selectionEnd

        field.value = field.value.slice(0, start) + token + field.value.slice(end)
        field.focus()
        field.selectionStart = field.selectionEnd = start + token.length
        field.dispatchEvent(new Event('input', { bubbles: true }))
    })
})()
