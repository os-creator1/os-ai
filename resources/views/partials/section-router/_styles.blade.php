{{--
    MotionGrove — the one branded loading state for content that is being replaced in place (see
    _script.blade.php), plus the busy / enter states of a section-router region.

    Three thick bars whose heights shift out of phase. The colour is the application's own primary
    (`--color-primary`, the runtime theme token), so it follows whatever theme is active; nothing here
    hard-codes a brand colour. Compact, no text, decorative (aria-hidden — the region itself carries
    aria-busy), and still under prefers-reduced-motion.

    Markup (also produced by SectionRouter.motionGrove()):
        <span class="motiongrove" role="presentation" aria-hidden="true"><i></i><i></i><i></i></span>
    or <x-motiongrove />. Standing alone it is an inline element; as a direct child of a
    `.mg-host` region it is centred over that region.
--}}
<style>
    .motiongrove {
        --mg-color: var(--color-primary, #B5524C);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .3rem;
        height: 1.9rem;
    }

    .motiongrove > i {
        display: block;
        width: .5rem;
        height: 100%;
        border-radius: .2rem;
        background: var(--mg-color);
        transform: scaleY(.4);
        transform-origin: center;
        animation: motiongrove-bar 1s ease-in-out infinite;
    }

    .motiongrove > i:nth-child(2) { animation-delay: -.66s; }
    .motiongrove > i:nth-child(3) { animation-delay: -.33s; }

    @keyframes motiongrove-bar {
        0%, 100% { transform: scaleY(.35); }
        50% { transform: scaleY(1); }
    }

    /* A region whose content is swapped in place. */
    .mg-host { position: relative; }

    .mg-host.mg-busy { min-height: 12rem; }

    .mg-host.mg-busy > :not(.motiongrove) {
        opacity: .35;
        pointer-events: none;
        transition: opacity .15s ease;
    }

    .mg-host > .motiongrove {
        position: absolute;
        left: 50%;
        top: min(50%, 12rem);
        transform: translate(-50%, -50%);
        z-index: 5;
    }

    .mg-host.mg-enter { animation: mg-enter .18s ease-out; }

    @keyframes mg-enter {
        from { opacity: .55; }
        to { opacity: 1; }
    }

    .mg-sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        padding: 0;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    @media (prefers-reduced-motion: reduce) {
        .motiongrove > i { animation: none; }
        .motiongrove > i:nth-child(1) { transform: scaleY(.5); }
        .motiongrove > i:nth-child(2) { transform: scaleY(1); }
        .motiongrove > i:nth-child(3) { transform: scaleY(.7); }
        .mg-host.mg-enter { animation: none; }
        .mg-host.mg-busy > :not(.motiongrove) { transition: none; }
    }
</style>
