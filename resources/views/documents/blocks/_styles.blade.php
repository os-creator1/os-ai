{{-- Contract 17B §2 — the one stylesheet for every block-document surface.
     Scoped under .doc-blocks. Token variables fall back to the design-system
     defaults so the standalone public page (which loads no app CSS) looks the
     same as the in-app preview. --}}
<style>
.doc-blocks{--doc-text:var(--color-text-primary,#262522);--doc-muted:var(--color-text-muted,#6F6D67);--doc-border:var(--color-border-neutral,#E5E1DA);--doc-soft:var(--color-surface-secondary,#FBFAF7);--doc-accent:var(--color-primary,#B5524C);--doc-accent-soft:var(--color-primary-soft-bg,#F4E6E4);box-sizing:border-box;max-width:794px;margin:0 auto;padding:48px 56px;background:var(--color-surface-primary,#fff);color:var(--doc-text);font-family:system-ui,-apple-system,"Segoe UI",sans-serif;font-size:15px;line-height:1.6}
.doc-blocks *,.doc-blocks *::before,.doc-blocks *::after{box-sizing:border-box}
.doc-blocks .doc-block{margin:0 0 14px}
.doc-blocks h1,.doc-blocks h2,.doc-blocks h3{line-height:1.25;margin:0 0 10px;font-weight:650;white-space:pre-wrap}
.doc-blocks h1{font-size:30px}.doc-blocks h2{font-size:23px}.doc-blocks h3{font-size:18px}
.doc-blocks p.doc-text{margin:0;white-space:pre-wrap;overflow-wrap:anywhere}
.doc-blocks .doc-align-center{text-align:center}.doc-blocks .doc-align-right{text-align:right}.doc-blocks .doc-align-left{text-align:left}
.doc-blocks a{color:var(--color-link,var(--doc-accent))}
.doc-blocks .doc-merge{background:var(--doc-accent-soft);border:1px dashed var(--color-primary-border,#E3C3BF);border-radius:4px;padding:0 6px;font-size:.9em;white-space:nowrap}
.doc-blocks hr.doc-divider{border:0;border-top:1px solid var(--doc-border);margin:18px 0}
.doc-blocks .doc-section{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--doc-muted);border-bottom:1px solid var(--doc-border);padding-bottom:6px;margin-top:22px}
.doc-blocks .doc-image{display:block;max-width:100%;height:auto;border-radius:6px}
.doc-blocks .doc-details{background:var(--doc-soft);border:1px solid var(--doc-border);border-radius:8px;padding:12px 16px;font-size:14px}
.doc-blocks .doc-details div+div{color:var(--doc-muted)}
.doc-blocks table.doc-table{width:100%;border-collapse:collapse;font-size:14px;margin:0}
.doc-blocks .doc-table th,.doc-blocks .doc-table td{text-align:left;padding:9px 6px;border-bottom:1px solid var(--doc-border);vertical-align:top}
.doc-blocks .doc-table th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--doc-muted);font-weight:600}
.doc-blocks .doc-table .num{text-align:right;white-space:nowrap}
.doc-blocks .doc-table .doc-total td{font-weight:650;border-bottom:2px solid var(--doc-text)}
.doc-blocks .doc-muted{color:var(--doc-muted);font-size:13px}
.doc-blocks .doc-placeholder{border:1px dashed var(--doc-border);border-radius:8px;background:var(--doc-soft);color:var(--doc-muted);padding:16px;text-align:center;font-size:14px}
.doc-blocks .doc-pagebreak-marker{display:flex;align-items:center;gap:10px;color:var(--doc-muted);font-size:12px;text-transform:uppercase;letter-spacing:.08em}
.doc-blocks .doc-pagebreak-marker::before,.doc-blocks .doc-pagebreak-marker::after{content:"";flex:1;border-top:1px dashed var(--doc-border)}
.doc-blocks .doc-page-break{break-after:page;page-break-after:always;height:0;margin:0}
.doc-blocks .doc-signature-slot{margin-top:8px}
@media (max-width:640px){.doc-blocks{padding:24px 18px}}
@media print{.doc-blocks{max-width:none;padding:0}.doc-blocks .doc-signature-slot form{display:none}}
</style>
