<style>
    .mg-back-link { display: inline-flex; align-items: center; gap: .35rem; margin-bottom: .75rem; font-size: .875rem; color: var(--color-text-muted, #6F6D67); text-decoration: none; }
    .mg-back-link:hover, .mg-back-link:focus-visible { color: var(--color-primary, #B5524C); }
    .mg-manage-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
    .mg-manage-h1 { margin: 0; font-size: 1.5rem; font-weight: 700; color: var(--color-text-primary, #262522); }
    .mg-manage-sub { margin: .15rem 0 0; font-size: .875rem; color: var(--color-text-muted, #6F6D67); overflow-wrap: anywhere; }
    .mg-manage-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
    .mg-manage-tabs { display: flex; gap: .25rem; margin-bottom: 1.25rem; border-bottom: 1px solid var(--color-border, #E5E1DA); overflow-x: auto; scrollbar-width: none; }
    .mg-manage-tabs::-webkit-scrollbar { display: none; }
    .mg-manage-tabs a { position: relative; display: inline-flex; align-items: center; gap: .4rem; padding: .6rem .9rem; margin-bottom: -1px; white-space: nowrap; font-size: .875rem; color: var(--color-text-muted, #6F6D67); text-decoration: none; border-bottom: 2px solid transparent; }
    .mg-manage-tabs a.is-active { color: var(--color-primary, #B5524C); font-weight: 600; border-bottom-color: var(--color-primary, #B5524C); }
    .mg-tab-count { padding: 0 .4rem; border-radius: 999px; font-size: .7rem; background: var(--color-primary-soft-bg, #F4E6E4); color: var(--color-primary, #B5524C); }
    .mg-card { background: var(--color-surface, #fff); border: 1px solid var(--color-border, #E5E1DA); border-radius: .875rem; box-shadow: 0 1px 2px rgba(0,0,0,.03); margin-bottom: 1.25rem; }
    .mg-card-pad { padding: 1.25rem; }
    .mg-card-title { margin: 0 0 .25rem; font-size: 1rem; font-weight: 600; color: var(--color-text-primary, #262522); }
    .mg-muted { color: var(--color-text-muted, #6F6D67); font-size: .8125rem; }
    .mg-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; overflow-wrap: anywhere; }
    .mg-icon-tile { display: inline-flex; align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem; border-radius: .75rem; background: var(--color-primary-soft-bg, #F4E6E4); color: var(--color-primary, #B5524C); flex: none; }
    .mg-chip { display: inline-block; padding: .2rem .6rem; border-radius: 999px; font-size: .75rem; background: var(--color-surface-secondary, #F4F1EC); color: var(--color-text-primary, #262522); }
    .mg-sticky-bar { position: sticky; bottom: 0; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .75rem; margin-top: 1.5rem; padding: .85rem 1rem; background: var(--color-surface, #fff); border-top: 1px solid var(--color-border, #E5E1DA); border-radius: .75rem .75rem 0 0; }
    .mg-sticky-bar .mg-muted { margin-right: auto; }
</style>
