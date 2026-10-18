{{-- Forms — the ONE stylesheet of a rendered form. Included by the real public
     page, by the builder's Preview and by the builder's canvas, so the three can
     never drift apart. $design is the version's small closed style object
     (FormVersion::style()): never free CSS, only these tokens. --}}
@php
    $design = $design ?? [];
    $accent = $design['accent'] ?? '#2563eb';
    $pageBg = $design['background'] ?? '#f3f5f9';
    $radius = ['none' => '0px', 'sm' => '4px', 'md' => '8px', 'lg' => '16px'][$design['radius'] ?? 'md'] ?? '8px';
    $width = ['narrow' => '30rem', 'medium' => '40rem', 'wide' => '52rem'][$design['width'] ?? 'medium'] ?? '40rem';
@endphp
<style>
    .pf-scope{--pf-accent:{{ $accent }};--pf-bg:{{ $pageBg }};--pf-radius:{{ $radius }};--pf-width:{{ $width }};--pf-text:#18202b;--pf-muted:#5b6776;--pf-line:#cdd5df;--pf-error:#b42318;
        font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--pf-text);line-height:1.45}
    .pf-scope *{box-sizing:border-box}
    .pf-shell{max-width:var(--pf-width);margin:0 auto;padding:2rem 1rem}
    .pf-card{background:#fff;border-radius:calc(var(--pf-radius) + 4px);padding:1.75rem;box-shadow:0 1px 3px rgba(16,24,40,.08),0 8px 24px rgba(16,24,40,.06)}
    .pf-title{font-size:1.6rem;margin:0 0 .35rem;line-height:1.2}
    .pf-step{color:var(--pf-muted);margin:.25rem 0 1rem;font-size:.9rem}
    .pf-intro{color:var(--pf-muted);margin:.25rem 0 1.1rem}
    .pf-error{color:var(--pf-error);background:#fef3f2;border:1px solid #fecdca;border-radius:var(--pf-radius);padding:.6rem .8rem;margin-bottom:1rem}
    .pf-row{display:flex;flex-wrap:wrap;gap:0 1rem}
    .pf-item{flex:1 1 100%;min-width:0;margin:0 0 1rem}
    .pf-item.pf-half{flex:1 1 calc(50% - .5rem)}
    @media (max-width:560px){.pf-item.pf-half{flex-basis:100%}}
    .pf-label{display:block;font-weight:600;font-size:.92rem;margin:0 0 .3rem}
    .pf-req{color:var(--pf-error)}
    .pf-help{display:block;color:var(--pf-muted);font-size:.82rem;margin-top:.25rem}
    .pf-input,.pf-select,.pf-textarea{width:100%;font:inherit;padding:.65rem .75rem;border:1px solid var(--pf-line);border-radius:var(--pf-radius);background:#fff;color:inherit}
    .pf-input:focus,.pf-select:focus,.pf-textarea:focus{outline:2px solid color-mix(in srgb,var(--pf-accent) 35%,transparent);border-color:var(--pf-accent)}
    .pf-textarea{min-height:7rem;resize:vertical}
    .pf-choice{display:flex;align-items:flex-start;gap:.55rem;margin:.3rem 0;font-weight:400}
    .pf-choice input{margin-top:.25rem;accent-color:var(--pf-accent)}
    .pf-consent{font-size:.88rem;color:var(--pf-muted)}
    .pf-heading{font-size:1.2rem;margin:.4rem 0 .2rem;line-height:1.25}
    .pf-paragraph{margin:0;color:var(--pf-muted)}
    .pf-divider{border:0;border-top:1px solid var(--pf-line);margin:.5rem 0}
    .pf-spacer{height:1.5rem}
    .pf-actions{display:flex;align-items:center;gap:1rem;margin-top:1.4rem}
    .pf-actions.pf-align-left{justify-content:flex-start}.pf-actions.pf-align-center{justify-content:center}.pf-actions.pf-align-right{justify-content:flex-end}
    .pf-btn{font:inherit;font-weight:600;letter-spacing:.02em;padding:.8rem 1.6rem;border:0;border-radius:var(--pf-radius);background:var(--pf-accent);color:#fff;cursor:pointer}
    .pf-btn:hover{filter:brightness(1.07)}
    .pf-align-full .pf-btn{width:100%}
    .pf-back{color:var(--pf-muted);font-size:.9rem}
    .pf-hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
</style>
