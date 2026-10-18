/*
 * MotionGrove marketing website — lightweight interactions. No dependencies.
 * Every state is server-rendered; without JavaScript all panels are visible,
 * every video is a plain link to YouTube, and the menu is a native <details>.
 */
(function () {
  'use strict';

  var doc = document;

  // Tabs: <div data-tabs> with buttons [data-tab="n"] and panels [data-panel="n"].
  // Used for the homepage connected-system steps and the industry picker.
  function initTabs(root) {
    var buttons = [].slice.call(root.querySelectorAll('[data-tab]'));
    var panels = [].slice.call(root.querySelectorAll('[data-panel]'));
    function select(id) {
      buttons.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-tab') === id ? 'true' : 'false'); });
      panels.forEach(function (p) { p.classList.toggle('is-on', p.getAttribute('data-panel') === id); });
    }
    buttons.forEach(function (b) { b.addEventListener('click', function () { select(b.getAttribute('data-tab')); }); });
  }
  [].forEach.call(doc.querySelectorAll('[data-tabs]'), initTabs);

  // Filter: buttons [data-filter="all|motiongrove|jazmin_media"], items [data-context].
  [].forEach.call(doc.querySelectorAll('[data-filter-root]'), function (root) {
    var buttons = [].slice.call(root.querySelectorAll('[data-filter]'));
    var items = [].slice.call(root.querySelectorAll('[data-context]'));
    buttons.forEach(function (b) {
      b.addEventListener('click', function () {
        var f = b.getAttribute('data-filter');
        buttons.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        items.forEach(function (i) { i.hidden = !(f === 'all' || i.getAttribute('data-context') === f); });
      });
    });
  });

  // Click-to-load YouTube: nothing from YouTube is requested for the player until the visitor clicks.
  [].forEach.call(doc.querySelectorAll('[data-video]'), function (box) {
    var button = box.querySelector('[data-video-play]');
    if (!button) { return; }
    button.addEventListener('click', function () {
      var src = box.getAttribute('data-video');
      if (!/^https:\/\/www\.youtube-nocookie\.com\/embed\/[A-Za-z0-9_-]{11}\?autoplay=1$/.test(src)) { return; }
      var frame = doc.createElement('iframe');
      frame.src = src;
      frame.title = box.getAttribute('data-video-title') || 'Customer story video';
      frame.loading = 'lazy';
      frame.allow = 'autoplay; encrypted-media; picture-in-picture';
      frame.allowFullscreen = true;
      box.textContent = '';
      box.appendChild(frame);
      frame.focus();
    });
  });

  // Mobile menu: close on Escape and when a link is chosen.
  [].forEach.call(doc.querySelectorAll('details.mg-menu'), function (menu) {
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary').focus(); } });
    menu.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('a')) { menu.open = false; } });
  });
})();
