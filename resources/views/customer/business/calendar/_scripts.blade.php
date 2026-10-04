{{--
    The Calendar module's one client script, emitted once per full page load
    by _frame.blade.php (never by a section swap, so it never re-runs).

    1. SECTION ROUTER — plain left-clicks on `a[data-calendar-nav]` (the three
       tabs and the schedule's own Today / previous / next / Day / Week
       links) fetch the destination with `?fragment=1`, which the SAME
       controller action answers with just its section, and swap it into
       #calendar-content. The Business OS shell is never touched or reloaded.
       The real URL is kept with history.pushState, Back/Forward re-fetch the
       section, and every failure path falls back to ordinary navigation —
       the links remain real links with or without this script.
         - stale protection: each navigation takes a generation number and
           aborts the previous request; a response that is no longer the
           latest is discarded, so the last click always wins.
         - loading feedback: the old section stays until the new one is
           ready; only after 150 ms is it dimmed with a small spinner.

    2. GRID — mounts FullCalendar for the schedule section (loading its
       stylesheet/script on demand when the schedule was reached from another
       tab) and picks how many days the week view shows from the width the
       grid actually has.

    The script below sits in a verbatim block because it is JavaScript and
    Blade must not read it. Do not write that directive's name inside this
    comment: Blade pairs the first occurrence it finds with the closing one
    before it looks for comments, which swallows this comment's terminator and
    prints the whole comment and script as page text.
--}}
@verbatim
<script>
    (function () {
        'use strict';

        var content = document.getElementById('calendar-content');

        if (!content || window.CalendarSections) {
            return;
        }

        // ---------------------------------------------------------------
        // Visible-day rule. A week view may widen from 7 to 10 or 14 days,
        // but only while every day column keeps at least MIN_COLUMN px; the
        // day count follows the grid's real width, never the viewport's.
        // Below that the classic 7 days stay and, if they would be narrower
        // than SCROLL_COLUMN, the grid scrolls sideways instead of squeezing.
        // ---------------------------------------------------------------
        var AXIS = 56;
        var MIN_COLUMN = 150;
        var SCROLL_COLUMN = 110;
        var DAY_OPTIONS = [14, 10, 7];

        function visibleDaysFor(width) {
            var columns = Math.floor((width - AXIS) / MIN_COLUMN);

            for (var i = 0; i < DAY_OPTIONS.length; i++) {
                if (columns >= DAY_OPTIONS[i]) {
                    return DAY_OPTIONS[i];
                }
            }

            return 7;
        }

        var generation = 0;
        var inflight = null;
        var loadingTimer = null;
        var resizeTimer = null;
        var resizeObserver = null;
        var calendar = null;
        var preferredDays = null;
        var lastReconcile = null;
        var titleSuffix = (document.title.match(/^.*?( [·-] .*)$/) || [])[1] || '';

        // ---------------------------------------------------------------
        // Section router
        // ---------------------------------------------------------------
        function setLoading(on) {
            clearTimeout(loadingTimer);

            if (on) {
                loadingTimer = setTimeout(function () {
                    content.classList.add('is-loading');
                    content.setAttribute('aria-busy', 'true');
                }, 150);

                return;
            }

            content.classList.remove('is-loading');
            content.removeAttribute('aria-busy');
        }

        // The Monday on or before a YYYY-MM-DD date (calendar arithmetic only,
        // no time zone involved) — the same week start the server uses.
        function weekStartOf(isoDay) {
            var parts = isoDay.split('-');
            var d = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));

            d.setUTCDate(d.getUTCDate() - ((d.getUTCDay() + 6) % 7));

            return isoDate(d);
        }

        // A schedule link that does not say how many days to show (the tab
        // strip, Day -> Week) inherits the count this screen last settled on,
        // so returning to the grid does not flash the wrong range. A span that
        // is not whole weeks starts exactly where it is anchored, so the
        // anchor becomes that week's Monday rather than, say, a Saturday.
        function withPreferredDays(href) {
            var url = new URL(href, window.location.href);

            if (preferredDays && preferredDays !== 7 && /\/schedule$/.test(url.pathname)
                && url.searchParams.get('view') !== 'day' && !url.searchParams.has('days')) {
                url.searchParams.set('days', String(preferredDays));

                if (preferredDays % 7 !== 0 && /^\d{4}-\d{2}-\d{2}$/.test(url.searchParams.get('date') || '')) {
                    url.searchParams.set('date', weekStartOf(url.searchParams.get('date')));
                }
            }

            return url.toString();
        }

        // mode: 'push' (a click), 'replace' (the screen decided, e.g. a wider
        // range) or 'none' (Back/Forward — the browser already moved the entry).
        function navigate(href, mode) {
            var target = mode === 'none' ? href : withPreferredDays(href);
            var ticket = ++generation;

            if (inflight) {
                inflight.abort();
            }

            inflight = typeof AbortController === 'function' ? new AbortController() : null;
            setLoading(true);

            var fragmentUrl = new URL(target, window.location.href);
            fragmentUrl.searchParams.set('fragment', '1');

            var options = {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
            };

            if (inflight) {
                options.signal = inflight.signal;
            }

            fetch(fragmentUrl.toString(), options)
                .then(function (response) {
                    if (ticket !== generation) {
                        return null;
                    }

                    // A redirect (expired session, location picker) or an error is
                    // not a section: let the browser show the real page.
                    if (!response.ok || response.redirected) {
                        throw new Error('not-a-section');
                    }

                    return response.text();
                })
                .then(function (html) {
                    if (html === null || ticket !== generation) {
                        return;
                    }

                    var next = new DOMParser().parseFromString(html, 'text/html').getElementById('calendar-content');

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
                    content.classList.remove('is-reconciling');
                    window.location.assign(target);
                });
        }

        function swap(next, href, mode) {
            destroyGrid();

            var key = next.getAttribute('data-calendar-section') || '';
            var title = next.getAttribute('data-calendar-title') || '';

            content.innerHTML = next.innerHTML;
            content.setAttribute('data-calendar-section', key);
            content.setAttribute('data-calendar-title', title);

            if (title) {
                document.title = title + titleSuffix;
            }

            var links = document.querySelectorAll('.calendar-subnav-link[data-calendar-key]');

            for (var i = 0; i < links.length; i++) {
                var active = links[i].getAttribute('data-calendar-key') === key;

                links[i].classList.toggle('is-active', active);

                if (active) {
                    links[i].setAttribute('aria-current', 'page');
                } else {
                    links[i].removeAttribute('aria-current');
                }
            }

            if (mode === 'push') {
                window.history.pushState({ calendarSection: key }, '', href);
            } else if (mode === 'replace') {
                window.history.replaceState({ calendarSection: key }, '', href);
            }

            setLoading(false);
            initSection();

            var status = document.getElementById('calendar-live-status');

            if (status && title) {
                status.textContent = title + ' loaded';
            }
        }

        document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            var link = event.target.closest ? event.target.closest('a[data-calendar-nav]') : null;

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

        window.history.replaceState({ calendarSection: content.getAttribute('data-calendar-section') }, '', window.location.href);

        // ---------------------------------------------------------------
        // FullCalendar (v5.7.2, vendored, no CSS injection of its own)
        // ---------------------------------------------------------------
        var fcWaiters = [];
        var fcLoading = false;

        function whenFullCalendar(callback) {
            if (typeof FullCalendar !== 'undefined') {
                callback();

                return;
            }

            fcWaiters.push(callback);

            if (fcLoading) {
                return;
            }

            fcLoading = true;

            var cssHref = content.getAttribute('data-fc-css');
            var jsSrc = content.getAttribute('data-fc-js');

            if (cssHref && !document.querySelector('link[href="' + cssHref + '"]')) {
                var link = document.createElement('link');

                link.rel = 'stylesheet';
                link.href = cssHref;
                document.head.appendChild(link);
            }

            var script = document.createElement('script');

            script.src = jsSrc;
            script.onload = function () {
                fcLoading = false;

                var waiting = fcWaiters;

                fcWaiters = [];
                waiting.forEach(function (fn) { fn(); });
            };
            script.onerror = function () {
                // The server-rendered agenda list carries the same data.
                fcLoading = false;
                fcWaiters = [];
            };
            document.head.appendChild(script);
        }

        function destroyGrid() {
            clearTimeout(resizeTimer);

            if (resizeObserver) {
                resizeObserver.disconnect();
                resizeObserver = null;
            }

            if (calendar) {
                calendar.destroy();
                calendar = null;
            }
        }

        function pad(n) {
            return (n < 10 ? '0' : '') + n;
        }

        function isoDate(d) {
            return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
        }

        // ---------------------------------------------------------------
        // Event layout by duration. An event's box stays exactly as tall as
        // its duration (about 1.15 px per minute at the current slot height
        // — 15 min is ~17 px, 20 min ~23 px, 30 min ~35 px), so a short
        // appointment is never padded out to look longer than it is.
        // Instead the CONTENT adapts: at or under COMPACT_MAX_MINUTES the
        // padding and line height shrink; at or under SINGLE_LINE_MAX_MINUTES
        // (boxes too short for two lines) the time and the title share one
        // line, time first, and it is the title that is cut with an
        // ellipsis — never the time. The full details are always on the
        // event's tooltip. If the slot height in _styles.blade.php changes,
        // revisit these two numbers.
        // ---------------------------------------------------------------
        var COMPACT_MAX_MINUTES = 30;
        var SINGLE_LINE_MAX_MINUTES = 25;
        var STATUS_LABELS = { scheduled: 'Scheduled', completed: 'Completed', cancelled: 'Cancelled', no_show: 'No-show' };

        function clock(d) {
            return pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
        }

        function eventMinutes(event) {
            return event.end ? Math.round((event.end - event.start) / 60000) : 60;
        }

        function eventTimeRange(event) {
            return clock(event.start) + '–' + (event.end ? clock(event.end) : '');
        }

        function eventClasses(event) {
            var minutes = eventMinutes(event);
            var classes = [];

            if (minutes <= COMPACT_MAX_MINUTES) {
                classes.push('calendar-event--compact');
            }

            if (minutes <= SINGLE_LINE_MAX_MINUTES) {
                classes.push('calendar-event--single');
            }

            return classes;
        }

        function eventBody(event) {
            var body = document.createElement('div');
            var time = document.createElement('span');
            var title = document.createElement('span');

            var start = document.createElement('span');
            var end = document.createElement('span');

            body.className = 'calendar-event-body';
            time.className = 'calendar-event-time';
            start.textContent = clock(event.start);
            end.className = 'calendar-event-time-end';
            end.textContent = '–' + (event.end ? clock(event.end) : '');
            time.appendChild(start);
            time.appendChild(end);
            title.className = 'calendar-event-title';
            title.textContent = event.title;
            body.appendChild(time);
            body.appendChild(title);

            return body;
        }

        function renderedDays(mount) {
            return parseInt(mount.getAttribute('data-days'), 10) || 7;
        }

        // The week view was rendered for a different day count than this
        // grid can show: ask for the right one (replacing, not adding, a
        // history entry — it is the screen's decision, not the user's).
        function reconcile(want, mount) {
            if (lastReconcile === want) {
                return false;
            }

            lastReconcile = want;
            preferredDays = want;

            var url = new URL(window.location.href);

            url.searchParams.set('view', 'week');
            // Anchor the new range on where the current one STARTS, so widening
            // or narrowing keeps the first day on screen where it was.
            url.searchParams.set('date', mount.getAttribute('data-range-start'));

            if (want === 7) {
                url.searchParams.delete('days');
            } else {
                url.searchParams.set('days', String(want));
            }

            content.classList.add('is-reconciling');
            navigate(url.toString(), 'replace');

            return true;
        }

        function initSection() {
            var mount = content.querySelector('#calendar-grid');

            content.classList.remove('is-reconciling');

            if (!mount) {
                return;
            }

            if (mount.getAttribute('data-view') === 'week') {
                var wrap = document.getElementById('calendar-grid-wrap');
                var want = visibleDaysFor(wrap.clientWidth);

                if (want !== renderedDays(mount) && reconcile(want, mount)) {
                    return;
                }

                lastReconcile = null;
                preferredDays = renderedDays(mount);
            }

            whenFullCalendar(function () {
                if (content.querySelector('#calendar-grid') === mount && !calendar) {
                    mountGrid(mount);
                }
            });
        }

        function mountGrid(mount) {
            var wrap = document.getElementById('calendar-grid-wrap');
            var isWeek = mount.getAttribute('data-view') !== 'day';
            var days = renderedDays(mount);
            var todayLocal = mount.getAttribute('data-today');
            var events = [];

            try {
                events = JSON.parse(content.querySelector('[data-role="calendar-events"]').textContent);
            } catch (e) {
                events = [];
            }

            mount.style.minWidth = (AXIS + (isWeek ? days : 1) * SCROLL_COLUMN) + 'px';

            // Grid height is a static NUMBER computed once, before render —
            // never 'auto'/'parent' and never combined with `expandRows`, which
            // reproducibly sends FullCalendar 5.7.2 into an endless
            // resize/reflow loop inside this theme's flex card. The grid uses
            // the rest of the viewport below its own top edge; the hours scroll
            // inside it.
            var available = window.innerHeight - wrap.getBoundingClientRect().top - 24;
            var gridHeight = Math.max(560, Math.min(available, 1200));

            var options = {
                // The events carry the Business's LOCAL wall-clock with no
                // offset. FullCalendar 5.7.2 supports only 'local' and 'UTC'
                // without a timezone plugin, so 'UTC' displays those strings
                // verbatim — a named Business timezone shown correctly whatever
                // the viewer's browser timezone is.
                timeZone: 'UTC',

                headerToolbar: false,      // navigation is the server-rendered links above
                firstDay: 1,
                allDaySlot: false,
                nowIndicator: false,       // "now" would be computed in the browser's zone, not the Business's
                slotMinTime: '00:00:00',
                slotMaxTime: '24:00:00',
                scrollTime: '08:00:00',
                height: gridHeight,
                events: events,

                // Duration-aware event layout (see COMPACT_MAX_MINUTES above):
                // 24-hour start–end, then the title, with the full details on
                // the tooltip.
                eventClassNames: function (arg) {
                    return eventClasses(arg.event);
                },
                eventContent: function (arg) {
                    return { domNodes: [eventBody(arg.event)] };
                },
                eventDidMount: function (info) {
                    var status = STATUS_LABELS[info.event.extendedProps.status];

                    info.el.setAttribute('title', eventTimeRange(info.event) + ' · ' + info.event.title + (status ? ' (' + status + ')' : ''));
                },

                // FullCalendar's own "today" highlight compares against the
                // BROWSER's date; the Business's own today is computed
                // server-side (`data-today`) and applied here.
                dayHeaderClassNames: function (arg) {
                    return isoDate(arg.date) === todayLocal ? ['calendar-is-today'] : [];
                },
                dayCellClassNames: function (arg) {
                    return isoDate(arg.date) === todayLocal ? ['calendar-is-today'] : [];
                },

                // An empty slot starts a booking at that Location, date and
                // time. The click only builds a URL; the server re-checks everything.
                dateClick: function (info) {
                    var d = info.date;

                    window.location.href = mount.getAttribute('data-create-url')
                        + '?date=' + isoDate(d) + '&time=' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
                }
            };

            if (isWeek) {
                // The server already chose the range (and its first day); a
                // plain N-day duration view starts exactly there.
                options.initialView = 'calendarRange';
                options.views = { calendarRange: { type: 'timeGrid', duration: { days: days } } };
                options.initialDate = mount.getAttribute('data-range-start');
            } else {
                options.initialView = 'timeGridDay';
                options.initialDate = mount.getAttribute('data-date');
            }

            calendar = new FullCalendar.Calendar(mount, options);
            calendar.render();

            watchWidth(wrap);
        }

        function watchWidth(wrap) {
            if (typeof ResizeObserver !== 'function') {
                return;
            }

            var lastWidth = wrap.clientWidth;

            resizeObserver = new ResizeObserver(function () {
                var width = wrap.clientWidth;

                if (Math.abs(width - lastWidth) < 2) {
                    return;
                }

                lastWidth = width;
                clearTimeout(resizeTimer);

                resizeTimer = setTimeout(function () {
                    var mount = document.getElementById('calendar-grid');

                    if (!mount) {
                        return;
                    }

                    if (calendar) {
                        calendar.updateSize();
                    }

                    if (mount.getAttribute('data-view') === 'week') {
                        var want = visibleDaysFor(width);

                        if (want !== renderedDays(mount)) {
                            lastReconcile = null;
                            reconcile(want, mount);
                        }
                    }
                }, 250);
            });

            resizeObserver.observe(wrap);
        }

        window.CalendarSections = { visibleDaysFor: visibleDaysFor, navigate: navigate };

        initSection();
    })();
</script>
@endverbatim
