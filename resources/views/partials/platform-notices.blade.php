{{-- Platform notices + announcement banners for the signed-in customer.
     Loaded AFTER the page renders (one small GET) so it adds no query to any page,
     including the budget-pinned Home. The feed is re-read every minute and whenever the tab
     becomes visible again, so a cancelled or expired announcement disappears from an open page
     without a reload (the feed itself already excludes it the instant it is cancelled or its
     expiry passes). Text is inserted with textContent only. --}}
@auth
    @unless (Auth::user()->is_admin)
        <script>
            (function () {
                var url = @json(route('customer.platform-notices.feed'));
                var csrf = @json(csrf_token());
                var readUrl = @json(route('customer.platform-notices.read', ['notice' => '__ID__']));
                var dismissUrl = @json(route('customer.platform-announcements.dismiss', ['announcement' => '__ID__']));
                var REFRESH_MS = 60000;
                var baseBadge = null; // the badge count the page rendered with, before our notices

                function post(u) {
                    return fetch(u, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}, credentials: 'same-origin'});
                }

                function renderBanners(banners) {
                    var old = document.querySelector('[data-role=platform-banners]');
                    if (old) { old.remove(); }
                    var host = document.querySelector('.content-body') || document.querySelector('main');
                    if (!host || !banners.length) { return; }

                    var wrap = document.createElement('div');
                    wrap.setAttribute('data-role', 'platform-banners');
                    banners.forEach(function (b) {
                        var el = document.createElement('div');
                        el.className = 'alert alert-' + ({success: 'success', warning: 'warning', critical: 'danger'}[b.severity] || 'info') + ' d-flex align-items-start';
                        el.setAttribute('role', 'status');
                        el.setAttribute('data-role', 'platform-banner');
                        el.setAttribute('data-uid', b.uid);
                        var text = document.createElement('div');
                        text.className = 'flex-grow-1 p-1';
                        var h = document.createElement('strong'); h.textContent = b.title;
                        var p = document.createElement('div'); p.textContent = b.body; p.style.whiteSpace = 'pre-line';
                        text.appendChild(h); text.appendChild(p);
                        var x = document.createElement('button');
                        x.type = 'button'; x.className = 'btn-close mt-1 me-1'; x.setAttribute('aria-label', 'Dismiss');
                        x.addEventListener('click', function () { post(dismissUrl.replace('__ID__', b.uid)); el.remove(); });
                        el.appendChild(text); el.appendChild(x);
                        wrap.appendChild(el);
                    });
                    host.insertBefore(wrap, host.firstChild);
                }

                function renderNotices(notices) {
                    document.querySelectorAll('[data-role=platform-notice]').forEach(function (n) { n.remove(); });
                    var list = document.querySelector('.dropdown-notification .scrollable-container');
                    var bell = document.querySelector('.dropdown-notification > a, .dropdown-notification .nav-link');
                    var badge = bell ? bell.querySelector('.badge-up') : null;
                    if (baseBadge === null) { baseBadge = badge ? (parseInt(badge.textContent, 10) || 0) : 0; }

                    if (list) {
                        notices.forEach(function (n) {
                            var item = document.createElement('div');
                            item.className = 'list-item d-flex align-items-start p-1';
                            item.setAttribute('data-role', 'platform-notice');
                            var body = document.createElement('div'); body.className = 'list-item-body flex-grow-1';
                            var t = document.createElement('p'); t.className = 'media-heading mb-0'; var s = document.createElement('span'); s.className = 'fw-bolder'; s.textContent = n.title; t.appendChild(s);
                            var m = document.createElement('small'); m.className = 'notification-text'; m.textContent = n.message;
                            body.appendChild(t); body.appendChild(m);
                            var btn = document.createElement('button');
                            btn.type = 'button'; btn.className = 'btn btn-sm btn-link'; btn.textContent = 'Dismiss';
                            btn.addEventListener('click', function () { post(readUrl.replace('__ID__', n.id)); item.remove(); });
                            item.appendChild(body); item.appendChild(btn);
                            list.insertBefore(item, list.firstChild);
                        });
                    }

                    if (bell) {
                        var total = baseBadge + notices.length;
                        if (total > 0) {
                            if (!badge) { badge = document.createElement('span'); badge.className = 'badge rounded-pill bg-danger badge-up'; bell.appendChild(badge); }
                            badge.textContent = String(total);
                        } else if (badge) {
                            badge.remove();
                        }
                    }
                }

                function refresh() {
                    fetch(url, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .then(function (data) {
                            if (!data) { return; }
                            renderBanners(data.banners || []);
                            renderNotices(data.notices || []);
                        })
                        .catch(function () { /* a notice feed failure must never affect the page */ });
                }

                refresh();
                setInterval(function () { if (!document.hidden) { refresh(); } }, REFRESH_MS);
                document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });
                // Exposed so the page (and acceptance checks) can force a re-read.
                window.platformNoticesRefresh = refresh;
            })();
        </script>
    @endunless
@endauth
