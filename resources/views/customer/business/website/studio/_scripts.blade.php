{{--
    The Website module's one client script, emitted once per full page load by the shell (never by a
    swap): it mounts the shared SectionRouter on the tab strip's region, and scales the overview's
    live / draft previews. Previews are real pages framed at desktop width (1280px) and scaled down to the
    frame; this runs on load, on resize and after every swap (the `section-router:updated` event).
--}}
@verbatim
<script>
    (function () {
        'use strict';

        var content = document.getElementById('website-content');

        if (!content) {
            return;
        }

        function fitPreviews(root) {
            Array.prototype.forEach.call((root || document).querySelectorAll('[data-site-preview]'), function (frame) {
                var box = frame.parentElement;
                var width = box.clientWidth;

                if (!width) {
                    return;
                }

                frame.style.width = '1280px';
                frame.style.height = '800px';
                frame.style.transformOrigin = '0 0';
                frame.style.transform = 'scale(' + (width / 1280) + ')';
                box.classList.add('is-fitted');
            });
        }

        window.SectionRouter.mount({
            content: content,
            linkSelector: 'a[data-website-nav]',
            liveRegion: document.getElementById('website-live-status'),
            headingSelector: '.content-header-title',
            activeClass: 'active'
        });

        // In-page shortcuts to a tab (the overview's Edit -> Settings) reuse that tab's own link, so they swap the
        // centre exactly like the tab strip does; without JavaScript they stay ordinary links.
        content.addEventListener('click', function (event) {
            var go = event.target.closest ? event.target.closest('[data-website-go]') : null;

            if (!go || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            var tab = document.querySelector('a[data-website-nav][data-section-key="' + go.getAttribute('data-website-go') + '"]');

            if (tab) {
                event.preventDefault();
                tab.click();
            }
        });

        // On a narrow screen the tab strip scrolls sideways; keep the active tab inside it (horizontal only, so the page never jumps).
        function revealActiveTab() {
            var strip = document.querySelector('.website-tabs');
            var tab = strip ? strip.querySelector('.active') : null;

            if (!tab || strip.scrollWidth <= strip.clientWidth) {
                return;
            }

            strip.scrollLeft = tab.offsetLeft - (strip.clientWidth - tab.offsetWidth) / 2;
        }

        content.addEventListener('section-router:updated', function () { fitPreviews(content); revealActiveTab(); });
        window.addEventListener('resize', function () { fitPreviews(content); });
        fitPreviews(content);
        revealActiveTab();
        window.addEventListener('load', revealActiveTab);
    })();
</script>
@endverbatim
