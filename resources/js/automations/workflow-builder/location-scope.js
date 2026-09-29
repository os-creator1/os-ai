// Location run-scope foundation (lane contract §11, V2-D) — the Settings
// tab's "Run this automation for" control.
//
// DOCUMENT-LEVEL, NOT NODE-LEVEL, exactly like `location_scope`/
// `location_ids` sit beside `schema_version`/`root` on the server (contract
// §5A): this module edits `doc.location_scope`/`doc.location_ids` directly,
// the same document object every other builder module edits, and reports the
// change through the SAME `onDocumentChanged()` hook the canvas already uses
// — one autosave path, not a second one.
//
// WHO CAN PICK WHAT IS NOT ENFORCED HERE. The server does not hand this page
// a per-actor accessible-Location list (§18's query budget — computing it
// would re-derive Business/Workspace/membership a second time on every
// Builder load). Every option renders enabled; publish
// (WorkflowCompiler::validateLocationScope(), which re-checks
// LocationAccessGuard on the value actually submitted) is the authoritative
// gate, and a Selected-scope staff member choosing a Location — or "All" —
// they cannot reach is refused there, by name, surfaced through
// renderErrors() below.
export function createLocationScopeControl({ containerEl, locations, onChange }) {
    const groupEl = containerEl.querySelector('[data-role="wf-location-scope-group"]')
    const pickerEl = containerEl.querySelector('[data-role="wf-location-picker"]')
    const listEl = containerEl.querySelector('[data-role="wf-location-picker-list"]')
    const errorsEl = containerEl.querySelector('[data-role="wf-location-errors"]')
    const scopeInputs = Array.from(groupEl.querySelectorAll('[data-role="wf-location-scope-option"]'))

    let doc = null
    let readOnly = false

    function currentIds() {
        return Array.isArray(doc.location_ids) ? doc.location_ids.map(Number) : []
    }

    function renderPicker(scope) {
        const ids = currentIds()
        const inputType = scope === 'one' ? 'radio' : 'checkbox'

        listEl.innerHTML = ''

        locations.options.forEach((loc) => {
            const domId = 'wf-location-option-' + loc.id
            const wrapper = document.createElement('div')
            wrapper.className = 'form-check'

            const input = document.createElement('input')
            input.type = inputType
            input.className = 'form-check-input'
            input.name = 'wf-location-picker'
            input.id = domId
            input.value = String(loc.id)
            input.checked = ids.indexOf(loc.id) !== -1
            input.disabled = readOnly
            input.addEventListener('change', onPickerChange)

            const label = document.createElement('label')
            label.className = 'form-check-label'
            label.setAttribute('for', domId)
            label.textContent = loc.active ? loc.name : loc.name + ' (archived)'

            wrapper.appendChild(input)
            wrapper.appendChild(label)
            listEl.appendChild(wrapper)
        })
    }

    function onPickerChange() {
        // The native radio/checkbox `input` group already enforces "at most
        // one checked" for "One" and "any number" for "Selected" — nothing
        // here needs to re-derive that from `inputType`.
        doc.location_ids = Array.from(listEl.querySelectorAll('input:checked')).map((el) => Number(el.value))
        onChange(doc)
    }

    function onScopeChange(value) {
        doc.location_scope = value

        if (value === 'all') {
            doc.location_ids = []
        }

        pickerEl.hidden = value === 'all'
        renderPicker(value)
        onChange(doc)
    }

    scopeInputs.forEach((input) => {
        input.addEventListener('change', () => {
            if (input.checked) {
                onScopeChange(input.value)
            }
        })
    })

    function render(document_, isReadOnly) {
        doc = document_
        readOnly = isReadOnly

        const scope = doc.location_scope || 'all'

        scopeInputs.forEach((input) => {
            input.checked = input.value === scope
            input.disabled = readOnly
        })

        pickerEl.hidden = scope === 'all'
        renderPicker(scope)
    }

    /** Document-level errors mentioning "location", surfaced beside the control. */
    function renderErrors(errors) {
        const documentErrors = (errors && errors['_document']) || []
        const locationErrors = documentErrors.filter((message) => /location/i.test(message))

        errorsEl.classList.toggle('d-none', locationErrors.length === 0)
        errorsEl.innerHTML = ''

        locationErrors.forEach((message) => {
            const p = document.createElement('p')
            p.className = 'mb-0'
            p.textContent = message
            errorsEl.appendChild(p)
        })
    }

    return { render, renderErrors }
}
