{{-- Platform notices + announcement banners for the signed-in customer.
     Loaded AFTER the page renders (one small GET) so it adds no query to any page,
     including the budget-pinned Home. Text is inserted with textContent only. --}}
@auth
    @unless (Auth::user()->is_admin)
        <script>
            (function () {
                var url = @json(route('customer.platform-notices.feed'));
                var csrf = @json(csrf_token());
                var readUrl = @json(route('customer.platform-notices.read', ['notice' => '__ID__']));
                var dismissUrl = @json(route('customer.platform-announcements.dismiss', ['announcement' => '__ID__']));

                function post(u) {
                    return fetch(u, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}, credentials: 'same-origin'});
                }

                fetch(url, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (!data) { return; }

                        var host = document.querySelector('.content-body') || document.querySelector('main');
                        if (host && data.banners.length) {
                            var wrap = document.createElement('div');
                            wrap.setAttribute('data-role', 'platform-banners');
                            data.banners.forEach(function (b) {
                                var el = document.createElement('div');
                                el.className = 'alert alert-' + ({success: 'success', warning: 'warning', critical: 'danger'}[b.severity] || 'info') + ' d-flex align-items-start';
                                el.setAttribute('role', 'status');
                                el.setAttribute('data-role', 'platform-banner');
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

                        var list = document.querySelector('.dropdown-notification .scrollable-container');
                        if (list && data.notices.length) {
                            data.notices.forEach(function (n) {
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

                            var bell = document.querySelector('.dropdown-notification > a, .dropdown-notification .nav-link');
                            if (bell) {
                                var badge = bell.querySelector('.badge-up');
                                if (!badge) { badge = document.createElement('span'); badge.className = 'badge rounded-pill bg-danger badge-up'; badge.textContent = '0'; bell.appendChild(badge); }
                                badge.textContent = String((parseInt(badge.textContent, 10) || 0) + data.notices.length);
                            }
                        }
                    })
                    .catch(function () { /* a notice feed failure must never affect the page */ });
            })();
        </script>
    @endunless
@endauth
