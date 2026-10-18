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

    /* Settings: the Domain callout, then "Manage your website" | "Your website's look" */
    .website-domain-card {
        display: flex;
        align-items: center;
        gap: .85rem;
        padding: .9rem 1.1rem;
        border: 1px solid var(--color-status-warning-border, #EBD9A8);
        border-radius: .75rem;
        background: var(--color-status-warning-soft-bg, #FDF6E3);
    }

    .website-domain-icon,
    .website-settings-icon,
    .website-look-upload-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        width: 2rem;
        height: 2rem;
        border-radius: .5rem;
        color: var(--color-primary, #B5524C);
        background: var(--color-primary-soft-bg, #F4E6E4);
    }

    .website-domain-icon { background: var(--color-surface, #fff); width: 1.75rem; height: 1.75rem; }
    .website-domain-body { flex: 1 1 auto; min-width: 0; }
    .website-domain-action { flex: 0 0 auto; white-space: nowrap; }

    .website-settings-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 1.25rem;
        align-items: start;
    }

    .website-settings-heading { margin: 0; padding: 1rem 1.25rem .75rem; }

    a.website-settings-row {
        display: flex;
        align-items: center;
        gap: .85rem;
        padding: .85rem 1.25rem;
        color: var(--color-text-primary, #262522);
        text-decoration: none;
    }

    .website-settings-list li { border-top: 1px solid var(--color-border, #E5E1DA); }

    a.website-settings-row:hover,
    a.website-settings-row:focus-visible { color: var(--color-text-primary, #262522); text-decoration: none; background: var(--color-row-hover, var(--color-primary-soft-bg)); }

    .website-settings-text { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
    .website-settings-title { font-weight: 600; font-size: .875rem; }
    .website-settings-sub { font-size: .75rem; color: var(--color-text-muted, #6F6D67); }

    .website-settings-meta {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        flex: 0 0 auto;
        font-size: .75rem;
        color: var(--color-text-secondary, #4A4843);
        text-align: right;
    }

    .website-settings-chevron { flex: 0 0 auto; color: var(--color-text-muted, #6F6D67); }

    /* Your website's look */
    .website-look-field { margin-bottom: 1.25rem; }
    .website-look-label { display: block; margin-bottom: .35rem; font-size: .75rem; font-weight: 600; color: var(--color-text-secondary, #4A4843); }

    .website-look-color { display: flex; align-items: center; gap: .6rem; max-width: 14rem; }

    .website-look-swatch {
        flex: 0 0 auto;
        width: 2.5rem;
        height: 2.5rem;
        padding: 0;
        border: 1px solid var(--color-border, #E5E1DA);
        border-radius: .5rem;
        background: none;
        cursor: pointer;
    }

    .website-look-swatch::-webkit-color-swatch-wrapper { padding: 0; }
    .website-look-swatch::-webkit-color-swatch { border: 0; border-radius: .45rem; }
    .website-look-swatch::-moz-color-swatch { border: 0; border-radius: .45rem; }

    .website-look-upload {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .7rem .85rem;
        border: 1px dashed var(--color-border-strong, #D6D1C7);
        border-radius: .6rem;
        background: var(--color-surface-secondary, #FBFAF7);
    }

    .website-look-upload-text { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; font-size: .8125rem; }
    .website-look-upload-text .text-caption { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .website-look-choose { flex: 0 0 auto; }
    .website-look-thumb { flex: 0 0 auto; width: 3rem; height: 2.25rem; object-fit: cover; border-radius: .4rem; }
    .website-look-thumb--logo { object-fit: contain; }

    @media (max-width: 991.98px) {
        .website-settings-grid { grid-template-columns: minmax(0, 1fr); }
    }

    @media (max-width: 575.98px) {
        .website-domain-card { flex-wrap: wrap; }
        .website-domain-body { flex-basis: calc(100% - 3rem); }
        .website-domain-action { width: 100%; justify-content: center; }
        .website-look-upload { flex-wrap: wrap; }
        .website-look-upload-text { flex-basis: calc(100% - 4rem); }
    }

    @media (max-width: 767.98px) {
        .website-previews { grid-template-columns: minmax(0, 1fr); gap: 1.25rem; }
        .website-actions .btn { flex: 1 1 auto; justify-content: center; }
        .website-actions form { flex: 1 1 auto; display: flex !important; }
        .website-actions form .btn { width: 100%; }
    }
</style>
