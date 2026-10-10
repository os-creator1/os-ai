{{--
    SEO -> Content tab router. The shared SectionRouter (partials/section-router) does the fetching, swapping, history and loading
    state and is mounted exactly once here; Content only supplies what is its own:

      - links marked `data-content-nav` (the tab strip) and `data-content-local` (in-section links such as the Articles filters)
        swap #seo-content-region; everything else is an ordinary link;
      - the header subtitle and action sit outside the region, so beforeSwap lifts them out of the incoming section;
      - the Articles search is a GET form marked `data-content-form`; its submit is turned into the same navigation, and the
        caret is put back in the box afterwards.

    The region must not rely on inline scripts (see the router's own notes). Loaded by _frame, never by a section view.
--}}
@verbatim
<script>
    (function () {
        'use strict';

        var region = document.getElementById('seo-content-region');

        if (!region || !window.SectionRouter || region.__contentRouterBound) {
            return;
        }

        region.__contentRouterBound = true;

        var subtitle = document.getElementById('content-subtitle');
        var action = document.getElementById('content-header-action');
        var refocusSearch = false;

        var router = window.SectionRouter.mount({
            content: region,
            linkSelector: 'a[data-content-nav], a[data-content-local]',
            sectionAttr: 'data-content-section',
            titleAttr: 'data-content-title',
            linkKeyAttr: 'data-content-key',
            activeClass: 'is-active',
            liveRegion: document.getElementById('content-live-status'),
            beforeSwap: function (next) {
                if (subtitle) {
                    subtitle.textContent = next.getAttribute('data-content-subtitle') || '';
                }

                if (action) {
                    var tpl = next.querySelector('template[data-content-action]');

                    action.innerHTML = tpl ? tpl.innerHTML : '';
                }
            },
            afterSwap: function () {
                var input = refocusSearch ? region.querySelector('input[name="q"]') : null;

                refocusSearch = false;

                if (input) {
                    input.focus();
                    input.setSelectionRange(input.value.length, input.value.length);
                }
            }
        });

        document.addEventListener('submit', function (event) {
            var form = event.target;

            if (!router || event.defaultPrevented || !form.matches || !form.matches('form[data-content-form]')) {
                return;
            }

            event.preventDefault();

            var url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
            var data = new FormData(form);

            url.search = '';

            data.forEach(function (value, key) {
                if (typeof value === 'string' && value.trim() !== '') {
                    url.searchParams.set(key, value.trim());
                }
            });

            refocusSearch = true;
            router.navigate(url.toString(), 'push');
        });
    })();
</script>
@endverbatim
