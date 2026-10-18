{{--
    SectionRouter — the one reusable "change only the centre content" mechanism for a module whose pages
    share a stable shell (sidebar, top bar, module header, tab strip). It is the Calendar's tab router,
    lifted out so that more than one module can use exactly the same contract:

      - plain left-clicks on the module's tab links request the destination with `?fragment=1`; the SAME
        controller action answers with just the section region (same tenancy / entitlement / permission
        gates, same data — there is no second read surface), and only that region is replaced;
      - the real URL is kept with history.pushState; Back / Forward re-fetch the section; a deep link or a
        refresh is the ordinary full page;
      - every failure path (an error, a redirect such as an expired session, a response with no region)
        falls back to ordinary navigation, so the links remain real links with or without this script;
      - stale protection: each navigation takes a generation number and aborts the previous request, so
        the last click always wins;
      - loading feedback: the old section stays until the new one is ready; only after 150 ms is it dimmed
        and the MotionGrove loader shown (a fast response never flashes either); the region is aria-busy
        meanwhile, and a live region announces the new section.

    It is OPT-IN per page: nothing is intercepted until a module calls SectionRouter.mount(). It is not a
    global link interceptor and not an SPA — the shell is never replaced and no page script is re-run
    (a section must not rely on inline scripts; use delegated listeners or the `section-router:updated`
    event, which bubbles from the region after every swap).

    mount(options):
      content       the region element (required; its id is how the new section is found in a response)
      linkSelector  links to intercept, e.g. 'a[data-website-nav]'
      sectionAttr   attribute on the region AND on each tab link naming the section ('data-section-key')
      titleAttr     attribute on the region holding the page title ('data-section-title')
      headingSelector  the page's visible heading, kept in step with the title ('.content-header-title'; optional)
      linkKeyAttr   attribute on a tab link holding its section key (defaults to sectionAttr)
      activeClass   class marking the active tab link ('is-active'); aria-current is managed too
      liveRegion    element announcing "<title> loaded" (optional)
      transformHref(href, mode)        may rewrite the URL before it is requested
      beforeSwap(next)                 called with the incoming region just before the swap
      afterSwap({ key, title, mode })  called after the swap
      onFail()                         called before falling back to ordinary navigation

    The script below sits in a verbatim block because it is JavaScript and Blade must not read it. Do not
    write that directive's name inside this comment: Blade pairs the first occurrence it finds with the
    closing one before it looks for comments, which swallows this comment's terminator.
--}}
@verbatim
<script>
    (function () {
        'use strict';

        if (window.SectionRouter) {
            return;
        }

        var LOADING_DELAY = 150;

        function motionGrove() {
            var grove = document.createElement('span');

            grove.className = 'motiongrove';
            grove.setAttribute('role', 'presentation');
            grove.setAttribute('aria-hidden', 'true');
            grove.innerHTML = '<i></i><i></i><i></i>';

            return grove;
        }

        function mount(options) {
            var content = options && options.content;

            if (!content) {
                return null;
            }

            if (content.__sectionRouter) {
                return content.__sectionRouter;
            }

            var cfg = {
                linkSelector: options.linkSelector,
                sectionAttr: options.sectionAttr || 'data-section-key',
                titleAttr: options.titleAttr || 'data-section-title',
                headingSelector: options.headingSelector || '',
                activeClass: options.activeClass || 'is-active',
                liveRegion: options.liveRegion || null,
                transformHref: options.transformHref || function (href) { return href; },
                beforeSwap: options.beforeSwap || function () {},
                afterSwap: options.afterSwap || function () {},
                onFail: options.onFail || function () {}
            };

            cfg.linkKeyAttr = options.linkKeyAttr || cfg.sectionAttr;

            var generation = 0;
            var inflight = null;
            var loadingTimer = null;
            var titleSuffix = (document.title.match(/^.*?( [·-] .*)$/) || [])[1] || '';
            var loader = null;

            content.classList.add('mg-host');

            function setLoading(on) {
                clearTimeout(loadingTimer);

                if (on) {
                    loadingTimer = setTimeout(function () {
                        content.classList.add('mg-busy');
                        content.setAttribute('aria-busy', 'true');

                        if (!loader || loader.parentNode !== content) {
                            loader = motionGrove();
                            content.insertBefore(loader, content.firstChild);
                        }
                    }, LOADING_DELAY);

                    return;
                }

                content.classList.remove('mg-busy');
                content.removeAttribute('aria-busy');

                if (loader && loader.parentNode === content) {
                    content.removeChild(loader);
                }

                loader = null;
            }

            // mode: 'push' (a click), 'replace' (the screen decided) or 'none' (Back/Forward — the
            // browser already moved the history entry).
            function navigate(href, mode) {
                var target = mode === 'none' ? href : cfg.transformHref(href, mode);
                var ticket = ++generation;

                if (inflight) {
                    inflight.abort();
                }

                inflight = typeof AbortController === 'function' ? new AbortController() : null;
                setLoading(true);

                var fragmentUrl = new URL(target, window.location.href);
                fragmentUrl.searchParams.set('fragment', '1');

                var request = {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
                };

                if (inflight) {
                    request.signal = inflight.signal;
                }

                fetch(fragmentUrl.toString(), request)
                    .then(function (response) {
                        if (ticket !== generation) {
                            return null;
                        }

                        // A redirect (expired session, a stage that lives elsewhere) or an error is not a
                        // section: let the browser show the real page.
                        if (!response.ok || response.redirected) {
                            throw new Error('not-a-section');
                        }

                        return response.text();
                    })
                    .then(function (html) {
                        if (html === null || ticket !== generation) {
                            return;
                        }

                        var next = new DOMParser().parseFromString(html, 'text/html').getElementById(content.id);

                        if (!next) {
                            throw new Error('not-a-section');
                        }

                        swap(next, target, mode);
                    })
                    .catch(function () {
                        if (ticket !== generation) {
                            return;
                        }

                        setLoading(false);
                        cfg.onFail();
                        window.location.assign(target);
                    });
            }

            function swap(next, href, mode) {
                cfg.beforeSwap(next);

                var key = next.getAttribute(cfg.sectionAttr) || '';
                var title = next.getAttribute(cfg.titleAttr) || '';

                setLoading(false);

                content.innerHTML = next.innerHTML;
                content.setAttribute(cfg.sectionAttr, key);
                content.setAttribute(cfg.titleAttr, title);

                if (title) {
                    document.title = title + titleSuffix;

                    var heading = cfg.headingSelector ? document.querySelector(cfg.headingSelector) : null;

                    if (heading) {
                        heading.textContent = title;
                    }
                }

                var links = document.querySelectorAll(cfg.linkSelector);

                for (var i = 0; i < links.length; i++) {
                    var wanted = links[i].getAttribute(cfg.linkKeyAttr);

                    if (wanted === null) {
                        continue;
                    }

                    var active = wanted === key;

                    links[i].classList.toggle(cfg.activeClass, active);

                    if (active) {
                        links[i].setAttribute('aria-current', 'page');
                    } else {
                        links[i].removeAttribute('aria-current');
                    }
                }

                if (mode === 'push') {
                    window.history.pushState({ sectionRouter: key }, '', href);
                } else if (mode === 'replace') {
                    window.history.replaceState({ sectionRouter: key }, '', href);
                }

                // A short, quiet fade-in on the new section (skipped under reduced motion in CSS).
                content.classList.remove('mg-enter');
                void content.offsetWidth;
                content.classList.add('mg-enter');

                content.dispatchEvent(new CustomEvent('section-router:updated', { bubbles: true, detail: { key: key, title: title, mode: mode } }));
                cfg.afterSwap({ key: key, title: title, mode: mode });

                if (cfg.liveRegion && title) {
                    cfg.liveRegion.textContent = title + ' loaded';
                }
            }

            content.addEventListener('animationend', function (event) {
                if (event.target === content) {
                    content.classList.remove('mg-enter');
                }
            });

            document.addEventListener('click', function (event) {
                if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }

                var link = event.target.closest ? event.target.closest(cfg.linkSelector) : null;

                if (!link || (link.target && link.target !== '_self') || link.hasAttribute('download')) {
                    return;
                }

                var url = new URL(link.href, window.location.href);

                if (url.origin !== window.location.origin) {
                    return;
                }

                event.preventDefault();

                var sameAsCurrent = url.pathname + url.search === window.location.pathname + window.location.search;

                navigate(link.href, sameAsCurrent ? 'replace' : 'push');
            });

            window.addEventListener('popstate', function () {
                navigate(window.location.href, 'none');
            });

            window.history.replaceState({ sectionRouter: content.getAttribute(cfg.sectionAttr) }, '', window.location.href);

            content.__sectionRouter = { navigate: navigate };

            return content.__sectionRouter;
        }

        window.SectionRouter = { mount: mount, motionGrove: motionGrove };
    })();
</script>
@endverbatim
