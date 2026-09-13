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
import { listRecipes } from './recipes.js'
import { newNode, countNodes, insertAt, removeFrom, moveWithin } from './document-model.js'
import { renderDocumentBanner, hasErrors } from './validation.js'
import { defaultConfigFor } from './constants.js'

function initBuilder(root) {
    const dataEl = document.getElementById('wf-builder-data')

    if (!dataEl) {
        return
    }

    const data = JSON.parse(dataEl.textContent)
    let doc = data.draft.definition
    let errors = data.draft.errors || {}
    let selectedKey = null

    const canvasRootEl = root.querySelector('[data-role="wf-canvas-root"]')
    const saveStateEl = root.querySelector('[data-role="wf-save-state"]')
    const bannerEl = root.querySelector('[data-role="wf-document-errors"]')
    const undoButton = root.querySelector('[data-role="wf-undo"]')
    const redoButton = root.querySelector('[data-role="wf-redo"]')
    const publishButton = root.querySelector('[data-role="wf-publish"]')
    const testButton = root.querySelector('[data-role="wf-test-workflow"]')
    const viewportEl = root.querySelector('[data-role="wf-canvas-viewport"]')
    const surfaceEl = root.querySelector('[data-role="wf-canvas-surface"]')

    const api = createApiClient(data.basePath)
    const history = createHistory(doc)
    const zoomPan = createZoomPan(viewportEl, surfaceEl)

    root.querySelector('[data-role="wf-zoom-in"]').addEventListener('click', zoomPan.zoomIn)
    root.querySelector('[data-role="wf-zoom-out"]').addEventListener('click', zoomPan.zoomOut)
    root.querySelector('[data-role="wf-zoom-reset"]').addEventListener('click', zoomPan.reset)
    root.querySelector('[data-role="wf-zoom-fit"]').addEventListener('click', zoomPan.fit)

    function setSaveState(state) {
        saveStateEl.dataset.state = state
        const label = { saving: 'Saving…', saved: 'Saved', error: 'Offline — changes kept locally' }[state]
        saveStateEl.textContent = label || ''
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
            bannerEl.innerHTML =
                '<p class="mb-0">This workflow changed somewhere else. Reload to get the latest version before editing.</p>'
        },
    })

    const drawer = createDrawer({
        drawerEl: root.querySelector('[data-role="wf-drawer"]'),
        catalogs: data.catalogs,
        dateOffsets: data.dateOffsets,
        contactSources: data.contactSources,
        onSave(node, config) {
            node.config = config
            onDocumentChanged()
        },
        onDelete(node) {
            deleteNode(node)
        },
    })

    function findContainingList(target, list) {
        if (list.includes(target)) {
            return list
        }

        for (const node of list) {
            if (node.type === 'if_else') {
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

    function deleteNode(node) {
        const list = findContainingList(node, doc.root.next || [])

        if (list) {
            removeFrom(list, node)
            onDocumentChanged()
        }
    }

    function onDocumentChanged() {
        history.push(doc)
        rerender()
        autosave.schedule(doc)
    }

    function rerender() {
        const nodeCount = countNodes(doc)

        renderCanvas(
            doc,
            canvasRootEl,
            {
                onSelect(node) {
                    selectedKey = node.key
                    drawer.open(node, errors[node.key] || [])
                },
                onAdd(list, index, depth, type) {
                    const node = newNode(type, defaultConfigFor(type))
                    insertAt(list, index, node)
                    onDocumentChanged()
                },
                onDelete(node) {
                    deleteNode(node)
                },
                onMove(node, list, direction) {
                    if (moveWithin(list, node, direction)) {
                        onDocumentChanged()
                    }
                },
            },
            { errors, nodeCount, limits: data.limits, selectedKey },
        )

        renderDocumentBanner(bannerEl, errors, 'This workflow has :count issue(s) to fix before it can publish.')
        undoButton.disabled = !history.canUndo()
        redoButton.disabled = !history.canRedo()
        publishButton.disabled = hasErrors(errors)
    }

    undoButton.addEventListener('click', () => {
        const restored = history.undo()

        if (restored) {
            doc = restored
            rerender()
            autosave.schedule(doc)
        }
    })

    redoButton.addEventListener('click', () => {
        const restored = history.redo()

        if (restored) {
            doc = restored
            rerender()
            autosave.schedule(doc)
        }
    })

    publishButton.addEventListener('click', async () => {
        await autosave.flushNow(doc)

        const result = await api.publish(data.basePath)

        if (result.status === 422) {
            errors = (result.body && result.body.errors) || {}
            rerender()

            return
        }

        if (result.status >= 200 && result.status < 300) {
            window.location.reload()
        }
    })

    testButton.addEventListener('click', async () => {
        const contactUid = window.prompt('Contact UID to simulate with:')

        if (!contactUid) {
            return
        }

        const result = await api.simulate(data.basePath, contactUid)

        if (result.body && result.body.path) {
            window.alert(result.body.path.map((step) => step.label || step.node_type).join(' → '))
        }
    })

    rerender()
}

function initChooser(root) {
    const scratchCard = root.querySelector('[data-role="wf-chooser-scratch"]')
    const recipesContainer = root.querySelector('[data-role="wf-chooser-recipes"]')
    const createUrl = root.dataset.createUrl
    const labelsEl = document.getElementById('wf-recipe-labels')
    const labels = labelsEl ? JSON.parse(labelsEl.textContent) : {}

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]')

        return meta ? meta.getAttribute('content') : ''
    }

    async function createWorkflow(payload) {
        const response = await fetch(createUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(payload),
        })

        const body = await response.json().catch(() => null)

        if (body && body.redirect) {
            window.location.href = body.redirect
        } else if (body && body.workflow && body.workflow.uid) {
            window.location.href = `${createUrl}/${body.workflow.uid}`
        }
    }

    scratchCard.addEventListener('click', () => {
        createWorkflow({ mode: 'scratch' })
    })

    listRecipes().forEach((recipe) => {
        const col = document.createElement('div')
        col.className = 'col-12 col-lg-4'

        const card = document.createElement('div')
        card.className = 'card ds-card shadow-none h-100'
        card.style.cursor = 'pointer'
        card.dataset.role = 'wf-chooser-recipe'
        card.dataset.recipeKey = recipe.key

        const body = document.createElement('div')
        body.className = 'card-body'

        const labelSet = labels[recipe.key] || {}

        const title = document.createElement('h5')
        title.className = 'text-section-heading'
        title.textContent = labelSet.title || recipe.titleKey

        const description = document.createElement('p')
        description.className = 'text-caption mb-0'
        description.textContent = labelSet.description || recipe.descriptionKey

        body.appendChild(title)
        body.appendChild(description)
        card.appendChild(body)
        col.appendChild(card)
        recipesContainer.appendChild(col)

        card.addEventListener('click', () => {
            createWorkflow({ mode: 'recipe', recipe: recipe.key, definition: recipe.build() })
        })
    })
}

window.AutomationsWorkflowBuilder = { initBuilder, initChooser }
