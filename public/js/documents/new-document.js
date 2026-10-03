/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./resources/js/documents/editor/dom.js"
/*!**********************************************!*\
  !*** ./resources/js/documents/editor/dom.js ***!
  \**********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   append: () => (/* binding */ append),
/* harmony export */   clear: () => (/* binding */ clear),
/* harmony export */   debounce: () => (/* binding */ debounce),
/* harmony export */   el: () => (/* binding */ el),
/* harmony export */   icon: () => (/* binding */ icon),
/* harmony export */   qs: () => (/* binding */ qs),
/* harmony export */   qsa: () => (/* binding */ qsa),
/* harmony export */   uuid: () => (/* binding */ uuid)
/* harmony export */ });
// Contract 17B — tiny DOM helpers shared by every editor module.

/**
 * el('button', {class: 'de-btn', 'data-role': 'x', onclick: fn}, ['text', node])
 * Attributes with a null/false/undefined value are skipped; `on*` are listeners.
 */
function el(tag, attrs, children) {
  var node = document.createElement(tag);
  Object.keys(attrs || {}).forEach(function (key) {
    var value = attrs[key];
    if (value === null || value === undefined || value === false) {
      return;
    }
    if (key.indexOf('on') === 0 && typeof value === 'function') {
      node.addEventListener(key.slice(2), value);
    } else if (key === 'class') {
      node.className = value;
    } else if (key === 'text') {
      node.textContent = value;
    } else if (key === 'value') {
      node.value = value;
    } else if (value === true) {
      node.setAttribute(key, '');
    } else {
      node.setAttribute(key, String(value));
    }
  });
  append(node, children);
  return node;
}
function append(node, children) {
  (Array.isArray(children) ? children : [children]).forEach(function (child) {
    if (child === null || child === undefined || child === false) {
      return;
    }
    node.appendChild(typeof child === 'string' || typeof child === 'number' ? document.createTextNode(String(child)) : child);
  });
  return node;
}
function clear(node) {
  while (node.firstChild) {
    node.removeChild(node.firstChild);
  }
  return node;
}
function qs(root, selector) {
  return root.querySelector(selector);
}
function qsa(root, selector) {
  return Array.prototype.slice.call(root.querySelectorAll(selector));
}

/** A fresh clone of a server-rendered icon (<template id="de-icon-NAME">). */
function icon(name) {
  var template = document.getElementById('de-icon-' + name);
  if (template && template.content && template.content.firstElementChild) {
    var svg = template.content.firstElementChild.cloneNode(true);
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    return svg;
  }
  return document.createTextNode('');
}
function uuid() {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') {
    return window.crypto.randomUUID();
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
    var r = Math.random() * 16 | 0;
    return (c === 'x' ? r : r & 0x3 | 0x8).toString(16);
  });
}
function debounce(fn, wait) {
  var timer = null;
  var wrapped = function wrapped() {
    for (var _len = arguments.length, args = new Array(_len), _key = 0; _key < _len; _key++) {
      args[_key] = arguments[_key];
    }
    clearTimeout(timer);
    timer = setTimeout(function () {
      return fn.apply(void 0, args);
    }, wait);
  };
  wrapped.cancel = function () {
    return clearTimeout(timer);
  };
  return wrapped;
}

/***/ }

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		if (!(moduleId in __webpack_modules__)) {
/******/ 			delete __webpack_module_cache__[moduleId];
/******/ 			var e = new Error("Cannot find module '" + moduleId + "'");
/******/ 			e.code = 'MODULE_NOT_FOUND';
/******/ 			throw e;
/******/ 		}
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!*******************************************************!*\
  !*** ./resources/js/documents/editor/new-document.js ***!
  \*******************************************************/
__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   init: () => (/* binding */ init)
/* harmony export */ });
/* harmony import */ var _dom__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./dom */ "./resources/js/documents/editor/dom.js");
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
// Contract 17B §7 — the "New proposal" flow on the Documents page.
//
//   step 1  choose the Contact (searchable; the Location is derived from the
//           contact on the server)
//   step 2  title + start blank (the "My templates / Recommended" area is a
//           clearly marked slot that the templates stage fills)
//
// The markup is server-rendered (documents/index.blade.php); this only drives
// it. Submitting posts the normal documents.store form with via=editor, which
// creates the draft and redirects into the editor.


function init(root) {
  if (!root) {
    return;
  }
  var modal = root.querySelector('[data-role="new-proposal-modal"]');
  var form = root.querySelector('[data-role="new-proposal-form"]');
  if (!modal || !form) {
    return;
  }
  var contactField = form.querySelector('[data-role="np-contact"]');
  var search = form.querySelector('[data-role="np-search"]');
  var results = form.querySelector('[data-role="np-results"]');
  var stepLabel = form.querySelector('[data-role="np-step"]');
  var sections = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
  var back = form.querySelector('[data-role="np-back"]');
  var next = form.querySelector('[data-role="np-next"]');
  var submit = form.querySelector('[data-role="np-submit"]');
  var chosen = form.querySelector('[data-role="np-chosen"]');
  var title = form.querySelector('[data-role="np-title"]');
  var opener = document.querySelectorAll('[data-role="new-proposal-open"]');
  var searchUrl = root.getAttribute('data-search-url');
  var step = 1;
  var token = 0;
  var previous = null;
  function setStep(value) {
    step = value;
    sections.forEach(function (section) {
      section.hidden = Number(section.getAttribute('data-step')) !== step;
    });
    stepLabel.textContent = 'Step ' + step + ' of 2';
    back.hidden = step === 1;
    next.hidden = step !== 1;
    submit.hidden = step !== 2;
    next.disabled = !contactField.value;
    if (step === 1) {
      search.focus();
    } else {
      title.focus();
      title.select();
    }
  }
  function open() {
    previous = document.activeElement;
    modal.hidden = false;
    contactField.value = '';
    chosen.textContent = '';
    setStep(1);
    load('');
  }
  function close() {
    modal.hidden = true;
    if (previous && previous.focus) {
      previous.focus();
    }
  }
  function load(_x) {
    return _load.apply(this, arguments);
  }
  function _load() {
    _load = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee(query) {
      var mine, json, response, empty, _t;
      return _regenerator().w(function (_context) {
        while (1) switch (_context.p = _context.n) {
          case 0:
            mine = ++token;
            results.textContent = 'Loading...';
            json = {
              results: []
            };
            _context.p = 1;
            _context.n = 2;
            return fetch(searchUrl + '?q=' + encodeURIComponent(query), {
              headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
              },
              credentials: 'same-origin'
            });
          case 2:
            response = _context.v;
            _context.n = 3;
            return response.json();
          case 3:
            json = _context.v;
            _context.n = 5;
            break;
          case 4:
            _context.p = 4;
            _t = _context.v;
            results.textContent = 'Contacts could not be loaded.';
            return _context.a(2);
          case 5:
            if (!(mine !== token)) {
              _context.n = 6;
              break;
            }
            return _context.a(2);
          case 6:
            results.textContent = '';
            if (!(!json.results || json.results.length === 0)) {
              _context.n = 7;
              break;
            }
            empty = document.createElement('div');
            empty.className = 'de-muted';
            empty.setAttribute('data-role', 'np-empty');
            empty.textContent = query ? 'No contact matches that search.' : 'You have no contacts yet. Add a contact first.';
            results.appendChild(empty);
            return _context.a(2);
          case 7:
            json.results.forEach(function (contact) {
              var button = document.createElement('button');
              button.type = 'button';
              button.className = 'de-pick' + (contactField.value === contact.uid ? ' is-active' : '');
              button.setAttribute('data-contact-uid', contact.uid);
              var main = document.createElement('span');
              main.className = 'de-pick__main';
              var name = document.createElement('strong');
              name.textContent = contact.text;
              main.appendChild(name);
              button.appendChild(main);
              button.addEventListener('click', function () {
                contactField.value = contact.uid;
                chosen.textContent = contact.text;
                Array.prototype.forEach.call(results.querySelectorAll('.de-pick'), function (node) {
                  return node.classList.toggle('is-active', node === button);
                });
                next.disabled = false;
              });
              button.addEventListener('dblclick', function () {
                return setStep(2);
              });
              results.appendChild(button);
            });
          case 8:
            return _context.a(2);
        }
      }, _callee, null, [[1, 4]]);
    }));
    return _load.apply(this, arguments);
  }
  search.addEventListener('input', (0,_dom__WEBPACK_IMPORTED_MODULE_0__.debounce)(function () {
    return load(search.value.trim());
  }, 250));
  search.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      if (contactField.value) {
        setStep(2);
      }
    }
  });
  next.addEventListener('click', function () {
    if (contactField.value) setStep(2);
  });
  back.addEventListener('click', function () {
    return setStep(1);
  });
  form.querySelectorAll('[data-role="np-cancel"]').forEach(function (node) {
    return node.addEventListener('click', close);
  });
  modal.addEventListener('mousedown', function (event) {
    if (event.target === modal) close();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !modal.hidden) close();
  });
  opener.forEach(function (node) {
    return node.addEventListener('click', open);
  });
  form.addEventListener('submit', function (event) {
    if (!contactField.value) {
      event.preventDefault();
      setStep(1);
      return;
    }
    if (title.value.trim() === '') {
      title.value = 'Untitled proposal';
    }
    submit.disabled = true;
  });
}
window.DocumentNewProposal = {
  init: init
};

})();

/******/ })()
;