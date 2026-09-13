// Automations V2 (contract §13, V2-D) — the builder's entry point. One
// bundled ES module (contract §13.1), exposed as `window.AutomationsWorkflowBuilder`
// so the Blade views (which cannot use `import` directly) can call it after
// this bundle loads.
import { renderCanvas } from './canvas-renderer.js'
import { createDrawer } from './drawer.js'
import { createHistory } from './history.js'
import { createZoomPan } from './zoom-pan.js'
import { createAutosave } from './autosave.js'
import { createApiClient } from './api.js'
import { createStepPicker } from './step-picker.js'
import { createTestPanel } from './test-panel.js'
import { listRecipes } from './recipes.js'
import { newNode, countNodes, insertAt, insertBranchAt, removeFrom, moveWithin } from './document-model.js'
import { renderDocumentBanner, hasErrors, countIssues } from './validation.js'
import { INSERTABLE_TYPES, NODE_TYPES, defaultConfigFor } from './constants.js'

const STATUS_LABELS = { draft: 'Draft', published: 'Published', paused: 'Paused', archived: 'Archived' }

function initBuilder(root) {
    const dataEl = document.getElementById('wf-builder-data')

    if (!dataEl) {
        return
    }

    const data = JSON.parse(dataEl.textContent)
    let doc = data.draft.definition
    let errors = data.draft.errors || {}
    let selectedKey = null
    let path = null
    let status = data.workflow.status || 'draft'
    const readOnly = status === 'archived'

    const canvasRootEl = root.querySelector('[data-role="wf-canvas-root"]')
    const saveStateEl = root.querySelector('[data-role="wf-save-state"]')
    const bannerEl = root.querySelector('[data-role="wf-document-errors"]')
    const undoButton = root.querySelector('[data-role="wf-undo"]')
    const redoButton = root.querySelector('[data-role="wf-redo"]')
    const publishButton = root.querySelector('[data-role="wf-publish"]')
    const publishLabel = root.querySelector('[data-role="wf-publish-label"]')
    const pauseButton = root.querySelector('[data-role="wf-pause"]')
    const resumeButton = root.querySelector('[data-role="wf-resume"]')
    const statusEl = root.querySelector('[data-role="wf-status"]')
    const noticeEl = root.querySelector('[data-role="wf-header-notice"]')
    const testButton = root.querySelector('[data-role="wf-test-workflow"]')
    const viewportEl = root.querySelector('[data-role="wf-canvas-viewport"]')
    const surfaceEl = root.querySelector('[data-role="wf-canvas-surface"]')
    const workspaceEl = root.querySelector('[data-role="wf-workspace"]')

    const api = createApiClient(data.basePath)
    const history = createHistory(doc)
    const zoomPan = createZoomPan(viewportEl, surfaceEl)
    const picker = createStepPicker(root)

    root.querySelector('[data-role="wf-zoom-in"]').addEventListener('click', zoomPan.zoomIn)
    root.querySelector('[data-role="wf-zoom-out"]').addEventListener('click', zoomPan.zoomOut)
    root.querySelector('[data-role="wf-zoom-reset"]').addEventListener('click', zoomPan.reset)
    root.querySelector('[data-role="wf-zoom-fit"]').addEventListener('click', zoomPan.fit)

    // ---------------------------------------------------------------
    // Header: save state, status, lifecycle
    // ---------------------------------------------------------------

    function setSaveState(state) {
        saveStateEl.dataset.state = state
        const label = { saving: 'Saving…', saved: 'All changes saved', error: 'Offline — changes kept locally' }[state]
        saveStateEl.querySelector('[data-role="wf-save-state-label"]').textContent = label || ''
    }

    function showNotice(message, tone) {
        noticeEl.textContent = message || ''
        noticeEl.dataset.tone = tone || 'info'
        noticeEl.hidden = !message
    }

    function renderStatus() {
        statusEl.dataset.status = status
        statusEl.textContent = STATUS_LABELS[status] || status
        pauseButton.hidden = status !== 'published'
        resumeButton.hidden = status !== 'paused'
        publishButton.hidden = readOnly
        publishLabel.textContent = status === 'draft' ? 'Publish' : 'Publish changes'
    }

    const autosave = createAutosave({
        api,
        workflowBasePath: data.basePath,
        initialRevision: data.draft.revision,
        onStateChange: setSaveState,
        onSaved(body) {
            errors = body.errors || {}
            rerender()
        },
        onConflict() {
            bannerEl.classList.remove('d-none')
            bannerEl.innerHTML = ''
            bannerEl.appendChild(Object.assign(document.createElement('p'), {
                className: 'mb-0',
                textContent: 'This workflow changed somewhere else. Reload to get the latest version before editing.',
            }))
        },
    })

    // ---------------------------------------------------------------
    // Panels: inspector and test
    // ---------------------------------------------------------------

    function syncPanels() {
        workspaceEl.classList.toggle('has-panel', drawer.isOpen() || testPanel.isOpen())
    }

    const drawer = createDrawer({
        drawerEl: root.querySelector('[data-role="wf-drawer"]'),
        catalogs: data.catalogs,
        dateOffsets: data.dateOffsets,
        limits: data.limits,
        onSave(node, config) {
            node.config = config
            onDocumentChanged()
        },
        onDelete(node) {
            deleteNode(node, true)
        },
        onClose(node) {
            selectedKey = null
            syncPanels()
            rerender()

            const card = node && canvasRootEl.querySelector(`[data-node-key="${CSS.escape(node.key)}"]`)

            if (card) {
                card.focus({ preventScroll: true })
            }
        },
    })

    const testPanel = createTestPanel({
        panelEl: root.querySelector('[data-role="wf-test-panel"]'),
        api,
        basePath: data.basePath,
        beforeRun: () => autosave.flushPending(),
        onPath(nextPath) {
            path = nextPath
            rerender()
        },
        onClose() {
            syncPanels()
        },
    })

    function selectNode(node) {
        if (testPanel.isOpen()) {
            testPanel.close()
        }

        selectedKey = node.key
        drawer.open(node, errors[node.key] || [], { readOnly })
        syncPanels()
        rerender()
    }

    // ---------------------------------------------------------------
    // Document edits
    // ---------------------------------------------------------------

    function findContainingList(target, list) {
        if (list.includes(target)) {
            return list
        }

        for (const node of list) {
            if (node.type === NODE_TYPES.IF_ELSE) {
                const inYes = findContainingList(target, node.yes || [])
                if (inYes) return inYes
                const inNo = findContainingList(target, node.no || [])
                if (inNo) return inNo
            } else if (Array.isArray(node.next)) {
                const found = findContainingList(target, node.next)
                if (found) return found
            }
        }

        return null
    }

    function stepCount(node) {
        if (node.type !== NODE_TYPES.IF_ELSE) {
            return 0
        }

        const count = (list) => list.reduce((total, child) => total + 1 + stepCount(child), 0)

        return count(node.yes || []) + count(node.no || [])
    }

    function deleteNode(node, alreadyConfirmed) {
        if (readOnly) {
            return
        }

        const inner = stepCount(node)

        if (!alreadyConfirmed || inner > 0) {
            const message = inner > 0
                ? `Delete this If / Else and the ${inner} step${inner === 1 ? '' : 's'} inside it?`
                : 'Delete this step?'

            if (!window.confirm(message)) {
                return
            }
        }

        const list = findContainingList(node, doc.root.next || [])

        if (list) {
            if (selectedKey === node.key) {
                drawer.close()
            }

            removeFrom(list, node)
            onDocumentChanged()
        }
    }

    function onDocumentChanged() {
        history.push(doc)

        if (path) {
            // A test result describes the document it ran on, not this one.
            path = null
        }

        rerender()
        autosave.schedule(doc)
    }

    function requestAdd(anchorEl, list, index, depth) {
        const trailing = index === list.length
        const canBranch = !(data.limits && depth >= data.limits.maxBranchDepth)

        const types = INSERTABLE_TYPES.filter((type) => {
            if (type === NODE_TYPES.IF_ELSE) {
                return canBranch
            }

            // End closes a path, so it is only offered where nothing follows.
            if (type === NODE_TYPES.END) {
                return trailing
            }

            return true
        })

        const hints = {}

        if (!trailing && canBranch) {
            hints[NODE_TYPES.IF_ELSE] = 'The steps below move into its Yes path.'
        }

        picker.open(anchorEl, {
            types,
            hints,
            note: canBranch ? '' : 'If / Else can’t be nested any deeper here.',
            onPick(type) {
                const node = newNode(type, defaultConfigFor(type))

                if (type === NODE_TYPES.IF_ELSE && !trailing) {
                    insertBranchAt(list, index, node)
                } else {
                    insertAt(list, index, node)
                }

                onDocumentChanged()
                selectNode(node)
            },
        })
    }

    function rerender() {
        const nodeCount = countNodes(doc)

        renderCanvas(
            doc,
            canvasRootEl,
            {
                onSelect: selectNode,
                onRequestAdd: requestAdd,
                onDelete(node) {
                    deleteNode(node, false)
                },
                onMove(node, list, direction) {
                    if (moveWithin(list, node, direction)) {
                        onDocumentChanged()
                    }
                },
            },
            { errors, nodeCount, limits: data.limits, selectedKey, readOnly, catalogs: data.catalogs, path },
        )

        renderDocumentBanner(bannerEl, errors, 'This workflow has :count issue(s) to fix before it can publish.')
        undoButton.disabled = readOnly || !history.canUndo()
        redoButton.disabled = readOnly || !history.canRedo()

        const blocked = hasErrors(errors)
        publishButton.disabled = blocked
        publishButton.title = blocked ? `Fix ${countIssues(errors)} issue${countIssues(errors) === 1 ? '' : 's'} before publishing` : ''
    }

    // ---------------------------------------------------------------
    // Toolbar actions
    // ---------------------------------------------------------------

    function restore(restored) {
        if (!restored) {
            return
        }

        drawer.close()
        doc = restored
        path = null
        rerender()
        autosave.schedule(doc)
    }

    undoButton.addEventListener('click', () => restore(history.undo()))
    redoButton.addEventListener('click', () => restore(history.redo()))

    publishButton.addEventListener('click', async () => {
        publishButton.disabled = true
        showNotice('Publishing…', 'info')

        await autosave.flushNow(doc)
        const result = await api.publish(data.basePath)

        if (result.status === 422) {
            errors = (result.body && result.body.errors) || {}
            showNotice('Fix the highlighted steps before publishing.', 'danger')
            rerender()

            return
        }

        if (result.status >= 200 && result.status < 300) {
            // A publish turns this draft into the live version; reloading opens
            // the fresh draft the next edit saves against.
            showNotice('Published. Contacts who match the trigger now enter this workflow.', 'success')
            window.location.reload()

            return
        }

        showNotice((result.body && result.body.message) || 'Publishing didn’t work. Try again.', 'danger')
        rerender()
    })

    async function changeStatus(button, call, done) {
        button.disabled = true
        const result = await call(data.basePath)
        button.disabled = false

        if (result.status >= 200 && result.status < 300 && result.body && result.body.workflow) {
            status = result.body.workflow.status
            renderStatus()
            showNotice(done, 'success')

            return
        }

        showNotice((result.body && result.body.message) || 'That didn’t work. Try again.', 'danger')
    }

    pauseButton.addEventListener('click', () => {
        if (window.confirm('Pause this workflow? No new contacts enter, and contacts already in it wait where they are until you resume.')) {
            changeStatus(pauseButton, api.pause, 'Paused. Contacts already in the workflow wait until you resume it.')
        }
    })

    resumeButton.addEventListener('click', () => {
        changeStatus(resumeButton, api.resume, 'Resumed. The workflow is live again.')
    })

    testButton.addEventListener('click', () => {
        drawer.close()
        selectedKey = null
        testPanel.open()
        syncPanels()
    })

    // ---------------------------------------------------------------
    // Keyboard: ↑/↓ between steps, Enter opens, Delete removes, Esc closes
    // ---------------------------------------------------------------

    canvasRootEl.addEventListener('keydown', (event) => {
        if (!['ArrowDown', 'ArrowUp'].includes(event.key) || !event.target.matches('[data-role="wf-step-card"]')) {
            return
        }

        const cards = [...canvasRootEl.querySelectorAll('[data-role="wf-step-card"]')]
        const index = cards.indexOf(event.target)
        const next = cards[index + (event.key === 'ArrowDown' ? 1 : -1)]

        if (next) {
            event.preventDefault()
            next.focus()
            next.scrollIntoView({ block: 'nearest', inline: 'nearest' })
        }
    })

    renderStatus()
    rerender()

    if (readOnly) {
        showNotice('This workflow is archived. You can review it, but not change it.', 'info')
    }
}

function initChooser(root) {
    const scratchCard = root.querySelector('[data-role="wf-chooser-scratch"]')
    const recipesContainer = root.querySelector('[data-role="wf-chooser-recipes"]')
    const nameInput = root.querySelector('[data-role="wf-chooser-name"]')
    const errorEl = root.querySelector('[data-role="wf-chooser-error"]')
    const createUrl = root.dataset.createUrl
    const labelsEl = document.getElementById('wf-recipe-labels')
    const labels = labelsEl ? JSON.parse(labelsEl.textContent) : {}
    const api = createApiClient(createUrl)
    let busy = false

    function showError(message) {
        if (errorEl) {
            errorEl.textContent = message || ''
            errorEl.hidden = !message
        }
    }

    // §20.2 — creating a workflow takes a name and a trigger type, nothing
    // else. A recipe is then only a starting document: it is saved into the new
    // workflow's draft through the same autosave any edit uses.
    async function createWorkflow(fallbackName, definition) {
        if (busy) {
            return
        }

        const name = (nameInput && nameInput.value.trim()) || fallbackName
        const triggerType = (definition && definition.root.config.trigger_type) || 'contact_created'

        busy = true
        showError('')
        root.classList.add('is-busy')

        try {
            const created = await api.create({ name, trigger_type: triggerType })
            const redirect = created.body && created.body.redirect

            if (created.status !== 201 || !redirect) {
                const firstError = created.body && created.body.errors ? Object.values(created.body.errors)[0][0] : null
                showError(firstError || (created.body && created.body.message) || 'The workflow couldn’t be created. Try again.')

                return
            }

            if (definition) {
                const opened = await api.openDraft(redirect)

                if (opened.body && typeof opened.body.revision === 'number') {
                    await api.saveDraft(redirect, definition, opened.body.revision)
                }
            }

            window.location.href = redirect
        } finally {
            busy = false
            root.classList.remove('is-busy')
        }
    }

    scratchCard.addEventListener('click', () => createWorkflow('Untitled workflow', null))
    scratchCard.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault()
            createWorkflow('Untitled workflow', null)
        }
    })

    listRecipes().forEach((recipe) => {
        const col = document.createElement('div')
        col.className = 'col-12 col-md-6 col-xl-3'

        const card = document.createElement('button')
        card.type = 'button'
        card.className = 'wf-chooser-card'
        card.dataset.role = 'wf-chooser-recipe'
        card.dataset.recipeKey = recipe.key

        const labelSet = labels[recipe.key] || {}

        const title = document.createElement('span')
        title.className = 'wf-chooser-card__title'
        title.textContent = labelSet.title || recipe.titleKey

        const description = document.createElement('span')
        description.className = 'wf-chooser-card__description'
        description.textContent = labelSet.description || recipe.descriptionKey

        card.appendChild(title)
        card.appendChild(description)
        col.appendChild(card)
        recipesContainer.appendChild(col)

        card.addEventListener('click', () => {
            createWorkflow(labelSet.title || 'Untitled workflow', recipe.build())
        })
    })
}

window.AutomationsWorkflowBuilder = { initBuilder, initChooser }
