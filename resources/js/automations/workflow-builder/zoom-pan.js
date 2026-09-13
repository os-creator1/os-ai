// Automations V2 (contract §13.1, V2-D) — lightweight viewport zoom/pan.
// No graph library: a CSS transform on one surface element, driven by
// wheel/pinch, drag, buttons and the keyboard. This state is presentation
// only — it is never read by autosave and never leaves this module, so
// zooming or panning can never modify the workflow document (task
// requirement, test #21).

const MIN_SCALE = 0.4
const MAX_SCALE = 2
const STEP = 1.2

export function createZoomPan(viewport, surface) {
    let scale = 1
    let tx = 0
    let ty = 0
    let panning = false
    let panStartX = 0
    let panStartY = 0
    let panOriginX = 0
    let panOriginY = 0

    function apply() {
        surface.style.transform = `translate(${tx}px, ${ty}px) scale(${scale})`
    }

    function clampScale(value) {
        return Math.min(MAX_SCALE, Math.max(MIN_SCALE, value))
    }

    function zoomIn() {
        scale = clampScale(scale * STEP)
        apply()
    }

    function zoomOut() {
        scale = clampScale(scale / STEP)
        apply()
    }

    function reset() {
        scale = 1
        tx = 0
        ty = 0
        apply()
    }

    function fit() {
        const viewportWidth = viewport.clientWidth
        const surfaceWidth = surface.scrollWidth || viewportWidth

        if (surfaceWidth > 0) {
            scale = clampScale(Math.min(1, viewportWidth / surfaceWidth))
        }

        tx = 0
        ty = 0
        apply()
    }

    function onWheel(event) {
        if (!event.ctrlKey && !event.metaKey) {
            return
        }

        event.preventDefault()

        if (event.deltaY < 0) {
            zoomIn()
        } else {
            zoomOut()
        }
    }

    function onPointerDown(event) {
        if (event.button !== 0) {
            return
        }

        panning = true
        panStartX = event.clientX
        panStartY = event.clientY
        panOriginX = tx
        panOriginY = ty
        viewport.classList.add('is-panning')
    }

    function onPointerMove(event) {
        if (!panning) {
            return
        }

        tx = panOriginX + (event.clientX - panStartX)
        ty = panOriginY + (event.clientY - panStartY)
        apply()
    }

    function onPointerUp() {
        panning = false
        viewport.classList.remove('is-panning')
    }

    function onKeydown(event) {
        if (!(event.ctrlKey || event.metaKey)) {
            return
        }

        if (event.key === '+' || event.key === '=') {
            event.preventDefault()
            zoomIn()
        } else if (event.key === '-') {
            event.preventDefault()
            zoomOut()
        } else if (event.key === '0') {
            event.preventDefault()
            reset()
        }
    }

    viewport.addEventListener('wheel', onWheel, { passive: false })
    viewport.addEventListener('mousedown', onPointerDown)
    window.addEventListener('mousemove', onPointerMove)
    window.addEventListener('mouseup', onPointerUp)
    viewport.addEventListener('keydown', onKeydown)

    apply()

    return { zoomIn, zoomOut, reset, fit, getState: () => ({ scale, tx, ty }) }
}
