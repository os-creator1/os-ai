{{-- Citations dashboard behaviour: static, no dynamic output. Opens a drawer for a clicked row and re-opens a rejected save's drawer. --}}
<script>
        (function () {
            if (typeof bootstrap === 'undefined' || !bootstrap.Offcanvas) { return; }

            // A rejected save re-opens that directory's drawer with its errors.
            document.querySelectorAll('[data-open-on-load]').forEach(function (el) {
                bootstrap.Offcanvas.getOrCreateInstance(el).show();
            });

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
