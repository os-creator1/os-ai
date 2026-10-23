/* MotionGrove Legal Center: print, contents scroll-spy, mobile contents, back to top. */
(function () {
  'use strict';
  var print = document.querySelector('[data-lg-print]');
  if (print) print.addEventListener('click', function () { window.print(); });

  var toc = document.querySelector('[data-lg-toc]');
  var narrow = window.matchMedia('(max-width: 1023px)');
  function fitToc() { if (toc) { if (narrow.matches) toc.removeAttribute('open'); else toc.setAttribute('open', ''); } }
  fitToc();
  if (narrow.addEventListener) narrow.addEventListener('change', fitToc);

  var links = toc ? toc.querySelectorAll('a[href^="#"]') : [];
  if (links.length && 'IntersectionObserver' in window) {
    var byId = {};
    links.forEach(function (a) { byId[a.getAttribute('href').slice(1)] = a; });
    var obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting && byId[e.target.id]) {
          links.forEach(function (a) { a.removeAttribute('aria-current'); });
          byId[e.target.id].setAttribute('aria-current', 'true');
        }
      });
    }, { rootMargin: '-80px 0px -70% 0px' });
    document.querySelectorAll('.lg-body h2[id]').forEach(function (h) { obs.observe(h); });
    links.forEach(function (a) {
      a.addEventListener('click', function () { if (narrow.matches) toc.removeAttribute('open'); });
    });
  }

  var top = document.querySelector('[data-lg-top]');
  if (top) top.addEventListener('click', function (e) {
    e.preventDefault();
    window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    var t = document.getElementById('lg-title'); if (t) { t.setAttribute('tabindex', '-1'); t.focus({ preventScroll: true }); }
  });
})();
