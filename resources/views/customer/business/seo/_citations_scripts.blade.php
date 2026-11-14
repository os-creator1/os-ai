{{-- Citations dashboard behaviour: static, no dynamic output. Opens a drawer for a clicked row and re-opens a rejected save's drawer. --}}
<script>
        // Listing kit: copy one confirmed value (or all available ones). Falls back to a textarea where the
        // Clipboard API is unavailable. Shows the design's confirmation state.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-copy]') : null;
            if (!btn) { return; }
            var value = btn.getAttribute('data-copy');
            var all = btn.hasAttribute('data-all');
            var toast = document.getElementById('cgToast');
            var done = function () {
                if (!all) {
                    var label = btn.querySelector('span'); var use = btn.querySelector('use'); var t = label ? label.textContent : '';
                    btn.classList.add('cg-done'); if (use) { use.setAttribute('href', '#cg-i-check'); } if (label) { label.textContent = 'Copied'; }
                    setTimeout(function () { btn.classList.remove('cg-done'); if (use) { use.setAttribute('href', '#cg-i-copy'); } if (label) { label.textContent = t; } }, 1600);
                }
                if (toast) { toast.querySelector('span').textContent = all ? 'All available details copied' : 'Copied to clipboard'; toast.classList.add('cg-show'); clearTimeout(toast._h); toast._h = setTimeout(function () { toast.classList.remove('cg-show'); }, 2200); }
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(done, function () {});
            } else {
                var ta = document.createElement('textarea'); ta.value = value; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); done(); } catch (err) {} document.body.removeChild(ta);
            }
        });
        (function () {
            // A rejected save re-opens that directory's drawer with its errors.
            if (typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
                document.querySelectorAll('[data-open-on-load]').forEach(function (el) {
                    bootstrap.Offcanvas.getOrCreateInstance(el).show();
                });
            }

            // Filters and search are local: every row is already on the page.
            var page = document.querySelector('.cg-page');
            var rows = Array.prototype.slice.call(document.querySelectorAll('.cg-rows .cg-row[data-bucket]'));
            var chips = document.querySelectorAll('.cg-chip[data-filter]');
            var search = document.querySelector('[data-role=citation-search]');
            var emptyNote = document.querySelector('[data-role=citation-filter-empty]');
            var inv = document.getElementById('cgInv');
            var active = 'all';

            var matches = function (row) {
                var term = search ? search.value.trim().toLowerCase() : '';
                if (term !== '' && (row.getAttribute('data-name') || '').indexOf(term) === -1) { return false; }
                switch (active) {
                    case 'niche': return row.getAttribute('data-niche') === '1';
                    case 'custom': return row.getAttribute('data-custom') === '1';
                    case 'all': return true;
                    default: return row.getAttribute('data-bucket') === active;
                }
            };

            var apply = function () {
                var visible = 0;
                rows.forEach(function (row) {
                    var show = matches(row);
                    row.style.display = show ? '' : 'none';
                    if (show) { visible++; }
                });
                document.querySelectorAll('.cg-group').forEach(function (group) {
                    var any = Array.prototype.some.call(group.querySelectorAll('.cg-row[data-bucket]'), function (r) { return r.style.display !== 'none'; });
                    group.style.display = any ? '' : 'none';
                });
                if (emptyNote) { emptyNote.style.display = visible === 0 ? '' : 'none'; }
            };

            var setFilter = function (key) {
                active = key;
                chips.forEach(function (c) { c.setAttribute('aria-pressed', c.getAttribute('data-filter') === key ? 'true' : 'false'); });
                apply();
            };

            chips.forEach(function (chip) { chip.addEventListener('click', function () { setFilter(chip.getAttribute('data-filter')); }); });
            if (search) { search.addEventListener('input', apply); }

            // The progress legend opens the inventory on that status.
            document.querySelectorAll('[data-jump]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (inv) { inv.open = true; }
                    setFilter(btn.getAttribute('data-jump'));
                    if (inv && inv.scrollIntoView) { inv.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                });
            });

            // Expandable rows (one real <button> per row, keyboard accessible).
            document.querySelectorAll('[data-cg-row-toggle]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var row = btn.closest('.cg-row');
                    var open = !row.classList.contains('cg-open');
                    row.classList.toggle('cg-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            });

            // Location menu.
            var locBtn = document.getElementById('cgLocBtn');
            var locMenu = document.getElementById('cgLocMenu');
            if (locBtn && locMenu) {
                locBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var o = !locMenu.classList.contains('cg-open');
                    locMenu.classList.toggle('cg-open', o);
                    locBtn.setAttribute('aria-expanded', o ? 'true' : 'false');
                });
                document.addEventListener('click', function (e) { if (!locMenu.contains(e.target)) { locMenu.classList.remove('cg-open'); locBtn.setAttribute('aria-expanded', 'false'); } });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { locMenu.classList.remove('cg-open'); locBtn.setAttribute('aria-expanded', 'false'); } });
            }
        })();
    </script>
