/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./resources/js/automations/workflow-builder/api.js"
/*!**********************************************************!*\
  !*** ./resources/js/automations/workflow-builder/api.js ***!
  \**********************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createApiClient: () => (/* binding */ createApiClient)
/* harmony export */ });
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
// Automations V2 (contract §20.2, V2-D) — the fixed endpoint contract, built
// against as a client. V2-0 fixes these exact paths; V2-E supplies the real
// controllers. Every URL is composed from the `basePath` the server handed
// this page (never a named route() — this module has no Laravel route
// table to consult) so it starts working the moment V2-E's routes exist,
// with no change here.

function csrfToken() {
  var meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') : '';
}
function request(_x, _x2) {
  return _request.apply(this, arguments);
}
function _request() {
  _request = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee(url, options) {
    var response, body, _t;
    return _regenerator().w(function (_context) {
      while (1) switch (_context.p = _context.n) {
        case 0:
          _context.n = 1;
          return fetch(url, _objectSpread({
            credentials: 'same-origin',
            headers: Object.assign({
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrfToken(),
              'X-Requested-With': 'XMLHttpRequest'
            }, options && options.headers || {})
          }, options));
        case 1:
          response = _context.v;
          body = null;
          _context.p = 2;
          _context.n = 3;
          return response.json();
        case 3:
          body = _context.v;
          _context.n = 5;
          break;
        case 4:
          _context.p = 4;
          _t = _context.v;
          body = null;
        case 5:
          return _context.a(2, {
            status: response.status,
            body: body
          });
      }
    }, _callee, null, [[2, 4]]);
  }));
  return _request.apply(this, arguments);
}
function createApiClient(basePath) {
  return {
    create: function create(payload) {
      return request(basePath, {
        method: 'POST',
        body: JSON.stringify(payload)
      });
    },
    saveDraft: function saveDraft(workflowBasePath, definition, revision) {
      return request("".concat(workflowBasePath, "/draft"), {
        method: 'PUT',
        body: JSON.stringify({
          definition: definition,
          definition_revision: revision
        })
      });
    },
    publish: function publish(workflowBasePath) {
      return request("".concat(workflowBasePath, "/publish"), {
        method: 'POST'
      });
    },
    discardDraft: function discardDraft(workflowBasePath) {
      return request("".concat(workflowBasePath, "/discard-draft"), {
        method: 'POST'
      });
    },
    simulate: function simulate(workflowBasePath, contactUid) {
      return request("".concat(workflowBasePath, "/simulate"), {
        method: 'POST',
        body: JSON.stringify({
          contact_uid: contactUid
        })
      });
    }
  };
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/autosave.js"
/*!***************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/autosave.js ***!
  \***************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createAutosave: () => (/* binding */ createAutosave)
/* harmony export */ });
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
// Automations V2 (contract §14.4, §20.2, V2-D) — autosave.
//
// Debounced 1.5s PUT of the whole document with `definition_revision`
// (§14.4). The server's `WorkflowDraftService::autosave()` is the real
// concurrency authority: a conditional `UPDATE ... WHERE definition_revision
// = ?` that 409s on a stale revision. Two things layer on top of that here,
// both required by the task's own test list:
//
//   1. STALE-RESPONSE GUARD (test #15). If a save is somehow still in
//      flight when the debounce fires again (slow network outrunning 1.5s),
//      the OLDER request's response must never overwrite state a NEWER
//      request's response already applied. A monotonically increasing
//      `token` does this: a response is only applied if it is still the
//      most recently ISSUED request when it arrives, regardless of arrival
//      order.
//   2. RETRY-ABLE FAILURE (test #16). A network failure or non-2xx/409
//      response leaves the local edit exactly as the customer left it —
//      nothing is discarded — and flips to an "error" state the next
//      successful save (or an explicit retry) clears.
//
// Never publishes anything: this module's only write is the draft PUT.
function createAutosave(_ref) {
  var api = _ref.api,
    workflowBasePath = _ref.workflowBasePath,
    initialRevision = _ref.initialRevision,
    onStateChange = _ref.onStateChange,
    onSaved = _ref.onSaved,
    onConflict = _ref.onConflict;
  var revision = initialRevision;
  var timer = null;
  var token = 0;
  var pendingDocument = null;
  function schedule(doc) {
    pendingDocument = doc;
    if (timer) {
      window.clearTimeout(timer);
    }
    timer = window.setTimeout(flush, 1500);
  }
  function flushNow(doc) {
    if (timer) {
      window.clearTimeout(timer);
      timer = null;
    }
    pendingDocument = doc;
    return flush();
  }
  function flush() {
    return _flush.apply(this, arguments);
  }
  function _flush() {
    _flush = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
      var doc, mySeq, result, _t;
      return _regenerator().w(function (_context) {
        while (1) switch (_context.p = _context.n) {
          case 0:
            if (!(pendingDocument === null)) {
              _context.n = 1;
              break;
            }
            return _context.a(2);
          case 1:
            doc = pendingDocument;
            pendingDocument = null;
            mySeq = ++token;
            onStateChange('saving');
            _context.p = 2;
            _context.n = 3;
            return api.saveDraft(workflowBasePath, doc, revision);
          case 3:
            result = _context.v;
            _context.n = 6;
            break;
          case 4:
            _context.p = 4;
            _t = _context.v;
            if (!(mySeq !== token)) {
              _context.n = 5;
              break;
            }
            return _context.a(2);
          case 5:
            onStateChange('error');
            // The edit stays visible locally (it was never discarded) and
            // the next debounce — or an explicit retry — tries again.
            schedule(doc);
            return _context.a(2);
          case 6:
            if (!(mySeq !== token)) {
              _context.n = 7;
              break;
            }
            return _context.a(2);
          case 7:
            if (!(result.status === 409)) {
              _context.n = 8;
              break;
            }
            onStateChange('error');
            onConflict(result.body);
            return _context.a(2);
          case 8:
            if (!(result.status < 200 || result.status >= 300)) {
              _context.n = 9;
              break;
            }
            onStateChange('error');
            schedule(doc);
            return _context.a(2);
          case 9:
            revision = result.body && result.body.revision !== undefined ? result.body.revision : revision + 1;
            onStateChange('saved');
            onSaved(result.body || {});
          case 10:
            return _context.a(2);
        }
      }, _callee, null, [[2, 4]]);
    }));
    return _flush.apply(this, arguments);
  }
  return {
    schedule: schedule,
    flushNow: flushNow,
    getRevision: function getRevision() {
      return revision;
    }
  };
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/canvas-renderer.js"
/*!**********************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/canvas-renderer.js ***!
  \**********************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   renderCanvas: () => (/* binding */ renderCanvas)
/* harmony export */ });
/* harmony import */ var _constants_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./constants.js */ "./resources/js/automations/workflow-builder/constants.js");
// Automations V2 (contract §5.1, §13.1, V2-D) — the canvas.
//
// Because the graph is a tree, it lays itself out: a vertical list of step
// cards, with If/Else rendering its two lanes as side-by-side columns
// (contract §13.1). This module builds real DOM nodes rather than HTML
// strings specifically so every "+"/move/delete control can close over the
// actual array (and node) it acts on — the same array `document-model.js`'s
// helpers splice — with no second, string-based addressing scheme to keep
// in sync with the document.

function el(tag, className, text) {
  var node = document.createElement(tag);
  if (className) {
    node.className = className;
  }
  if (text !== undefined) {
    node.textContent = text;
  }
  return node;
}

/**
 * @param {Object} doc the current document (mutated in place by callbacks)
 * @param {HTMLElement} rootEl the `<ol data-role="wf-canvas-root">` element
 * @param {Object} handlers
 *   onSelect(node)                    — open the drawer for this node
 *   onAdd(list, index, depth, type)    — insert a step of this type here
 *   onDelete(node, list)
 *   onMove(node, list, direction)     — direction is -1 or 1
 * @param {Object} state { errors, nodeCount, limits, selectedKey }
 */
function renderCanvas(doc, rootEl, handlers, state) {
  rootEl.innerHTML = '';
  rootEl.appendChild(renderTriggerCard(doc.root, handlers, state));
  rootEl.appendChild(connector());
  rootEl.appendChild(renderSequence(doc.root.next || [], handlers, state, 0));
}
function connector() {
  return el('div', 'wf-step-connector');
}
function renderTriggerCard(node, handlers, state) {
  var li = el('li', 'wf-step');
  var card = el('div', 'wf-step-card wf-step-card-trigger');
  card.dataset.nodeKey = node.key;
  card.dataset.nodeType = node.type;
  card.tabIndex = 0;
  card.setAttribute('role', 'button');
  var label = el('span', null, _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_LABELS.trigger);
  card.appendChild(label);
  if (state.errors && state.errors[node.key] && state.errors[node.key].length) {
    card.classList.add('has-error');
  }
  card.addEventListener('click', function () {
    return handlers.onSelect(node);
  });
  card.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
      handlers.onSelect(node);
    }
  });
  li.appendChild(card);
  appendErrorText(li, node.key, state);
  return li;
}

/**
 * Render one sequence (an array of sibling steps) as a `<ol class="wf-steps">`,
 * with a "+" before the first step, between every pair, and after the last
 * ONLY if the sequence does not already end in a branching or terminal step
 * — exactly the rule that keeps every insertion point structurally valid by
 * construction (contract: "Each plus control opens only node types that are
 * valid at that insertion point").
 */
function renderSequence(list, handlers, state, depth) {
  var ol = el('ol', 'wf-steps');
  ol.appendChild(renderAddControl(list, 0, depth, handlers, state));
  list.forEach(function (node, index) {
    ol.appendChild(renderStep(node, list, index, handlers, state, depth));
    var isLast = index === list.length - 1;
    var closesSequence = (0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.isBranching)(node.type) || (0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.isTerminal)(node.type);
    if (!isLast || !closesSequence) {
      ol.appendChild(connector());
      ol.appendChild(renderAddControl(list, index + 1, depth, handlers, state));
    }
  });
  if (list.length === 0) {
    // The empty-list case is handled by the single "+" already appended
    // above; nothing else to draw.
  }
  return ol;
}
function renderStep(node, list, index, handlers, state, depth) {
  var li = el('li', 'wf-step');
  if ((0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.isBranching)(node.type)) {
    li.appendChild(renderCard(node, list, index, handlers, state, depth));
    li.appendChild(connector());
    li.appendChild(renderBranches(node, handlers, state, depth));
    return li;
  }
  li.appendChild(renderCard(node, list, index, handlers, state, depth));

  // Every insert/delete/move in this module keeps a straight chain as one
  // flat sibling array (matching contract §5.3's own example), so this
  // branch is normally empty. It stays here defensively: the validator
  // itself walks a non-branching node's OWN `next` too (WorkflowDefinitionValidator::walkBranches),
  // so a document nesting a continuation that way — loaded from
  // elsewhere, or a future producer — still renders completely rather
  // than silently dropping steps.
  if (!(0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.isTerminal)(node.type) && Array.isArray(node.next) && node.next.length > 0) {
    li.appendChild(connector());
    li.appendChild(renderSequence(node.next, handlers, state, depth));
  }
  return li;
}
function renderCard(node, list, index, handlers, state, depth) {
  var wrapper = el('div', 'wf-step');
  var card = el('div', 'wf-step-card');
  card.dataset.nodeKey = node.key;
  card.dataset.nodeType = node.type;
  card.tabIndex = 0;
  card.setAttribute('role', 'button');
  if (state.selectedKey === node.key) {
    card.classList.add('is-focused');
  }
  var hasErrors = state.errors && state.errors[node.key] && state.errors[node.key].length > 0;
  if (hasErrors) {
    card.classList.add('has-error');
  }
  var label = el('span', null, _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_LABELS[node.type] || node.type);
  card.appendChild(label);
  var menuWrap = el('div', 'dropdown');
  var menuButton = el('button', 'btn btn-flat-secondary btn-sm');
  menuButton.type = 'button';
  menuButton.setAttribute('data-bs-toggle', 'dropdown');
  menuButton.setAttribute('aria-label', 'Step options');
  menuButton.textContent = '⋮';
  menuWrap.appendChild(menuButton);
  var menu = el('ul', 'dropdown-menu dropdown-menu-end');
  menu.appendChild(menuItem('Move up', function () {
    return handlers.onMove(node, list, -1);
  }));
  menu.appendChild(menuItem('Move down', function () {
    return handlers.onMove(node, list, 1);
  }));
  menu.appendChild(menuItem('Delete step', function () {
    return handlers.onDelete(node, list);
  }));
  menuWrap.appendChild(menu);
  card.appendChild(menuWrap);
  card.addEventListener('click', function (event) {
    if (event.target.closest('.dropdown')) {
      return;
    }
    handlers.onSelect(node);
  });
  card.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
      handlers.onSelect(node);
    } else if (event.key === 'Delete') {
      handlers.onDelete(node, list);
    }
  });
  wrapper.appendChild(card);
  appendErrorText(wrapper, node.key, state);
  return wrapper;
}
function menuItem(label, onClick) {
  var li = el('li');
  var button = el('button', 'dropdown-item', label);
  button.type = 'button';
  button.addEventListener('click', onClick);
  li.appendChild(button);
  return li;
}
function appendErrorText(container, key, state) {
  var messages = state.errors && state.errors[key] ? state.errors[key] : [];
  messages.forEach(function (message) {
    container.appendChild(el('p', 'wf-step-error-text', message));
  });
}
function renderBranches(node, handlers, state, depth) {
  var wrap = el('div', 'wf-branches');
  var yes = el('div', 'wf-branch wf-branch-yes');
  yes.appendChild(el('span', 'wf-branch-label', 'Yes'));
  yes.appendChild(renderSequence(node.yes || [], handlers, state, depth + 1));
  appendPathEnd(yes, node.yes || []);
  var no = el('div', 'wf-branch wf-branch-no');
  no.appendChild(el('span', 'wf-branch-label', 'No'));
  no.appendChild(renderSequence(node.no || [], handlers, state, depth + 1));
  appendPathEnd(no, node.no || []);
  wrap.appendChild(yes);
  wrap.appendChild(no);
  return wrap;
}
function appendPathEnd(container, list) {
  var last = list[list.length - 1];
  if (list.length === 0 || !(0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.isBranching)(last.type) && !(0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.isTerminal)(last.type)) {
    container.appendChild(el('p', 'wf-path-end', 'Path ends here'));
  }
}
function renderAddControl(list, index, depth, handlers, state) {
  var wrap = el('div', 'dropdown wf-add-step-wrap');
  var button = el('button', 'wf-add-step');
  button.type = 'button';
  button.setAttribute('data-bs-toggle', 'dropdown');
  button.setAttribute('aria-label', 'Add a step');
  button.textContent = '+';
  var atCapacity = typeof state.nodeCount === 'number' && state.limits && state.nodeCount >= state.limits.maxNodes;
  button.disabled = atCapacity;
  var menu = el('ul', 'dropdown-menu');
  var isTrailing = index === list.length;
  _constants_js__WEBPACK_IMPORTED_MODULE_0__.INSERTABLE_TYPES.forEach(function (type) {
    if (type === 'if_else' && state.limits && depth >= state.limits.maxBranchDepth) {
      return;
    }

    // End only makes sense as the LAST step in a sequence: something
    // already follows it at any other position, which the schema
    // forbids outright.
    if (type === 'end' && !isTrailing) {
      return;
    }
    var li = el('li');
    var item = el('button', 'dropdown-item', _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_LABELS[type]);
    item.type = 'button';
    item.addEventListener('click', function () {
      return handlers.onAdd(list, index, depth, type);
    });
    item.dataset.nodeType = type;
    li.appendChild(item);
    menu.appendChild(li);
  });
  wrap.appendChild(button);
  wrap.appendChild(menu);
  return wrap;
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/conditions.js"
/*!*****************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/conditions.js ***!
  \*****************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   BOOLEAN_SUBJECTS: () => (/* binding */ BOOLEAN_SUBJECTS),
/* harmony export */   GROUP_SUBJECTS: () => (/* binding */ GROUP_SUBJECTS),
/* harmony export */   TEXT_SUBJECTS: () => (/* binding */ TEXT_SUBJECTS),
/* harmony export */   subjectOperators: () => (/* binding */ subjectOperators)
/* harmony export */ });
function _toConsumableArray(r) { return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray(r) || _nonIterableSpread(); }
function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _iterableToArray(r) { if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r); }
function _arrayWithoutHoles(r) { if (Array.isArray(r)) return _arrayLikeToArray(r); }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
// Automations V2 (contract §11, V2-D) — the closed condition-subject
// vocabulary this builder may offer. `contact.replied_since_enrollment` is
// deliberately absent: it needs V2-F's inbound producer, which has not
// shipped, so offering it here would let a customer build a workflow that
// can never evaluate that condition.
var TEXT_SUBJECTS = ['contact.first_name', 'contact.last_name', 'contact.email', 'contact.company'];
var BOOLEAN_SUBJECTS = ['contact.subscribed'];
var GROUP_SUBJECTS = ['contact.in_group'];
var TEXT_OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty'];
var BOOLEAN_OPERATORS = ['is_true', 'is_false'];
var REFERENCE_OPERATORS = ['equals', 'not_equals'];
var DATE_OPERATORS = ['before', 'after', 'on_date', 'is_empty', 'is_not_empty'];
function subjectOperators(subject) {
  if (BOOLEAN_SUBJECTS.includes(subject)) {
    return BOOLEAN_OPERATORS;
  }
  if (GROUP_SUBJECTS.includes(subject)) {
    return REFERENCE_OPERATORS;
  }
  if (typeof subject === 'string' && subject.startsWith('contact.custom_field:')) {
    // A custom field's operators depend on its own type (date vs text);
    // the drawer does not currently carry field type metadata into this
    // pure lookup, so it offers the safe superset the server itself
    // validates against per field type at save time.
    return [].concat(TEXT_OPERATORS, _toConsumableArray(DATE_OPERATORS.filter(function (op) {
      return !TEXT_OPERATORS.includes(op);
    })));
  }
  return TEXT_OPERATORS;
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/constants.js"
/*!****************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/constants.js ***!
  \****************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   INSERTABLE_TYPES: () => (/* binding */ INSERTABLE_TYPES),
/* harmony export */   NODE_LABELS: () => (/* binding */ NODE_LABELS),
/* harmony export */   NODE_TYPES: () => (/* binding */ NODE_TYPES),
/* harmony export */   defaultConfigFor: () => (/* binding */ defaultConfigFor),
/* harmony export */   isBranching: () => (/* binding */ isBranching),
/* harmony export */   isTerminal: () => (/* binding */ isTerminal)
/* harmony export */ });
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
// Automations V2 (contract §5.2, V2-D) — the closed vocabulary this builder
// may ever render. Mirrors App\Enums\Automation\Workflow\WorkflowNodeType
// exactly; nothing here may be added without a matching registry entry on
// the server, because an unregistered type is refused at validate/compile.

var NODE_TYPES = {
  TRIGGER: 'trigger',
  SEND_SMS: 'send_sms',
  UPDATE_CONTACT_FIELD: 'update_contact_field',
  INTERNAL_NOTIFICATION: 'internal_notification',
  WAIT: 'wait',
  IF_ELSE: 'if_else',
  END: 'end'
};
var NODE_LABELS = _defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty({}, NODE_TYPES.TRIGGER, 'Trigger'), NODE_TYPES.SEND_SMS, 'Send a text message'), NODE_TYPES.UPDATE_CONTACT_FIELD, 'Update a contact field'), NODE_TYPES.INTERNAL_NOTIFICATION, 'Notify the team'), NODE_TYPES.WAIT, 'Wait'), NODE_TYPES.IF_ELSE, 'If / Else'), NODE_TYPES.END, 'End');

// Types a customer may insert anywhere in the body (never the trigger,
// which is always the one root the document validator requires).
var INSERTABLE_TYPES = [NODE_TYPES.SEND_SMS, NODE_TYPES.UPDATE_CONTACT_FIELD, NODE_TYPES.INTERNAL_NOTIFICATION, NODE_TYPES.WAIT, NODE_TYPES.IF_ELSE, NODE_TYPES.END];
function isBranching(type) {
  return type === NODE_TYPES.IF_ELSE;
}
function isTerminal(type) {
  return type === NODE_TYPES.END;
}

// Default config for a freshly inserted node of each type — deliberately
// the smallest shape NodeTypeRegistry's validator can score, so a new step
// always starts with one clear, singular validation message rather than a
// wall of them.
function defaultConfigFor(type) {
  switch (type) {
    case NODE_TYPES.SEND_SMS:
      return {
        body: ''
      };
    case NODE_TYPES.UPDATE_CONTACT_FIELD:
      return {
        field_id: null,
        value: ''
      };
    case NODE_TYPES.INTERNAL_NOTIFICATION:
      return {
        message: ''
      };
    case NODE_TYPES.WAIT:
      return {
        mode: 'duration',
        amount: 1,
        unit: 'days'
      };
    case NODE_TYPES.IF_ELSE:
      return {
        match: 'all',
        conditions: []
      };
    case NODE_TYPES.END:
      return {};
    default:
      return {};
  }
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/document-model.js"
/*!*********************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/document-model.js ***!
  \*********************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   cloneDocument: () => (/* binding */ cloneDocument),
/* harmony export */   countNodes: () => (/* binding */ countNodes),
/* harmony export */   insertAt: () => (/* binding */ insertAt),
/* harmony export */   moveWithin: () => (/* binding */ moveWithin),
/* harmony export */   newNode: () => (/* binding */ newNode),
/* harmony export */   removeFrom: () => (/* binding */ removeFrom),
/* harmony export */   sequenceDepthOf: () => (/* binding */ sequenceDepthOf)
/* harmony export */ });
function _slicedToArray(r, e) { return _arrayWithHoles(r) || _iterableToArrayLimit(r, e) || _unsupportedIterableToArray(r, e) || _nonIterableRest(); }
function _nonIterableRest() { throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _iterableToArrayLimit(r, l) { var t = null == r ? null : "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (null != t) { var e, n, i, u, a = [], f = !0, o = !1; try { if (i = (t = t.call(r)).next, 0 === l) { if (Object(t) !== t) return; f = !1; } else for (; !(f = (e = i.call(t)).done) && (a.push(e.value), a.length !== l); f = !0); } catch (r) { o = !0, n = r; } finally { try { if (!f && null != t["return"] && (u = t["return"](), Object(u) !== u)) return; } finally { if (o) throw n; } } return a; } }
function _arrayWithHoles(r) { if (Array.isArray(r)) return r; }
function _createForOfIteratorHelper(r, e) { var t = "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (!t) { if (Array.isArray(r) || (t = _unsupportedIterableToArray(r)) || e && r && "number" == typeof r.length) { t && (r = t); var _n = 0, F = function F() {}; return { s: F, n: function n() { return _n >= r.length ? { done: !0 } : { done: !1, value: r[_n++] }; }, e: function e(r) { throw r; }, f: F }; } throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); } var o, a = !0, u = !1; return { s: function s() { t = t.call(r); }, n: function n() { var r = t.next(); return a = r.done, r; }, e: function e(r) { u = !0, o = r; }, f: function f() { try { a || null == t["return"] || t["return"](); } finally { if (u) throw o; } } }; }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
// Automations V2 (contract §5.3, V2-D) — pure functions over the editor's
// document. THE DOCUMENT IS A NESTED LIST, and that is the whole tree
// guarantee: a node's continuation is its own `next` array, or for
// `if_else`, its `yes`/`no` arrays. There is nowhere in this shape to write
// "go back to" or "both branches continue here" — the same structural
// guarantee the server's WorkflowDefinitionValidator relies on (it is not
// duplicated here; this module only prevents the browser from ever being
// ASKED to build a shape the schema cannot express).
//
// Nothing here talks to the network, the DOM or the undo stack — every
// function takes a document (or a list within one) and returns a new node,
// a new key, or mutates the list it was handed. Callers (canvas-renderer.js,
// drawer.js) own snapshotting for undo and scheduling the autosave.

function uuid() {
  if (window.crypto && window.crypto.randomUUID) {
    return window.crypto.randomUUID();
  }

  // Fallback for a non-secure-context test/browser environment.
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
    var r = Math.random() * 16 | 0;
    var v = c === 'x' ? r : r & 0x3 | 0x8;
    return v.toString(16);
  });
}
function newNode(type, config) {
  var node = {
    key: uuid(),
    type: type,
    config: config || {}
  };
  if (type === 'if_else') {
    node.yes = [];
    node.no = [];
  } else if (type !== 'end') {
    node.next = [];
  }
  return node;
}
function cloneDocument(doc) {
  return JSON.parse(JSON.stringify(doc));
}

/**
 * Depth of a node's OWN body relative to the root (root's `next` = depth 0).
 * Every step inside an `if_else`'s `yes`/`no` lane is one deeper than the
 * `if_else` itself — matches the server's `depth` column exactly, so the
 * client can refuse to offer "If / Else" once nesting is already at the
 * limit rather than let the customer build something the compiler will
 * only reject after a round trip.
 */
function sequenceDepthOf(node, root) {
  var found = -1;
  function walk(list, depth) {
    if (found !== -1) {
      return;
    }
    var _iterator = _createForOfIteratorHelper(list),
      _step;
    try {
      for (_iterator.s(); !(_step = _iterator.n()).done;) {
        var step = _step.value;
        if (step === node) {
          found = depth;
          return;
        }
        if (step.type === 'if_else') {
          walk(step.yes, depth + 1);
          walk(step.no, depth + 1);
        } else if (Array.isArray(step.next)) {
          walk(step.next, depth);
        }
        if (found !== -1) {
          return;
        }
      }
    } catch (err) {
      _iterator.e(err);
    } finally {
      _iterator.f();
    }
  }
  walk(root.next, 0);
  return found;
}

/**
 * The list a "+" between `before` and `after` (either may be null at an
 * end) belongs to, i.e. exactly what array.splice() target and index would
 * insert a node in the right spot. Callers already hold this array by
 * reference while rendering (canvas-renderer.js), so this helper exists
 * only for tests and for code paths that address a list by the node
 * bordering it rather than by the array itself.
 */
function insertAt(list, index, node) {
  list.splice(index, 0, node);
  return node;
}
function removeFrom(list, node) {
  var index = list.indexOf(node);
  if (index !== -1) {
    list.splice(index, 1);
  }
  return index;
}
function moveWithin(list, node, direction) {
  var index = list.indexOf(node);
  if (index === -1) {
    return false;
  }
  var target = index + direction;
  if (target < 0 || target >= list.length) {
    return false;
  }
  var _list$splice = list.splice(index, 1),
    _list$splice2 = _slicedToArray(_list$splice, 1),
    item = _list$splice2[0];
  list.splice(target, 0, item);
  return true;
}

/** Count every node in the document — mirrors the server's own walk. */
function countNodes(doc) {
  var count = 1; // the root

  function walkList(list) {
    var _iterator2 = _createForOfIteratorHelper(list),
      _step2;
    try {
      for (_iterator2.s(); !(_step2 = _iterator2.n()).done;) {
        var node = _step2.value;
        count++;
        if (node.type === 'if_else') {
          walkList(node.yes || []);
          walkList(node.no || []);
        } else {
          walkList(node.next || []);
        }
      }
    } catch (err) {
      _iterator2.e(err);
    } finally {
      _iterator2.f();
    }
  }
  walkList(doc.root.next || []);
  return count;
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/drawer.js"
/*!*************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/drawer.js ***!
  \*************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createDrawer: () => (/* binding */ createDrawer)
/* harmony export */ });
/* harmony import */ var _conditions_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./conditions.js */ "./resources/js/automations/workflow-builder/conditions.js");
// Automations V2 (contract §13.1, §14.2, V2-D) — the configuration drawer.
//
// One Bootstrap 5 offcanvas, its body populated per node type from the
// hidden `<template>` partials the Blade view already rendered
// server-side (contract §13.1: "one server-rendered form partial per node
// type"). This module never invents a field NodeTypeRegistry does not
// validate, and every option list it offers (contact groups, writable
// fields, date fields) comes only from the `catalogs` this Business's
// caller supplied — never a value typed into the DOM by a script, and
// never another Business's row.

function fillSelect(select, options, valueKey, labelKey, placeholder) {
  select.innerHTML = '';
  if (placeholder) {
    var opt = document.createElement('option');
    opt.value = '';
    opt.textContent = placeholder;
    select.appendChild(opt);
  }
  options.forEach(function (row) {
    var opt = document.createElement('option');
    opt.value = String(row[valueKey]);
    opt.textContent = row[labelKey];
    select.appendChild(opt);
  });
}
function createDrawer(_ref) {
  var drawerEl = _ref.drawerEl,
    catalogs = _ref.catalogs,
    dateOffsets = _ref.dateOffsets,
    contactSources = _ref.contactSources,
    onSave = _ref.onSave,
    onDelete = _ref.onDelete;
  var OffcanvasCtor = window.bootstrap && window.bootstrap.Offcanvas;
  var instance = OffcanvasCtor ? OffcanvasCtor.getOrCreateInstance(drawerEl) : null;
  var titleEl = drawerEl.querySelector('[data-role="wf-drawer-title"]');
  var formEl = drawerEl.querySelector('[data-role="wf-drawer-form"]');
  var errorsEl = drawerEl.querySelector('[data-role="wf-drawer-errors"]');
  var saveButton = drawerEl.querySelector('[data-role="wf-drawer-save"]');
  var deleteButton = drawerEl.querySelector('[data-role="wf-drawer-delete"]');
  var currentNode = null;
  function open(node, errorMessages) {
    currentNode = node;
    titleEl.textContent = titleFor(node.type);
    formEl.innerHTML = '';
    errorsEl.classList.add('d-none');
    errorsEl.innerHTML = '';
    var template = document.getElementById("wf-node-form-".concat(node.type));
    if (template) {
      formEl.appendChild(template.content.cloneNode(true));
    }
    populate(node);
    showErrors(errorMessages || []);
    deleteButton.classList.toggle('d-none', node.type === 'trigger');
    if (instance) {
      instance.show();
    }
  }
  function close() {
    if (instance) {
      instance.hide();
    }
    currentNode = null;
  }
  function showErrors(messages) {
    if (!messages || messages.length === 0) {
      errorsEl.classList.add('d-none');
      return;
    }
    errorsEl.classList.remove('d-none');
    errorsEl.innerHTML = '';
    messages.forEach(function (message) {
      var p = document.createElement('p');
      p.className = 'mb-0';
      p.textContent = message;
      errorsEl.appendChild(p);
    });
  }
  function populate(node) {
    if (node.type === 'trigger') {
      populateTrigger(node);
    } else if (node.type === 'if_else') {
      populateIfElse(node);
    } else if (node.type === 'update_contact_field') {
      populateUpdateContactField(node);
    } else {
      populateGeneric(node);
    }
  }
  function populateGeneric(node) {
    formEl.querySelectorAll('[data-field]').forEach(function (field) {
      var key = field.dataset.field;
      var value = node.config[key];
      if (value !== undefined && value !== null) {
        field.value = value;
      }
    });
    if (node.type === 'wait') {
      wireWaitMode();
    }
  }
  function wireWaitMode() {
    var modeSelect = formEl.querySelector('[data-field="mode"]');
    var durationFields = formEl.querySelector('[data-role="wf-wait-duration-fields"]');
    var datetimeFields = formEl.querySelector('[data-role="wf-wait-datetime-fields"]');
    function sync() {
      var isDuration = modeSelect.value === 'duration';
      durationFields.classList.toggle('d-none', !isDuration);
      datetimeFields.classList.toggle('d-none', isDuration);
    }
    modeSelect.addEventListener('change', sync);
    sync();
  }
  function populateUpdateContactField(node) {
    var select = formEl.querySelector('[data-role="wf-writable-field-select"]');
    fillSelect(select, catalogs.writableFields, 'id', 'label', null);
    formEl.querySelectorAll('[data-field]').forEach(function (field) {
      var key = field.dataset.field;
      var value = node.config[key];
      if (value !== undefined && value !== null) {
        field.value = value;
      }
    });
  }
  function populateTrigger(node) {
    var typeSelect = formEl.querySelector('[data-role="wf-trigger-type"]');
    var createdFields = formEl.querySelector('[data-role="wf-trigger-contact-created-fields"]');
    var dateFields = formEl.querySelector('[data-role="wf-trigger-date-fields"]');
    var groupSelect = formEl.querySelector('[data-role="wf-contact-group-select"]');
    var dateGroupSelect = formEl.querySelector('[data-role="wf-date-contact-group-select"]');
    var dateFieldSelect = formEl.querySelector('[data-role="wf-date-field-select"]');
    var offsetSelect = formEl.querySelector('[data-role="wf-offset-select"]');
    var sourceSelect = formEl.querySelector('select[data-field="source"]');
    var policySelect = formEl.querySelector('[data-role="wf-enrollment-policy"]');
    var policySourceInput = formEl.querySelector('[data-field="enrollment_policy_source"]');
    var confirmNote = formEl.querySelector('[data-role="wf-policy-confirm-note"]');
    fillSelect(groupSelect, catalogs.contactGroups, 'id', 'name', 'Any group');
    fillSelect(dateGroupSelect, catalogs.contactGroups, 'id', 'name', 'Choose a group');
    fillSelect(offsetSelect, dateOffsets.map(function (value) {
      return {
        value: value,
        label: value
      };
    }), 'value', 'label', null);
    sourceSelect.innerHTML = '';
    contactSources.forEach(function (source) {
      var opt = document.createElement('option');
      opt.value = source;
      opt.textContent = source;
      sourceSelect.appendChild(opt);
    });
    function refreshDateFieldOptions() {
      var groupId = dateGroupSelect.value;
      var rows = catalogs.dateFields.filter(function (f) {
        return String(f.contact_group_id) === String(groupId);
      });
      fillSelect(dateFieldSelect, rows, 'id', 'label', rows.length ? null : 'No date fields in this group');
    }
    function syncVisibility() {
      var isDate = typeSelect.value === 'contact_date_reached';
      createdFields.classList.toggle('d-none', isDate);
      dateFields.classList.toggle('d-none', !isDate);
    }
    function defaultPolicyFor(triggerType) {
      return triggerType === 'contact_date_reached' || triggerType === 'message_received' ? 'once_per_occurrence' : 'once_ever';
    }
    typeSelect.addEventListener('change', function () {
      syncVisibility();
      refreshDateFieldOptions();

      // Mirrors WorkflowDraftService::withTriggerChanged(): a policy
      // still carrying its trigger's DEFAULT origin follows the new
      // trigger silently; a customer's own explicit choice is left
      // alone, with a note rather than a silent override (§7.5).
      if (policySourceInput.value === 'default') {
        policySelect.value = defaultPolicyFor(typeSelect.value);
        confirmNote.classList.add('d-none');
      } else {
        confirmNote.classList.remove('d-none');
      }
    });
    dateGroupSelect.addEventListener('change', refreshDateFieldOptions);
    policySelect.addEventListener('change', function () {
      // Any manual change is a deliberate customer choice from this
      // moment on.
      policySourceInput.value = 'user';
      confirmNote.classList.add('d-none');
    });
    typeSelect.value = node.config.trigger_type || 'contact_created';
    sourceSelect.value = node.config.source || 'any';
    groupSelect.value = node.config.contact_group_id != null ? String(node.config.contact_group_id) : '';
    dateGroupSelect.value = node.config.contact_group_id != null ? String(node.config.contact_group_id) : '';
    refreshDateFieldOptions();
    dateFieldSelect.value = node.config.date_field_id != null ? String(node.config.date_field_id) : '';
    offsetSelect.value = node.config.offset || '0 day';
    formEl.querySelector('[data-field="send_at"]').value = node.config.send_at || '09:00';
    policySelect.value = node.config.enrollment_policy || defaultPolicyFor(typeSelect.value);
    policySourceInput.value = node.config.enrollment_policy_source || 'default';
    syncVisibility();
  }
  function populateIfElse(node) {
    var matchSelect = formEl.querySelector('[data-field="match"]');
    matchSelect.value = node.config.match || 'all';
    var list = formEl.querySelector('[data-role="wf-conditions-list"]');
    var addButton = formEl.querySelector('[data-role="wf-add-condition"]');
    var rowTemplate = document.getElementById('wf-if-else-condition-row');
    function addRow(condition) {
      var fragment = rowTemplate.content.cloneNode(true);
      var row = fragment.querySelector('[data-role="wf-condition-row"]');
      var subjectSelect = row.querySelector('[data-role="wf-condition-subject"]');
      var customGroup = row.querySelector('[data-role="wf-condition-custom-field-group"]');
      var operatorSelect = row.querySelector('[data-role="wf-condition-operator"]');
      var operandWrap = row.querySelector('[data-role="wf-condition-operand-wrapper"]');
      var operandInput = row.querySelector('[data-role="wf-condition-operand"]');
      var operandGroupWrap = row.querySelector('[data-role="wf-condition-operand-group-wrapper"]');
      var operandGroupSelect = row.querySelector('[data-role="wf-condition-operand-group"]');
      catalogs.writableFields.forEach(function (field) {
        var opt = document.createElement('option');
        opt.value = "contact.custom_field:".concat(field.id);
        opt.textContent = field.label;
        customGroup.appendChild(opt);
      });
      fillSelect(operandGroupSelect, catalogs.contactGroups, 'id', 'name', null);
      function syncOperators() {
        var subject = subjectSelect.value;
        var operators = (0,_conditions_js__WEBPACK_IMPORTED_MODULE_0__.subjectOperators)(subject);
        operatorSelect.innerHTML = '';
        operators.forEach(function (op) {
          var opt = document.createElement('option');
          opt.value = op;
          opt.textContent = op.replace(/_/g, ' ');
          operatorSelect.appendChild(opt);
        });
      }
      function syncOperandVisibility() {
        var operator = operatorSelect.value;
        var needsOperand = !['is_empty', 'is_not_empty', 'is_true', 'is_false'].includes(operator);
        var isGroupSubject = _conditions_js__WEBPACK_IMPORTED_MODULE_0__.GROUP_SUBJECTS.includes(subjectSelect.value);
        operandWrap.classList.toggle('d-none', !needsOperand || isGroupSubject);
        operandGroupWrap.classList.toggle('d-none', !needsOperand || !isGroupSubject);
      }
      subjectSelect.addEventListener('change', function () {
        syncOperators();
        syncOperandVisibility();
      });
      operatorSelect.addEventListener('change', syncOperandVisibility);
      row.querySelector('[data-role="wf-remove-condition"]').addEventListener('click', function () {
        row.remove();
      });
      subjectSelect.value = condition.subject || 'contact.first_name';
      syncOperators();
      operatorSelect.value = condition.operator || operatorSelect.options[0].value;
      syncOperandVisibility();
      if (_conditions_js__WEBPACK_IMPORTED_MODULE_0__.GROUP_SUBJECTS.includes(subjectSelect.value)) {
        operandGroupSelect.value = condition.operand != null ? String(condition.operand) : '';
      } else {
        operandInput.value = condition.operand != null ? condition.operand : '';
      }
      list.appendChild(row);
    }
    list.innerHTML = '';
    (node.config.conditions || []).forEach(addRow);
    addButton.onclick = function () {
      return addRow({});
    };
  }
  function readGeneric() {
    var config = {};
    formEl.querySelectorAll('[data-field]').forEach(function (field) {
      config[field.dataset.field] = field.value;
    });
    return config;
  }
  function readTrigger() {
    var triggerType = formEl.querySelector('[data-role="wf-trigger-type"]').value;
    var config = {
      trigger_type: triggerType,
      enrollment_policy: formEl.querySelector('[data-role="wf-enrollment-policy"]').value,
      enrollment_policy_source: formEl.querySelector('[data-field="enrollment_policy_source"]').value,
      failure_policy: 'halt'
    };
    if (triggerType === 'contact_date_reached') {
      var groupValue = formEl.querySelector('[data-role="wf-date-contact-group-select"]').value;
      config.contact_group_id = groupValue ? Number(groupValue) : null;
      var fieldValue = formEl.querySelector('[data-role="wf-date-field-select"]').value;
      config.date_field_id = fieldValue ? Number(fieldValue) : null;
      config.offset = formEl.querySelector('[data-role="wf-offset-select"]').value;
      config.send_at = formEl.querySelector('[data-field="send_at"]').value;
    } else {
      config.source = formEl.querySelector('select[data-field="source"]').value;
      var _groupValue = formEl.querySelector('[data-role="wf-contact-group-select"]').value;
      config.contact_group_id = _groupValue ? Number(_groupValue) : null;
    }
    return config;
  }
  function readIfElse() {
    var conditions = [];
    formEl.querySelectorAll('[data-role="wf-condition-row"]').forEach(function (row) {
      var subject = row.querySelector('[data-role="wf-condition-subject"]').value;
      var operator = row.querySelector('[data-role="wf-condition-operator"]').value;
      var condition = {
        subject: subject,
        operator: operator
      };
      if (!['is_empty', 'is_not_empty', 'is_true', 'is_false'].includes(operator)) {
        condition.operand = _conditions_js__WEBPACK_IMPORTED_MODULE_0__.GROUP_SUBJECTS.includes(subject) ? row.querySelector('[data-role="wf-condition-operand-group"]').value : row.querySelector('[data-role="wf-condition-operand"]').value;
      }
      conditions.push(condition);
    });
    return {
      match: formEl.querySelector('[data-field="match"]').value,
      conditions: conditions
    };
  }
  function readUpdateContactField() {
    var fieldValue = formEl.querySelector('[data-role="wf-writable-field-select"]').value;
    return {
      field_id: fieldValue ? Number(fieldValue) : null,
      value: formEl.querySelector('[data-field="value"]').value
    };
  }
  function titleFor(type) {
    return type.split('_').map(function (part) {
      return part.charAt(0).toUpperCase() + part.slice(1);
    }).join(' ');
  }
  saveButton.addEventListener('click', function () {
    if (!currentNode) {
      return;
    }
    var config;
    if (currentNode.type === 'trigger') {
      config = readTrigger();
    } else if (currentNode.type === 'if_else') {
      config = readIfElse();
    } else if (currentNode.type === 'update_contact_field') {
      config = readUpdateContactField();
    } else if (currentNode.type === 'end') {
      config = {};
    } else {
      config = readGeneric();
    }
    onSave(currentNode, config);
    close();
  });
  deleteButton.addEventListener('click', function () {
    if (!currentNode) {
      return;
    }
    var node = currentNode;
    close();
    onDelete(node);
  });
  return {
    open: open,
    close: close
  };
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/history.js"
/*!**************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/history.js ***!
  \**************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createHistory: () => (/* binding */ createHistory)
/* harmony export */ });
// Automations V2 (contract §13.1, V2-D) — client-side document-history
// undo/redo. Bounded to 50 snapshots, exactly as the contract specifies.
// Every entry is a full document snapshot rather than a diff/operation log:
// a snapshot can never be "half applied," so undo/redo can never produce an
// invalid document — restoring one is exactly the same code path as
// loading the draft in the first place.
//
// This is a plain module, not server-side event sourcing: nothing here is
// persisted: it lives only in this tab's memory and is gone on reload.

var MAX_ENTRIES = 50;
function createHistory(initialDocument) {
  var stack = [JSON.parse(JSON.stringify(initialDocument))];
  var cursor = 0;
  function push(doc) {
    // A new edit after an undo discards the redo branch — the
    // conventional undo/redo contract, and the only one that keeps
    // "redo" meaning "the edit I just undid" rather than something
    // else entirely.
    stack.splice(cursor + 1);
    stack.push(JSON.parse(JSON.stringify(doc)));
    if (stack.length > MAX_ENTRIES) {
      stack.shift();
    }
    cursor = stack.length - 1;
  }
  function canUndo() {
    return cursor > 0;
  }
  function canRedo() {
    return cursor < stack.length - 1;
  }
  function undo() {
    if (!canUndo()) {
      return null;
    }
    cursor--;
    return JSON.parse(JSON.stringify(stack[cursor]));
  }
  function redo() {
    if (!canRedo()) {
      return null;
    }
    cursor++;
    return JSON.parse(JSON.stringify(stack[cursor]));
  }
  return {
    push: push,
    undo: undo,
    redo: redo,
    canUndo: canUndo,
    canRedo: canRedo
  };
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/recipes.js"
/*!**************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/recipes.js ***!
  \**************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   listRecipes: () => (/* binding */ listRecipes)
/* harmony export */ });
/* harmony import */ var _document_model_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./document-model.js */ "./resources/js/automations/workflow-builder/document-model.js");
// Automations V2 (contract §13.3, task requirement, V2-D) — recipe
// templates. A recipe is ONLY a starting document: each factory below
// produces the exact same §5.3 nested-list shape a hand-built workflow
// would, using nothing but the launch node/trigger vocabulary
// (constants.js). There is no second engine, no recipe-specific runtime,
// and no domain (Forms/Calendar/Payments/Pipeline/Tags) this contract does
// not support — every recipe here is mechanically re-derivable from
// NODE_TYPES alone.

function starter(triggerConfig, body) {
  var root = (0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('trigger', triggerConfig);
  root.next = body;
  return {
    schema_version: 1,
    root: root
  };
}
var TRIGGER_DEFAULTS = {
  contact_created: {
    trigger_type: 'contact_created',
    source: 'any',
    contact_group_id: null,
    enrollment_policy: 'once_ever',
    enrollment_policy_source: 'default',
    failure_policy: 'halt'
  },
  contact_date_reached: {
    trigger_type: 'contact_date_reached',
    contact_group_id: null,
    date_field_id: null,
    offset: '0 day',
    send_at: '09:00',
    enrollment_policy: 'once_per_occurrence',
    enrollment_policy_source: 'default',
    failure_policy: 'halt'
  }
};
function listRecipes() {
  return [{
    key: 'welcome_new_contact',
    titleKey: 'welcome_new_contact',
    descriptionKey: 'welcome_new_contact_description',
    build: function build() {
      return starter(Object.assign({}, TRIGGER_DEFAULTS.contact_created), [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('send_sms', {
        body: 'Hi {first_name}, thanks for reaching out! We will be in touch shortly.'
      })]);
    }
  }, {
    key: 'notify_team_new_contact',
    titleKey: 'notify_team_new_contact',
    descriptionKey: 'notify_team_new_contact_description',
    build: function build() {
      return starter(Object.assign({}, TRIGGER_DEFAULTS.contact_created), [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('internal_notification', {
        message: 'New contact: {first_name} {last_name}'
      })]);
    }
  }, {
    key: 'check_in_after_days',
    titleKey: 'check_in_after_days',
    descriptionKey: 'check_in_after_days_description',
    build: function build() {
      var wait = (0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('wait', {
        mode: 'duration',
        amount: 2,
        unit: 'days'
      });
      var branch = (0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('if_else', {
        match: 'all',
        conditions: [{
          subject: 'contact.subscribed',
          operator: 'is_true'
        }]
      });
      branch.yes = [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('send_sms', {
        body: 'Hi {first_name}, just checking in — still interested?'
      })];
      branch.no = [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('end', {})];

      // A straight chain is one flat sibling array (contract §5.3's
      // own example: n_1/n_2/n_3 all sit directly under root.next),
      // never nesting via one step's own `next` — the canvas
      // renderer and every insert/delete/move helper share that
      // one invariant throughout this module.
      return starter(Object.assign({}, TRIGGER_DEFAULTS.contact_created), [wait, branch]);
    }
  }, {
    key: 'date_reminder',
    titleKey: 'date_reminder',
    descriptionKey: 'date_reminder_description',
    build: function build() {
      return starter(Object.assign({}, TRIGGER_DEFAULTS.contact_date_reached), [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('send_sms', {
        body: 'Hi {first_name}, just a friendly reminder from {business_name}!'
      })]);
    }
  }];
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/validation.js"
/*!*****************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/validation.js ***!
  \*****************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   DOCUMENT_KEY: () => (/* binding */ DOCUMENT_KEY),
/* harmony export */   countIssues: () => (/* binding */ countIssues),
/* harmony export */   hasErrors: () => (/* binding */ hasErrors),
/* harmony export */   renderDocumentBanner: () => (/* binding */ renderDocumentBanner)
/* harmony export */ });
// Automations V2 (contract §14.4, V2-D) — turning the server's
// node_key-keyed errors into customer-facing feedback. The server is the
// only authority on what is valid (WorkflowDefinitionValidator); this
// module never re-derives a validation rule, it only places the server's
// own messages: document-level ones in a banner, per-step ones on their
// card (canvas-renderer.js reads the same `errors` map directly).
var DOCUMENT_KEY = '_document';
function hasErrors(errors) {
  return Object.keys(errors || {}).length > 0;
}
function countIssues(errors) {
  return Object.values(errors || {}).reduce(function (total, list) {
    return total + list.length;
  }, 0);
}
function renderDocumentBanner(bannerEl, errors, messageTemplate) {
  var documentMessages = errors && errors[DOCUMENT_KEY] || [];
  var total = countIssues(errors);
  if (total === 0) {
    bannerEl.classList.add('d-none');
    bannerEl.innerHTML = '';
    return;
  }
  bannerEl.classList.remove('d-none');
  bannerEl.innerHTML = '';
  var summary = document.createElement('p');
  summary.className = 'mb-1 fw-semibold';
  summary.textContent = messageTemplate.replace(':count', String(total));
  bannerEl.appendChild(summary);
  documentMessages.forEach(function (message) {
    var p = document.createElement('p');
    p.className = 'mb-0';
    p.textContent = message;
    bannerEl.appendChild(p);
  });
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/zoom-pan.js"
/*!***************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/zoom-pan.js ***!
  \***************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createZoomPan: () => (/* binding */ createZoomPan)
/* harmony export */ });
// Automations V2 (contract §13.1, V2-D) — lightweight viewport zoom/pan.
// No graph library: a CSS transform on one surface element, driven by
// wheel/pinch, drag, buttons and the keyboard. This state is presentation
// only — it is never read by autosave and never leaves this module, so
// zooming or panning can never modify the workflow document (task
// requirement, test #21).

var MIN_SCALE = 0.4;
var MAX_SCALE = 2;
var STEP = 1.2;
function createZoomPan(viewport, surface) {
  var scale = 1;
  var tx = 0;
  var ty = 0;
  var panning = false;
  var panStartX = 0;
  var panStartY = 0;
  var panOriginX = 0;
  var panOriginY = 0;
  function apply() {
    surface.style.transform = "translate(".concat(tx, "px, ").concat(ty, "px) scale(").concat(scale, ")");
  }
  function clampScale(value) {
    return Math.min(MAX_SCALE, Math.max(MIN_SCALE, value));
  }
  function zoomIn() {
    scale = clampScale(scale * STEP);
    apply();
  }
  function zoomOut() {
    scale = clampScale(scale / STEP);
    apply();
  }
  function reset() {
    scale = 1;
    tx = 0;
    ty = 0;
    apply();
  }
  function fit() {
    var viewportWidth = viewport.clientWidth;
    var surfaceWidth = surface.scrollWidth || viewportWidth;
    if (surfaceWidth > 0) {
      scale = clampScale(Math.min(1, viewportWidth / surfaceWidth));
    }
    tx = 0;
    ty = 0;
    apply();
  }
  function onWheel(event) {
    if (!event.ctrlKey && !event.metaKey) {
      return;
    }
    event.preventDefault();
    if (event.deltaY < 0) {
      zoomIn();
    } else {
      zoomOut();
    }
  }
  function onPointerDown(event) {
    if (event.button !== 0) {
      return;
    }
    panning = true;
    panStartX = event.clientX;
    panStartY = event.clientY;
    panOriginX = tx;
    panOriginY = ty;
    viewport.classList.add('is-panning');
  }
  function onPointerMove(event) {
    if (!panning) {
      return;
    }
    tx = panOriginX + (event.clientX - panStartX);
    ty = panOriginY + (event.clientY - panStartY);
    apply();
  }
  function onPointerUp() {
    panning = false;
    viewport.classList.remove('is-panning');
  }
  function onKeydown(event) {
    if (!(event.ctrlKey || event.metaKey)) {
      return;
    }
    if (event.key === '+' || event.key === '=') {
      event.preventDefault();
      zoomIn();
    } else if (event.key === '-') {
      event.preventDefault();
      zoomOut();
    } else if (event.key === '0') {
      event.preventDefault();
      reset();
    }
  }
  viewport.addEventListener('wheel', onWheel, {
    passive: false
  });
  viewport.addEventListener('mousedown', onPointerDown);
  window.addEventListener('mousemove', onPointerMove);
  window.addEventListener('mouseup', onPointerUp);
  viewport.addEventListener('keydown', onKeydown);
  apply();
  return {
    zoomIn: zoomIn,
    zoomOut: zoomOut,
    reset: reset,
    fit: fit,
    getState: function getState() {
      return {
        scale: scale,
        tx: tx,
        ty: ty
      };
    }
  };
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
/*!************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/index.js ***!
  \************************************************************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _canvas_renderer_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./canvas-renderer.js */ "./resources/js/automations/workflow-builder/canvas-renderer.js");
/* harmony import */ var _drawer_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./drawer.js */ "./resources/js/automations/workflow-builder/drawer.js");
/* harmony import */ var _history_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./history.js */ "./resources/js/automations/workflow-builder/history.js");
/* harmony import */ var _zoom_pan_js__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./zoom-pan.js */ "./resources/js/automations/workflow-builder/zoom-pan.js");
/* harmony import */ var _autosave_js__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./autosave.js */ "./resources/js/automations/workflow-builder/autosave.js");
/* harmony import */ var _api_js__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./api.js */ "./resources/js/automations/workflow-builder/api.js");
/* harmony import */ var _recipes_js__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./recipes.js */ "./resources/js/automations/workflow-builder/recipes.js");
/* harmony import */ var _document_model_js__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./document-model.js */ "./resources/js/automations/workflow-builder/document-model.js");
/* harmony import */ var _validation_js__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./validation.js */ "./resources/js/automations/workflow-builder/validation.js");
/* harmony import */ var _constants_js__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ./constants.js */ "./resources/js/automations/workflow-builder/constants.js");
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
function _createForOfIteratorHelper(r, e) { var t = "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (!t) { if (Array.isArray(r) || (t = _unsupportedIterableToArray(r)) || e && r && "number" == typeof r.length) { t && (r = t); var _n = 0, F = function F() {}; return { s: F, n: function n() { return _n >= r.length ? { done: !0 } : { done: !1, value: r[_n++] }; }, e: function e(r) { throw r; }, f: F }; } throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); } var o, a = !0, u = !1; return { s: function s() { t = t.call(r); }, n: function n() { var r = t.next(); return a = r.done, r; }, e: function e(r) { u = !0, o = r; }, f: function f() { try { a || null == t["return"] || t["return"](); } finally { if (u) throw o; } } }; }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
// Automations V2 (contract §13, V2-D) — the builder's entry point. One
// bundled ES module (contract §13.1), exposed as `window.AutomationsWorkflowBuilder`
// so the Blade views (which cannot use `import` directly) can call it after
// this bundle loads.










function initBuilder(root) {
  var dataEl = document.getElementById('wf-builder-data');
  if (!dataEl) {
    return;
  }
  var data = JSON.parse(dataEl.textContent);
  var doc = data.draft.definition;
  var errors = data.draft.errors || {};
  var selectedKey = null;
  var canvasRootEl = root.querySelector('[data-role="wf-canvas-root"]');
  var saveStateEl = root.querySelector('[data-role="wf-save-state"]');
  var bannerEl = root.querySelector('[data-role="wf-document-errors"]');
  var undoButton = root.querySelector('[data-role="wf-undo"]');
  var redoButton = root.querySelector('[data-role="wf-redo"]');
  var publishButton = root.querySelector('[data-role="wf-publish"]');
  var testButton = root.querySelector('[data-role="wf-test-workflow"]');
  var viewportEl = root.querySelector('[data-role="wf-canvas-viewport"]');
  var surfaceEl = root.querySelector('[data-role="wf-canvas-surface"]');
  var api = (0,_api_js__WEBPACK_IMPORTED_MODULE_5__.createApiClient)(data.basePath);
  var history = (0,_history_js__WEBPACK_IMPORTED_MODULE_2__.createHistory)(doc);
  var zoomPan = (0,_zoom_pan_js__WEBPACK_IMPORTED_MODULE_3__.createZoomPan)(viewportEl, surfaceEl);
  root.querySelector('[data-role="wf-zoom-in"]').addEventListener('click', zoomPan.zoomIn);
  root.querySelector('[data-role="wf-zoom-out"]').addEventListener('click', zoomPan.zoomOut);
  root.querySelector('[data-role="wf-zoom-reset"]').addEventListener('click', zoomPan.reset);
  root.querySelector('[data-role="wf-zoom-fit"]').addEventListener('click', zoomPan.fit);
  function setSaveState(state) {
    saveStateEl.dataset.state = state;
    var label = {
      saving: 'Saving…',
      saved: 'Saved',
      error: 'Offline — changes kept locally'
    }[state];
    saveStateEl.textContent = label || '';
  }
  var autosave = (0,_autosave_js__WEBPACK_IMPORTED_MODULE_4__.createAutosave)({
    api: api,
    workflowBasePath: data.basePath,
    initialRevision: data.draft.revision,
    onStateChange: setSaveState,
    onSaved: function onSaved(body) {
      errors = body.errors || {};
      rerender();
    },
    onConflict: function onConflict() {
      bannerEl.classList.remove('d-none');
      bannerEl.innerHTML = '<p class="mb-0">This workflow changed somewhere else. Reload to get the latest version before editing.</p>';
    }
  });
  var drawer = (0,_drawer_js__WEBPACK_IMPORTED_MODULE_1__.createDrawer)({
    drawerEl: root.querySelector('[data-role="wf-drawer"]'),
    catalogs: data.catalogs,
    dateOffsets: data.dateOffsets,
    contactSources: data.contactSources,
    onSave: function onSave(node, config) {
      node.config = config;
      onDocumentChanged();
    },
    onDelete: function onDelete(node) {
      deleteNode(node);
    }
  });
  function findContainingList(target, list) {
    if (list.includes(target)) {
      return list;
    }
    var _iterator = _createForOfIteratorHelper(list),
      _step;
    try {
      for (_iterator.s(); !(_step = _iterator.n()).done;) {
        var node = _step.value;
        if (node.type === 'if_else') {
          var inYes = findContainingList(target, node.yes || []);
          if (inYes) return inYes;
          var inNo = findContainingList(target, node.no || []);
          if (inNo) return inNo;
        } else if (Array.isArray(node.next)) {
          var found = findContainingList(target, node.next);
          if (found) return found;
        }
      }
    } catch (err) {
      _iterator.e(err);
    } finally {
      _iterator.f();
    }
    return null;
  }
  function deleteNode(node) {
    var list = findContainingList(node, doc.root.next || []);
    if (list) {
      (0,_document_model_js__WEBPACK_IMPORTED_MODULE_7__.removeFrom)(list, node);
      onDocumentChanged();
    }
  }
  function onDocumentChanged() {
    history.push(doc);
    rerender();
    autosave.schedule(doc);
  }
  function rerender() {
    var nodeCount = (0,_document_model_js__WEBPACK_IMPORTED_MODULE_7__.countNodes)(doc);
    (0,_canvas_renderer_js__WEBPACK_IMPORTED_MODULE_0__.renderCanvas)(doc, canvasRootEl, {
      onSelect: function onSelect(node) {
        selectedKey = node.key;
        drawer.open(node, errors[node.key] || []);
      },
      onAdd: function onAdd(list, index, depth, type) {
        var node = (0,_document_model_js__WEBPACK_IMPORTED_MODULE_7__.newNode)(type, (0,_constants_js__WEBPACK_IMPORTED_MODULE_9__.defaultConfigFor)(type));
        (0,_document_model_js__WEBPACK_IMPORTED_MODULE_7__.insertAt)(list, index, node);
        onDocumentChanged();
      },
      onDelete: function onDelete(node) {
        deleteNode(node);
      },
      onMove: function onMove(node, list, direction) {
        if ((0,_document_model_js__WEBPACK_IMPORTED_MODULE_7__.moveWithin)(list, node, direction)) {
          onDocumentChanged();
        }
      }
    }, {
      errors: errors,
      nodeCount: nodeCount,
      limits: data.limits,
      selectedKey: selectedKey
    });
    (0,_validation_js__WEBPACK_IMPORTED_MODULE_8__.renderDocumentBanner)(bannerEl, errors, 'This workflow has :count issue(s) to fix before it can publish.');
    undoButton.disabled = !history.canUndo();
    redoButton.disabled = !history.canRedo();
    publishButton.disabled = (0,_validation_js__WEBPACK_IMPORTED_MODULE_8__.hasErrors)(errors);
  }
  undoButton.addEventListener('click', function () {
    var restored = history.undo();
    if (restored) {
      doc = restored;
      rerender();
      autosave.schedule(doc);
    }
  });
  redoButton.addEventListener('click', function () {
    var restored = history.redo();
    if (restored) {
      doc = restored;
      rerender();
      autosave.schedule(doc);
    }
  });
  publishButton.addEventListener('click', /*#__PURE__*/_asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
    var result;
    return _regenerator().w(function (_context) {
      while (1) switch (_context.n) {
        case 0:
          _context.n = 1;
          return autosave.flushNow(doc);
        case 1:
          _context.n = 2;
          return api.publish(data.basePath);
        case 2:
          result = _context.v;
          if (!(result.status === 422)) {
            _context.n = 3;
            break;
          }
          errors = result.body && result.body.errors || {};
          rerender();
          return _context.a(2);
        case 3:
          if (result.status >= 200 && result.status < 300) {
            window.location.reload();
          }
        case 4:
          return _context.a(2);
      }
    }, _callee);
  })));
  testButton.addEventListener('click', /*#__PURE__*/_asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee2() {
    var contactUid, result;
    return _regenerator().w(function (_context2) {
      while (1) switch (_context2.n) {
        case 0:
          contactUid = window.prompt('Contact UID to simulate with:');
          if (contactUid) {
            _context2.n = 1;
            break;
          }
          return _context2.a(2);
        case 1:
          _context2.n = 2;
          return api.simulate(data.basePath, contactUid);
        case 2:
          result = _context2.v;
          if (result.body && result.body.path) {
            window.alert(result.body.path.map(function (step) {
              return step.label || step.node_type;
            }).join(' → '));
          }
        case 3:
          return _context2.a(2);
      }
    }, _callee2);
  })));
  rerender();
}
function initChooser(root) {
  var scratchCard = root.querySelector('[data-role="wf-chooser-scratch"]');
  var recipesContainer = root.querySelector('[data-role="wf-chooser-recipes"]');
  var createUrl = root.dataset.createUrl;
  var labelsEl = document.getElementById('wf-recipe-labels');
  var labels = labelsEl ? JSON.parse(labelsEl.textContent) : {};
  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }
  function createWorkflow(_x) {
    return _createWorkflow.apply(this, arguments);
  }
  function _createWorkflow() {
    _createWorkflow = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee3(payload) {
      var response, body;
      return _regenerator().w(function (_context3) {
        while (1) switch (_context3.n) {
          case 0:
            _context3.n = 1;
            return fetch(createUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken()
              },
              body: JSON.stringify(payload)
            });
          case 1:
            response = _context3.v;
            _context3.n = 2;
            return response.json()["catch"](function () {
              return null;
            });
          case 2:
            body = _context3.v;
            if (body && body.redirect) {
              window.location.href = body.redirect;
            } else if (body && body.workflow && body.workflow.uid) {
              window.location.href = "".concat(createUrl, "/").concat(body.workflow.uid);
            }
          case 3:
            return _context3.a(2);
        }
      }, _callee3);
    }));
    return _createWorkflow.apply(this, arguments);
  }
  scratchCard.addEventListener('click', function () {
    createWorkflow({
      mode: 'scratch'
    });
  });
  (0,_recipes_js__WEBPACK_IMPORTED_MODULE_6__.listRecipes)().forEach(function (recipe) {
    var col = document.createElement('div');
    col.className = 'col-12 col-lg-4';
    var card = document.createElement('div');
    card.className = 'card ds-card shadow-none h-100';
    card.style.cursor = 'pointer';
    card.dataset.role = 'wf-chooser-recipe';
    card.dataset.recipeKey = recipe.key;
    var body = document.createElement('div');
    body.className = 'card-body';
    var labelSet = labels[recipe.key] || {};
    var title = document.createElement('h5');
    title.className = 'text-section-heading';
    title.textContent = labelSet.title || recipe.titleKey;
    var description = document.createElement('p');
    description.className = 'text-caption mb-0';
    description.textContent = labelSet.description || recipe.descriptionKey;
    body.appendChild(title);
    body.appendChild(description);
    card.appendChild(body);
    col.appendChild(card);
    recipesContainer.appendChild(col);
    card.addEventListener('click', function () {
      createWorkflow({
        mode: 'recipe',
        recipe: recipe.key,
        definition: recipe.build()
      });
    });
  });
}
window.AutomationsWorkflowBuilder = {
  initBuilder: initBuilder,
  initChooser: initChooser
};
})();

/******/ })()
;