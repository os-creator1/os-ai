{{-- Citations dashboard behaviour: static, no dynamic output. Opens a drawer for a clicked row and re-opens a rejected save's drawer. --}}
<script>
        (function () {
            if (typeof bootstrap === 'undefined' || !bootstrap.Offcanvas) { return; }

            // A rejected save re-opens that directory's drawer with its errors.
            document.querySelectorAll('[data-open-on-load]').forEach(function (el) {
                bootstrap.Offcanvas.getOrCreateInstance(el).show();
            });

            // Filters and search are local: every row is already on the page.
            var rows = Array.prototype.slice.call(document.querySelectorAll('.cz-list .cz-row[data-importance]'));
            var buttons = document.querySelectorAll('.cz-filter');
            var search = document.querySelector('[data-role=citation-search]');
            var emptyNote = document.querySelector('[data-role=citation-filter-empty]');
            var active = 'all';

            var matches = function (row) {
                var term = search ? search.value.trim().toLowerCase() : '';
                if (term !== '' && (row.getAttribute('data-name') || '').indexOf(term) === -1) { return false; }
                switch (active) {
                    case 'essential': return row.getAttribute('data-importance') === 'essential' && row.getAttribute('data-custom') !== '1';
                    case 'recommended': return row.getAttribute('data-importance') === 'recommended' && row.getAttribute('data-custom') !== '1';
                    case 'attention': return row.getAttribute('data-attention') === '1';
                    case 'notchecked': return row.getAttribute('data-notchecked') === '1';
                    case 'accurate': return row.getAttribute('data-state') === 'accurate';
                    case 'custom': return row.getAttribute('data-custom') === '1';
                    default: return true;
                }
            };

            var apply = function () {
                var visible = 0;
                rows.forEach(function (row) {
                    var show = matches(row);
                    row.style.display = show ? '' : 'none';
                    if (show) { visible++; }
                });
                document.querySelectorAll('.cz-group').forEach(function (group) {
                    var any = Array.prototype.some.call(group.querySelectorAll('.cz-row[data-importance]'), function (r) { return r.style.display !== 'none'; });
                    group.style.display = any ? '' : 'none';
                });
                if (emptyNote) { emptyNote.classList.toggle('d-none', visible !== 0); }
            };

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    active = button.getAttribute('data-filter');
                    buttons.forEach(function (b) { b.classList.toggle('is-active', b === button); });
                    apply();
                });
            });
            if (search) { search.addEventListener('input', apply); }

            // The whole row opens its drawer; real links and buttons keep working.
            document.querySelectorAll('.cz-row[data-drawer]').forEach(function (row) {
                row.addEventListener('click', function (event) {
                    if (event.target.closest('a, button, input, select, textarea, label')) { return; }
                    var target = document.querySelector(row.getAttribute('data-drawer'));
                    if (target) { bootstrap.Offcanvas.getOrCreateInstance(target).toggle(); }
                });
            });
        })();
    </script>
