<style>
    /* Tab strip: stays on one line and scrolls sideways on a narrow screen instead of wrapping. */
    .website-tabs {
        position: relative;
        flex-wrap: nowrap;
        overflow-x: auto;
        gap: .25rem;
        scrollbar-width: none;
    }

    .website-tabs::-webkit-scrollbar { display: none; }
    .website-tabs .nav-link { white-space: nowrap; }

    /* Overview: live | draft previews */
    .website-previews {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1.5rem;
    }

    .website-preview-label {
        display: flex;
        align-items: center;
        gap: .5rem;
        margin-bottom: .5rem;
        font-weight: 600;
        color: var(--color-text-primary, #262522);
    }

    .website-dot {
        width: .55rem;
        height: .55rem;
        border-radius: 50%;
        background: var(--color-border, #E5E1DA);
    }

    .website-dot.is-live { background: var(--color-success, #3C8D5A); }
    .website-dot.is-draft { background: var(--color-warning, #C58A2B); }

    .website-chip {
        margin-left: auto;
        padding: .1rem .55rem;
        border-radius: 999px;
        font-size: .75rem;
        font-weight: 500;
        color: var(--color-text-muted, #6F6D67);
        background: var(--color-surface-secondary, #FBFAF7);
        border: 1px solid var(--color-border, #E5E1DA);
    }

    .website-preview-frame {
        display: block;
        position: relative;
        aspect-ratio: 16 / 10;
        overflow: hidden;
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: .75rem;
        background: var(--color-surface-secondary, #FBFAF7);
        box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
        transition: box-shadow .15s ease, border-color .15s ease;
    }

    a.website-preview-frame:hover,
    a.website-preview-frame:focus-visible {
        border-color: var(--color-primary, #B5524C);
        box-shadow: 0 0 0 3px var(--color-primary-soft-bg, #F4E6E4);
    }

    .website-preview-frame iframe {
        position: absolute;
        top: 0;
        left: 0;
        border: 0;
        pointer-events: none;
        background: #fff;
        visibility: hidden;
    }

    .website-preview-frame.is-fitted iframe { visibility: visible; }

    .website-preview-frame.is-empty {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        text-align: center;
        border-style: dashed;
        box-shadow: none;
    }

    .website-preview-frame.is-quiet { aspect-ratio: auto; min-height: 5rem; }

    .website-preview-meta {
        display: inline-block;
        margin-top: .4rem;
        font-size: .8125rem;
        color: var(--color-text-muted, #6F6D67);
    }

    .website-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .5rem;
        margin-top: 1.25rem;
    }

    .website-health-fold > summary {
        cursor: pointer;
        list-style: none;
        font-weight: 600;
        color: var(--color-text-primary, #262522);
    }

    .website-health-fold > summary::-webkit-details-marker { display: none; }

    /* Settings: one calm list of the existing screens */
    .website-settings-row {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .9rem 1.1rem;
        color: var(--color-text-primary, #262522);
        text-decoration: none;
    }

    .website-settings-list li + li .website-settings-row { border-top: 1px solid var(--color-border, #E5E1DA); }

    .website-settings-row:hover,
    .website-settings-row:focus-visible { background: var(--color-row-hover, var(--color-primary-soft-bg)); }

    .website-settings-title { font-weight: 600; }
    .website-settings-meta { margin-left: auto; }
    .website-settings-row > .website-settings-chevron { color: var(--color-text-muted, #6F6D67); }
    .website-settings-title + .website-settings-chevron { margin-left: auto; }
    .website-settings-meta + .website-settings-chevron { margin-left: .25rem; }

    @media (max-width: 767.98px) {
        .website-previews { grid-template-columns: minmax(0, 1fr); gap: 1.25rem; }
        .website-actions .btn { flex: 1 1 auto; justify-content: center; }
        .website-actions form { flex: 1 1 auto; display: flex !important; }
        .website-actions form .btn { width: 100%; }
    }
</style>
