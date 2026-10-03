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
    openDraft: function openDraft(workflowBasePath) {
      return request("".concat(workflowBasePath, "/draft"), {
        method: 'GET'
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
    },
    testContacts: function testContacts(workflowBasePath, search) {
      return request("".concat(workflowBasePath, "/test-contacts?q=").concat(encodeURIComponent(search || '')), {
        method: 'GET'
      });
    },
    enrollments: function enrollments(workflowBasePath) {
      return request("".concat(workflowBasePath, "/enrollments"), {
        method: 'GET'
      });
    },
    enrollmentLogs: function enrollmentLogs(workflowBasePath, enrollmentUid) {
      return request("".concat(workflowBasePath, "/enrollments/").concat(encodeURIComponent(enrollmentUid), "/logs"), {
        method: 'GET'
      });
    },
    pause: function pause(workflowBasePath) {
      return request("".concat(workflowBasePath, "/pause"), {
        method: 'POST'
      });
    },
    resume: function resume(workflowBasePath) {
      return request("".concat(workflowBasePath, "/resume"), {
        method: 'POST'
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
  /** Save now only if an edit is still waiting for its debounce. */
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
  function flushPending() {
    if (pendingDocument === null) {
      return Promise.resolve();
    }
    return flushNow(pendingDocument);
  }
  return {
    schedule: schedule,
    flushNow: flushNow,
    flushPending: flushPending,
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
/* harmony import */ var _summaries_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./summaries.js */ "./resources/js/automations/workflow-builder/summaries.js");
/* harmony import */ var _dom_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./dom.js */ "./resources/js/automations/workflow-builder/dom.js");
function _slicedToArray(r, e) { return _arrayWithHoles(r) || _iterableToArrayLimit(r, e) || _unsupportedIterableToArray(r, e) || _nonIterableRest(); }
function _nonIterableRest() { throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
function _iterableToArrayLimit(r, l) { var t = null == r ? null : "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (null != t) { var e, n, i, u, a = [], f = !0, o = !1; try { if (i = (t = t.call(r)).next, 0 === l) { if (Object(t) !== t) return; f = !1; } else for (; !(f = (e = i.call(t)).done) && (a.push(e.value), a.length !== l); f = !0); } catch (r) { o = !0, n = r; } finally { try { if (!f && null != t["return"] && (u = t["return"](), Object(u) !== u)) return; } finally { if (o) throw n; } } return a; } }
function _arrayWithHoles(r) { if (Array.isArray(r)) return r; }
// Automations V2 (contract §5.1, §13.1, V2-D) — the canvas.
//
// Because the graph is a tree, it lays itself out: the trigger fixed at the
// top, then one vertical, connected column of step cards, with an If / Else
// opening two side-by-side lanes (contract §13.1). No layout engine, no
// edge-routing and no canvas library — nested DOM, built as real elements so
// every "+", move and delete closes over the exact array it acts on, the same
// array document-model.js splices.
//
// HONEST ENDINGS. The schema has no "merge": an If / Else is the last step of
// its path, and its Yes and No lanes never rejoin (WorkflowDefinitionValidator).
// So the canvas never draws lanes converging. Every path is capped with an
// explicit end marker instead — "End of path" under each lane, "Workflow ends"
// under the main column — which is exactly what the runtime does when a path
// runs out of steps.




/**
 * @param {Object} doc the current document (mutated in place by callbacks)
 * @param {HTMLElement} rootEl the `<ol data-role="wf-canvas-root">` element
 * @param {Object} handlers
 *   onSelect(node)
 *   onRequestAdd(anchorEl, list, index, depth)
 *   onDelete(node, list)
 *   onMove(node, list, direction)     — direction is -1 or 1
 * @param {Object} state { errors, nodeCount, limits, selectedKey, readOnly, catalogs, path }
 *   path: null, or Map(node key → simulated step) while a test result is shown
 */
function renderCanvas(doc, rootEl, handlers, state) {
  rootEl.innerHTML = '';
  rootEl.className = 'wf-flow wf-flow--main';
  var trigger = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('li', 'wf-flow__item');
  trigger.appendChild(renderCard(doc.root, null, 0, handlers, state));
  rootEl.appendChild(trigger);
  renderSequenceInto(rootEl, doc.root.next || [], handlers, state, 0, 'Workflow ends');
}
function onPath(state, key) {
  return Boolean(state.path && state.path.has(key));
}

/**
 * Append one sequence's steps to `listEl`: a link (line + "+") before each
 * step, a trailing link when the path is still open, and the end marker when
 * nothing closes the path on its own.
 */
function renderSequenceInto(listEl, list, handlers, state, depth, endLabel) {
  list.forEach(function (node, index) {
    listEl.appendChild(renderLink(list, index, depth, handlers, state));
    var item = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('li', 'wf-flow__item');
    item.appendChild(renderCard(node, list, index, handlers, state));
    if ((0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.isBranching)(node.type)) {
      item.appendChild(renderBranches(node, handlers, state, depth));
    } else if (!(0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.isTerminal)(node.type) && Array.isArray(node.next) && node.next.length > 0) {
      // Every edit in this builder keeps a straight chain as one flat
      // sibling array (contract §5.3's own example). A document nesting a
      // continuation in a step's own `next` — loaded from elsewhere — is
      // still drawn in full rather than silently dropping steps; the
      // validator walks that shape too.
      var nested = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('ol', 'wf-flow');
      renderSequenceInto(nested, node.next, handlers, state, depth, endLabel);
      item.appendChild(nested);
    }
    listEl.appendChild(item);
  });
  var last = list[list.length - 1];
  if (last && (0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.closesSequence)(last.type)) {
    return;
  }
  listEl.appendChild(renderLink(list, list.length, depth, handlers, state));
  var end = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('li', 'wf-flow__item wf-end');
  end.dataset.role = 'wf-path-end';
  end.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.icon)('flag'));
  end.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('span', null, endLabel));
  listEl.appendChild(end);
}
function renderLink(list, index, depth, handlers, state) {
  var link = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('li', 'wf-link');
  link.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('span', 'wf-link__line'));
  if (state.readOnly) {
    return link;
  }
  var atCapacity = typeof state.nodeCount === 'number' && state.limits && state.nodeCount >= state.limits.maxNodes;
  var button = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('button', 'wf-add');
  button.type = 'button';
  button.dataset.role = 'wf-add-step';
  button.setAttribute('aria-haspopup', 'dialog');
  button.setAttribute('aria-expanded', 'false');
  button.setAttribute('aria-label', atCapacity ? 'This workflow has reached its step limit' : 'Add a step here');
  button.title = atCapacity ? 'This workflow has reached its step limit' : 'Add a step';
  button.disabled = atCapacity;
  button.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.icon)('plus'));
  button.addEventListener('click', function () {
    return handlers.onRequestAdd(button, list, index, depth);
  });
  link.appendChild(button);
  link.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('span', 'wf-link__line'));
  return link;
}
function renderCard(node, list, index, handlers, state) {
  var isTrigger = list === null;
  var _summarize = (0,_summaries_js__WEBPACK_IMPORTED_MODULE_1__.summarize)(node, state.catalogs || {}),
    title = _summarize.title,
    summary = _summarize.summary,
    incomplete = _summarize.incomplete;
  var messages = state.errors && state.errors[node.key] || [];
  var hasErrors = messages.length > 0;
  var wrap = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('div', 'wf-card-wrap');
  var card = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('div', "wf-card wf-card--".concat(node.type));
  card.dataset.nodeKey = node.key;
  card.dataset.nodeType = node.type;
  card.dataset.role = 'wf-step-card';
  card.tabIndex = 0;
  card.setAttribute('role', 'button');
  var label = title || _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_LABELS[node.type] || 'Step';
  card.setAttribute('aria-label', "".concat(isTrigger ? 'Trigger: ' : '').concat(label, ". ").concat(summary).concat(hasErrors ? '. Needs attention.' : ''));
  if (state.selectedKey === node.key) {
    card.classList.add('is-selected');
  }
  if (hasErrors) {
    card.classList.add('has-error');
  }
  if (state.path) {
    card.classList.add(onPath(state, node.key) ? 'is-on-path' : 'is-off-path');
  }
  card.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.icon)(_constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_ICONS[node.type] || 'circle-stop', "wf-tone wf-tone--".concat(node.type)));
  var body = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('div', 'wf-card__body');
  body.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('span', 'wf-card__eyebrow', isTrigger ? 'Trigger' : _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_LABELS[node.type]));
  if (isTrigger) {
    body.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('span', 'wf-card__title', label));
  }
  body.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('span', "wf-card__summary".concat(incomplete ? ' is-incomplete' : ''), summary));
  card.appendChild(body);
  if (hasErrors) {
    var flag = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.icon)('triangle-alert', 'wf-card__flag');
    flag.title = 'Needs attention';
    card.appendChild(flag);
  }
  if (!isTrigger && !state.readOnly) {
    card.appendChild(renderMenu(node, list, index, handlers));
  }
  card.addEventListener('click', function (event) {
    if (event.target.closest('.wf-card__menu')) {
      return;
    }
    handlers.onSelect(node);
  });
  card.addEventListener('keydown', function (event) {
    if (event.target !== card) {
      return;
    }
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      handlers.onSelect(node);
    } else if (event.key === 'Delete' && !isTrigger && !state.readOnly) {
      event.preventDefault();
      handlers.onDelete(node, list);
    }
  });
  wrap.appendChild(card);
  if (hasErrors) {
    var errorList = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('ul', 'wf-card__errors');
    messages.forEach(function (message) {
      return errorList.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('li', null, message));
    });
    wrap.appendChild(errorList);
  }
  return wrap;
}
function renderMenu(node, list, index, handlers) {
  var menuWrap = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('div', 'dropdown wf-card__menu');
  var button = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('button', 'wf-card__menu-button');
  button.type = 'button';
  button.setAttribute('data-bs-toggle', 'dropdown');
  button.setAttribute('aria-expanded', 'false');
  button.setAttribute('aria-label', 'Step options');
  button.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.icon)('ellipsis-vertical'));
  menuWrap.appendChild(button);
  var menu = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('ul', 'dropdown-menu dropdown-menu-end');

  // Moves are offered only where the result is still a valid path: a step
  // that closes its path (If / Else, End) must stay last, so it never moves
  // up, and nothing moves down past one.
  var next = list[index + 1];
  var canMoveUp = index > 0 && !(0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.closesSequence)(node.type);
  var canMoveDown = index < list.length - 1 && !(0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.closesSequence)(next.type);
  menu.appendChild(menuItem('Move up', 'chevron-up', canMoveUp, function () {
    return handlers.onMove(node, list, -1);
  }));
  menu.appendChild(menuItem('Move down', 'chevron-down', canMoveDown, function () {
    return handlers.onMove(node, list, 1);
  }));
  menu.appendChild(menuItem('Delete step', 'trash-2', true, function () {
    return handlers.onDelete(node, list);
  }, 'text-danger'));
  menuWrap.appendChild(menu);
  return menuWrap;
}
function menuItem(label, iconName, enabled, onClick, extraClass) {
  var li = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('li');
  var button = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('button', "dropdown-item d-flex align-items-center gap-2".concat(extraClass ? " ".concat(extraClass) : ''));
  button.type = 'button';
  button.disabled = !enabled;
  button.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.icon)(iconName));
  button.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('span', null, label));
  button.addEventListener('click', onClick);
  li.appendChild(button);
  return li;
}
function renderBranches(node, handlers, state, depth) {
  var split = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('div', 'wf-split');
  split.dataset.role = 'wf-branches';
  var taken = state.path && state.path.has(node.key) ? state.path.get(node.key).branch : null;
  [['yes', 'Yes', node.yes || (node.yes = [])], ['no', 'No', node.no || (node.no = [])]].forEach(function (_ref) {
    var _ref2 = _slicedToArray(_ref, 3),
      lane = _ref2[0],
      label = _ref2[1],
      list = _ref2[2];
    var column = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('div', "wf-lane wf-lane--".concat(lane));
    column.dataset.role = "wf-lane-".concat(lane);
    if (taken) {
      column.classList.add(taken === lane ? 'is-taken' : 'is-not-taken');
    }
    var head = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('div', 'wf-lane__head');
    head.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('span', 'wf-lane__line'));
    head.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('span', "wf-lane__label wf-lane__label--".concat(lane), label));
    column.appendChild(head);
    var sequence = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('ol', 'wf-flow');
    renderSequenceInto(sequence, list, handlers, state, depth + 1, 'End of path');
    column.appendChild(sequence);
    split.appendChild(column);
  });
  return split;
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
/* harmony export */   CUSTOM_FIELD_PREFIX: () => (/* binding */ CUSTOM_FIELD_PREFIX),
/* harmony export */   FACT_SUBJECTS: () => (/* binding */ FACT_SUBJECTS),
/* harmony export */   GROUP_SUBJECTS: () => (/* binding */ GROUP_SUBJECTS),
/* harmony export */   HAS_TAG_PREFIX: () => (/* binding */ HAS_TAG_PREFIX),
/* harmony export */   REPLIED_SUBJECT: () => (/* binding */ REPLIED_SUBJECT),
/* harmony export */   STAGE_SUBJECT: () => (/* binding */ STAGE_SUBJECT),
/* harmony export */   SUBJECT_LABELS: () => (/* binding */ SUBJECT_LABELS),
/* harmony export */   TEXT_SUBJECTS: () => (/* binding */ TEXT_SUBJECTS),
/* harmony export */   customFieldId: () => (/* binding */ customFieldId),
/* harmony export */   describeCondition: () => (/* binding */ describeCondition),
/* harmony export */   factSubjectAvailable: () => (/* binding */ factSubjectAvailable),
/* harmony export */   isDateSubject: () => (/* binding */ isDateSubject),
/* harmony export */   needsOperand: () => (/* binding */ needsOperand),
/* harmony export */   operatorLabel: () => (/* binding */ operatorLabel),
/* harmony export */   subjectLabel: () => (/* binding */ subjectLabel),
/* harmony export */   subjectOperators: () => (/* binding */ subjectOperators),
/* harmony export */   tagId: () => (/* binding */ tagId)
/* harmony export */ });
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
// Automations V2 (contract §11) — the closed condition-subject vocabulary this
// builder may offer, in customer words. It mirrors ConditionSubjectRegistry
// exactly: the four identity subjects, "subscribed", "in group", a group's
// custom fields, V2-F's `contact.replied_since_enrollment` — offered now
// that the inbound producer it reads from has shipped — and the Business's
// own tags (`contact.has_tag:{id}`, "has tag" / "does not have tag").
// Nothing here is a Lead, Form or Payment subject, because none of those is
// registered.
//
// Operator families follow ConditionOperator exactly (forText, forBoolean,
// forDate, forReference). A custom field's family follows its own type the
// same way the compiler decides it: only a `date` field compares as a date.

var REPLIED_SUBJECT = 'contact.replied_since_enrollment';
var CUSTOM_FIELD_PREFIX = 'contact.custom_field:';
var HAS_TAG_PREFIX = 'contact.has_tag:';
var TEXT_SUBJECTS = ['contact.first_name', 'contact.last_name', 'contact.email', 'contact.company'];
var BOOLEAN_SUBJECTS = ['contact.subscribed', REPLIED_SUBJECT, 'document.signed', 'document.paid'];
var GROUP_SUBJECTS = ['contact.in_group'];

// The subjects that read the deal, document, payment or appointment behind a
// journey (ConditionSubjectRegistry::FACT_SUBJECTS). Each has a closed set of
// values (or, for the stage, a stage of this Business) and the trigger family that
// can give it something to read.
var STAGE_SUBJECT = 'opportunity.stage';
var FACT_SUBJECTS = _defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty({}, STAGE_SUBJECT, {
  label: 'Opportunity stage',
  kind: 'reference',
  needs: null
}), 'opportunity.status', {
  label: 'Opportunity status',
  kind: 'choice',
  needs: null,
  values: {
    open: 'Open',
    won: 'Won',
    lost: 'Lost'
  }
}), 'document.status', {
  label: 'Document status',
  kind: 'choice',
  needs: 'document',
  values: {
    draft: 'Draft',
    sent: 'Sent',
    signed: 'Signed',
    paid: 'Paid',
    expired: 'Expired',
    "void": 'Void'
  }
}), 'document.signed', {
  label: 'Document signed',
  kind: 'boolean',
  needs: 'document'
}), 'document.paid', {
  label: 'Document paid',
  kind: 'boolean',
  needs: 'document'
}), 'payment.status', {
  label: 'Payment status',
  kind: 'choice',
  needs: 'payment',
  values: {
    succeeded: 'Succeeded',
    failed: 'Failed',
    processing: 'Processing',
    requires_action: 'Needs customer action',
    created: 'Started',
    canceled: 'Cancelled'
  }
}), 'appointment.status', {
  label: 'Appointment status',
  kind: 'choice',
  needs: 'appointment',
  values: {
    scheduled: 'Scheduled',
    cancelled: 'Cancelled',
    completed: 'Completed',
    no_show: 'No-show'
  }
});
var DOCUMENT_TRIGGERS = ['document_sent', 'document_signed', 'payment_succeeded', 'payment_failed'];

/** Whether the workflow's trigger gives this fact subject something to read. */
function factSubjectAvailable(subject, triggerType) {
  var meta = FACT_SUBJECTS[subject];
  if (!meta || meta.needs === null) {
    return true;
  }
  if (meta.needs === 'document') {
    return DOCUMENT_TRIGGERS.includes(triggerType);
  }
  if (meta.needs === 'payment') {
    return ['payment_succeeded', 'payment_failed'].includes(triggerType);
  }
  return ['appointment_scheduled', 'appointment_cancelled', 'appointment_rescheduled'].includes(triggerType);
}
var SUBJECT_LABELS = _defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty({}, REPLIED_SUBJECT, 'Customer replied'), 'contact.first_name', 'First name'), 'contact.last_name', 'Last name'), 'contact.email', 'Email'), 'contact.company', 'Company'), 'contact.subscribed', 'Subscribed to texts'), 'contact.in_group', 'Contact group');
var TEXT_OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty'];
var BOOLEAN_OPERATORS = ['is_true', 'is_false'];
var REFERENCE_OPERATORS = ['equals', 'not_equals'];
var DATE_OPERATORS = ['before', 'after', 'on_date', 'is_empty', 'is_not_empty'];
var OPERATOR_LABELS = {
  equals: 'is',
  not_equals: 'is not',
  contains: 'contains',
  not_contains: 'does not contain',
  is_empty: 'is empty',
  is_not_empty: 'is not empty',
  before: 'is before',
  after: 'is after',
  on_date: 'is on'
};
var BOOLEAN_OPERATOR_LABELS = _defineProperty({
  'document.signed': {
    is_true: 'is signed',
    is_false: 'is not signed yet'
  },
  'document.paid': {
    is_true: 'is paid',
    is_false: 'is not paid yet'
  },
  'contact.subscribed': {
    is_true: 'is subscribed',
    is_false: 'is not subscribed'
  }
}, REPLIED_SUBJECT, {
  is_true: 'has replied',
  is_false: 'has not replied yet'
});
var OPERAND_FREE = ['is_empty', 'is_not_empty', 'is_true', 'is_false'];

/** The tag id in a `contact.has_tag:{id}` subject, or null when it is not one. */
function tagId(subject) {
  if (typeof subject !== 'string' || !subject.startsWith(HAS_TAG_PREFIX)) {
    return null;
  }
  var raw = subject.slice(HAS_TAG_PREFIX.length);
  return /^\d+$/.test(raw) && Number(raw) > 0 ? Number(raw) : null;
}
function tagFor(subject, catalogs) {
  var id = tagId(subject);
  if (id === null || !catalogs) {
    return null;
  }
  return (catalogs.tags || []).find(function (tag) {
    return Number(tag.id) === id;
  }) || null;
}
function customFieldId(subject) {
  if (typeof subject !== 'string' || !subject.startsWith(CUSTOM_FIELD_PREFIX)) {
    return null;
  }
  var raw = subject.slice(CUSTOM_FIELD_PREFIX.length);
  return /^\d+$/.test(raw) && Number(raw) > 0 ? Number(raw) : null;
}
function fieldFor(subject, catalogs) {
  var id = customFieldId(subject);
  if (id === null || !catalogs) {
    return null;
  }
  return (catalogs.writableFields || []).find(function (field) {
    return Number(field.id) === id;
  }) || null;
}
function isDateSubject(subject, catalogs) {
  var field = fieldFor(subject, catalogs);
  return field !== null && field.type === 'date';
}
function subjectOperators(subject, catalogs) {
  if (BOOLEAN_SUBJECTS.includes(subject) || tagId(subject) !== null) {
    return BOOLEAN_OPERATORS;
  }
  if (GROUP_SUBJECTS.includes(subject) || FACT_SUBJECTS[subject] && FACT_SUBJECTS[subject].kind !== 'boolean') {
    return REFERENCE_OPERATORS;
  }
  if (customFieldId(subject) !== null) {
    return isDateSubject(subject, catalogs) ? DATE_OPERATORS : TEXT_OPERATORS;
  }
  return TEXT_OPERATORS;
}
function needsOperand(operator) {
  return !OPERAND_FREE.includes(operator);
}
function subjectLabel(subject, catalogs) {
  if (SUBJECT_LABELS[subject]) {
    return SUBJECT_LABELS[subject];
  }
  if (FACT_SUBJECTS[subject]) {
    return FACT_SUBJECTS[subject].label;
  }
  if (tagId(subject) !== null) {
    var tag = tagFor(subject, catalogs);
    return tag ? "Tag \u201C".concat(tag.name, "\u201D") : 'A tag';
  }
  var field = fieldFor(subject, catalogs);
  return field ? field.label : 'A contact field';
}
function operatorLabel(subject, operator) {
  if (tagId(subject) !== null && (operator === 'is_true' || operator === 'is_false')) {
    return operator === 'is_true' ? 'is on the contact' : 'is not on the contact';
  }
  var booleanLabels = BOOLEAN_OPERATOR_LABELS[subject];
  if (booleanLabels && booleanLabels[operator]) {
    return booleanLabels[operator];
  }
  return OPERATOR_LABELS[operator] || operator.replace(/_/g, ' ');
}
function groupName(id, catalogs) {
  var group = (catalogs && catalogs.contactGroups || []).find(function (row) {
    return String(row.id) === String(id);
  });
  return group ? group.name : 'a group';
}

/** One condition in a sentence: "Customer has not replied yet", "First name is “Sam”". */
function describeCondition(condition, catalogs) {
  if (!condition || !condition.subject || !condition.operator) {
    return 'An unfinished condition';
  }
  var subject = condition.subject,
    operator = condition.operator;
  if (subject === REPLIED_SUBJECT) {
    return "Customer ".concat(operatorLabel(subject, operator));
  }
  if (subject === 'contact.subscribed') {
    return "Contact ".concat(operatorLabel(subject, operator));
  }
  if (tagId(subject) !== null) {
    var tag = tagFor(subject, catalogs);
    var name = tag ? "\u201C".concat(tag.name, "\u201D") : 'a tag';
    return operator === 'is_false' ? "Contact does not have the tag ".concat(name) : "Contact has the tag ".concat(name);
  }
  if (FACT_SUBJECTS[subject]) {
    var _find;
    var meta = FACT_SUBJECTS[subject];
    if (meta.kind === 'boolean') {
      return "".concat(meta.label.replace(/ (signed|paid)$/, ''), " ").concat(operatorLabel(subject, operator));
    }
    var _operand = meta.kind === 'reference' ? (_find = (catalogs && catalogs.crmStages || []).find(function (row) {
      return String(row.id) === String(condition.operand);
    })) === null || _find === void 0 ? void 0 : _find.name : meta.values[condition.operand];
    return "".concat(meta.label, " ").concat(operatorLabel(subject, operator), " \u201C").concat(_operand || '…', "\u201D");
  }
  if (subject === 'contact.in_group') {
    return operator === 'not_equals' ? "Contact is not in ".concat(groupName(condition.operand, catalogs)) : "Contact is in ".concat(groupName(condition.operand, catalogs));
  }
  var label = subjectLabel(subject, catalogs);
  var words = operatorLabel(subject, operator);
  if (!needsOperand(operator)) {
    return "".concat(label, " ").concat(words);
  }
  var operand = condition.operand === undefined || condition.operand === null || condition.operand === '' ? '…' : condition.operand;
  return "".concat(label, " ").concat(words, " \u201C").concat(operand, "\u201D");
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/constants.js"
/*!****************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/constants.js ***!
  \****************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   CONTACT_SOURCE_LABELS: () => (/* binding */ CONTACT_SOURCE_LABELS),
/* harmony export */   ENROLLMENT_POLICY_LABELS: () => (/* binding */ ENROLLMENT_POLICY_LABELS),
/* harmony export */   INSERTABLE_TYPES: () => (/* binding */ INSERTABLE_TYPES),
/* harmony export */   NODE_ICONS: () => (/* binding */ NODE_ICONS),
/* harmony export */   NODE_LABELS: () => (/* binding */ NODE_LABELS),
/* harmony export */   NODE_TYPES: () => (/* binding */ NODE_TYPES),
/* harmony export */   STEP_CATALOG: () => (/* binding */ STEP_CATALOG),
/* harmony export */   STEP_GROUPS: () => (/* binding */ STEP_GROUPS),
/* harmony export */   TRIGGER_GROUPS: () => (/* binding */ TRIGGER_GROUPS),
/* harmony export */   TRIGGER_TYPES: () => (/* binding */ TRIGGER_TYPES),
/* harmony export */   closesSequence: () => (/* binding */ closesSequence),
/* harmony export */   defaultConfigFor: () => (/* binding */ defaultConfigFor),
/* harmony export */   isBranching: () => (/* binding */ isBranching),
/* harmony export */   isTerminal: () => (/* binding */ isTerminal),
/* harmony export */   offsetLabel: () => (/* binding */ offsetLabel),
/* harmony export */   triggerTypeInfo: () => (/* binding */ triggerTypeInfo)
/* harmony export */ });
var _NODE_LABELS;
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
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
  SEND_EMAIL: 'send_email',
  ADD_TAG: 'add_tag',
  REMOVE_TAG: 'remove_tag',
  MOVE_OPPORTUNITY: 'move_opportunity',
  SEND_BOOKING_LINK: 'send_booking_link',
  SEND_FORM: 'send_form',
  SEND_QUESTIONNAIRE: 'send_questionnaire',
  CREATE_SEND_PROPOSAL: 'create_send_proposal',
  REQUEST_PAYMENT: 'request_payment',
  WAIT: 'wait',
  IF_ELSE: 'if_else',
  END: 'end'
};

// Customer wording for each step. Outcome-first: what the step does for the
// business, never how the engine names it.
var NODE_LABELS = (_NODE_LABELS = {}, _defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_NODE_LABELS, NODE_TYPES.TRIGGER, 'Trigger'), NODE_TYPES.SEND_SMS, 'Send text message'), NODE_TYPES.UPDATE_CONTACT_FIELD, 'Update contact field'), NODE_TYPES.INTERNAL_NOTIFICATION, 'Notify your team'), NODE_TYPES.SEND_EMAIL, 'Send email'), NODE_TYPES.ADD_TAG, 'Add tag'), NODE_TYPES.REMOVE_TAG, 'Remove tag'), NODE_TYPES.MOVE_OPPORTUNITY, 'Move opportunity'), NODE_TYPES.SEND_BOOKING_LINK, 'Send booking link'), NODE_TYPES.SEND_FORM, 'Send form'), _defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_defineProperty(_NODE_LABELS, NODE_TYPES.SEND_QUESTIONNAIRE, 'Send questionnaire'), NODE_TYPES.CREATE_SEND_PROPOSAL, 'Create & send proposal or contract'), NODE_TYPES.REQUEST_PAYMENT, 'Request payment'), NODE_TYPES.WAIT, 'Wait'), NODE_TYPES.IF_ELSE, 'If / Else'), NODE_TYPES.END, 'End workflow'));

// The step picker's catalogue. Every entry is a registered node type; the
// keywords are only there so a search for "delay", "sms" or "branch" finds
// the step a person means. `group` is how the picker sections them, in this
// order; `requires` is the account capability the step needs (the server's
// WorkflowCapabilities) — a step the account cannot use is shown, disabled,
// with the reason, rather than offered and refused at publish.
var STEP_GROUPS = ['Messaging', 'CRM', 'Documents', 'Payments', 'Internal', 'Flow'];
var STEP_CATALOG = [{
  type: NODE_TYPES.SEND_EMAIL,
  group: 'Messaging',
  description: 'Email the contact from your connected mailbox. Personalise it with their name.',
  keywords: ['email', 'mail', 'message', 'send', 'follow up', 'confirmation'],
  icon: 'mail',
  requires: 'email'
}, {
  type: NODE_TYPES.SEND_SMS,
  group: 'Messaging',
  description: 'Text the contact. Personalise it with their name.',
  keywords: ['sms', 'text', 'message', 'send', 'follow up', 'reminder'],
  icon: 'message-square-text',
  requires: 'sms'
}, {
  type: NODE_TYPES.SEND_BOOKING_LINK,
  group: 'Messaging',
  description: 'Send the link to book one of your booking types, by email, text, or both.',
  keywords: ['booking', 'book', 'appointment', 'schedule', 'calendar', 'link'],
  icon: 'calendar-plus',
  requires: 'calendar'
}, {
  type: NODE_TYPES.SEND_FORM,
  group: 'Messaging',
  description: 'Send the link to one of your forms, by email, text, or both.',
  keywords: ['form', 'link', 'collect', 'intake', 'enquiry'],
  icon: 'clipboard-list',
  requires: 'forms'
}, {
  type: NODE_TYPES.SEND_QUESTIONNAIRE,
  group: 'Messaging',
  description: 'Send the link to one of your multi-step questionnaires, by email, text, or both.',
  keywords: ['questionnaire', 'survey', 'questions', 'link', 'intake', 'feedback'],
  icon: 'list-checks',
  requires: 'forms'
}, {
  type: NODE_TYPES.ADD_TAG,
  group: 'CRM',
  description: 'Tag the contact so you can find, segment or follow up with them later.',
  keywords: ['tag', 'label', 'add', 'segment', 'mark'],
  icon: 'tag'
}, {
  type: NODE_TYPES.REMOVE_TAG,
  group: 'CRM',
  description: 'Take a tag off the contact.',
  keywords: ['tag', 'label', 'remove', 'untag', 'clear'],
  icon: 'tag'
}, {
  type: NODE_TYPES.UPDATE_CONTACT_FIELD,
  group: 'CRM',
  description: 'Save a value on the contact, such as a status or a note.',
  keywords: ['update', 'field', 'contact', 'save', 'set', 'status', 'note'],
  icon: 'user-pen'
}, {
  type: NODE_TYPES.MOVE_OPPORTUNITY,
  group: 'CRM',
  description: "Move the contact's opportunity to another stage of its pipeline.",
  keywords: ['opportunity', 'deal', 'stage', 'pipeline', 'move', 'crm', 'sales'],
  icon: 'arrow-right-left',
  requires: 'crm'
}, {
  type: NODE_TYPES.CREATE_SEND_PROPOSAL,
  group: 'Documents',
  description: 'Create a proposal or contract from one of your products or packages and email it for signing.',
  keywords: ['proposal', 'contract', 'quote', 'agreement', 'sign', 'document', 'send'],
  icon: 'file-signature',
  requires: 'documents'
}, {
  type: NODE_TYPES.REQUEST_PAYMENT,
  group: 'Payments',
  description: 'Email a secure payment link: for the document this workflow is about, or a new invoice.',
  keywords: ['payment', 'pay', 'invoice', 'deposit', 'charge', 'money', 'request'],
  icon: 'credit-card',
  requires: 'payments'
}, {
  type: NODE_TYPES.INTERNAL_NOTIFICATION,
  group: 'Internal',
  description: 'Let your team know something needs their attention.',
  keywords: ['notify', 'alert', 'team', 'staff', 'owner', 'tell'],
  icon: 'bell'
}, {
  type: NODE_TYPES.WAIT,
  group: 'Flow',
  description: 'Pause for a while, or until a set date and time.',
  keywords: ['wait', 'delay', 'pause', 'later', 'timer', 'days', 'hours', 'minutes'],
  icon: 'clock'
}, {
  type: NODE_TYPES.IF_ELSE,
  group: 'Flow',
  description: 'Split the path: one set of steps if something is true, another if not.',
  keywords: ['if', 'else', 'branch', 'condition', 'split', 'check', 'replied', 'decide'],
  icon: 'split'
}, {
  type: NODE_TYPES.END,
  group: 'Flow',
  description: 'Stop the workflow for this contact here.',
  keywords: ['end', 'stop', 'finish', 'exit', 'done'],
  icon: 'circle-stop'
}];
var NODE_ICONS = _objectSpread(_defineProperty({}, NODE_TYPES.TRIGGER, 'zap'), Object.fromEntries(STEP_CATALOG.map(function (entry) {
  return [entry.type, entry.icon];
})));

// Types a customer may insert anywhere in the body (never the trigger,
// which is always the one root the document validator requires).
var INSERTABLE_TYPES = STEP_CATALOG.map(function (entry) {
  return entry.type;
});
function isBranching(type) {
  return type === NODE_TYPES.IF_ELSE;
}
function isTerminal(type) {
  return type === NODE_TYPES.END;
}

/** A step that must be the last in its path (§5.3): If / Else and End. */
function closesSequence(type) {
  return isBranching(type) || isTerminal(type);
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
    case NODE_TYPES.SEND_EMAIL:
      return {
        subject: '',
        body: ''
      };
    case NODE_TYPES.ADD_TAG:
    case NODE_TYPES.REMOVE_TAG:
      return {
        tag_id: null
      };
    case NODE_TYPES.MOVE_OPPORTUNITY:
      return {
        pipeline_id: null,
        stage_id: null
      };
    case NODE_TYPES.SEND_BOOKING_LINK:
      return {
        booking_type_id: null,
        channels: ['email'],
        subject: '',
        message: ''
      };
    case NODE_TYPES.SEND_FORM:
    case NODE_TYPES.SEND_QUESTIONNAIRE:
      return {
        form_id: null,
        channels: ['email'],
        subject: '',
        message: ''
      };
    case NODE_TYPES.CREATE_SEND_PROPOSAL:
      return {
        title: '',
        catalog_item_id: null,
        quantity: 1,
        payment_schedule: 'full'
      };
    case NODE_TYPES.REQUEST_PAYMENT:
      return {
        source: 'invoice',
        title: '',
        catalog_item_id: null,
        quantity: 1
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

// Triggers with a real producer today (WorkflowTriggerType::isIngestableInThisSlice()),
// in the groups the picker shows them under. Documents, payments and questionnaires
// are the owning domain's own durable events, never a browser redirect.
var TRIGGER_GROUPS = ['CRM', 'Messaging', 'Forms', 'Calendar', 'Documents', 'Payments', 'Manual / time'];
var TRIGGER_TYPES = [{
  value: 'contact_created',
  group: 'CRM',
  title: 'Contact is created',
  description: 'Starts when a new contact is added.',
  icon: 'user-plus',
  defaultPolicy: 'once_ever'
},
// CRM sales opportunities. Each defaults to "every time it happens": one
// contact can have many deals, and a deal many moves.
{
  value: 'opportunity_created',
  group: 'CRM',
  title: 'Opportunity created',
  description: 'Starts when a new opportunity is added for a contact.',
  icon: 'briefcase-business',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'opportunity_stage_changed',
  group: 'CRM',
  title: 'Opportunity moves stage',
  description: 'Starts when an opportunity moves to another stage.',
  icon: 'arrow-right-left',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'opportunity_won',
  group: 'CRM',
  title: 'Opportunity marked won',
  description: 'Starts when an opportunity is marked won.',
  icon: 'trophy',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'opportunity_lost',
  group: 'CRM',
  title: 'Opportunity marked lost',
  description: 'Starts when an opportunity is marked lost.',
  icon: 'circle-x',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'contact_tag_added',
  group: 'CRM',
  title: 'Tag added to a contact',
  description: 'Starts when a tag is added to a contact.',
  icon: 'tag',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'contact_tag_removed',
  group: 'CRM',
  title: 'Tag removed from a contact',
  description: 'Starts when a tag is taken off a contact.',
  icon: 'tag',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'message_received',
  group: 'Messaging',
  title: 'Customer sends a text',
  description: 'Starts when a contact texts your business.',
  icon: 'message-square-reply',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'form_submitted',
  group: 'Forms',
  title: 'Form submitted',
  description: 'Starts when someone finishes and submits one of your forms.',
  icon: 'clipboard-list',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'questionnaire_submitted',
  group: 'Forms',
  title: 'Questionnaire submitted',
  description: 'Starts when someone finishes one of your multi-step questionnaires.',
  icon: 'list-checks',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'appointment_scheduled',
  group: 'Calendar',
  title: 'Appointment booked',
  description: 'Starts when an appointment is booked for a contact.',
  icon: 'calendar-check',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'appointment_rescheduled',
  group: 'Calendar',
  title: 'Appointment rescheduled',
  description: 'Starts when an appointment is moved to another time.',
  icon: 'calendar-clock',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'appointment_cancelled',
  group: 'Calendar',
  title: 'Appointment cancelled',
  description: 'Starts when an appointment is cancelled.',
  icon: 'calendar-x',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'document_sent',
  group: 'Documents',
  title: 'Proposal or document sent',
  description: 'Starts when a proposal, contract or invoice is sent to a contact.',
  icon: 'file-text',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'document_signed',
  group: 'Documents',
  title: 'Proposal or document signed',
  description: 'Starts when a contact signs a proposal or contract.',
  icon: 'file-signature',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'payment_succeeded',
  group: 'Payments',
  title: 'Payment succeeded',
  description: 'Starts when a contact pays an invoice, a deposit or a balance.',
  icon: 'circle-check',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'payment_failed',
  group: 'Payments',
  title: 'Payment failed',
  description: "Starts when a contact's payment attempt is declined.",
  icon: 'circle-alert',
  defaultPolicy: 'once_per_occurrence'
}, {
  value: 'manual_enrollment',
  group: 'Manual / time',
  title: 'Added by hand',
  description: 'Starts only when you add a contact to it yourself.',
  icon: 'hand',
  defaultPolicy: 'once_ever'
}, {
  value: 'contact_date_reached',
  group: 'Manual / time',
  title: 'Contact date arrives',
  description: 'Starts on a date saved on the contact, such as a birthday.',
  icon: 'calendar-clock',
  defaultPolicy: 'once_per_occurrence'
}];
function triggerTypeInfo(value) {
  return TRIGGER_TYPES.find(function (entry) {
    return entry.value === value;
  }) || null;
}
var CONTACT_SOURCE_LABELS = {
  any: 'From anywhere',
  opt_in_form: 'From an opt-in form',
  in_app: 'Added in the app'
};
var ENROLLMENT_POLICY_LABELS = {
  once_ever: 'Only once per contact',
  once_per_occurrence: 'Every time it happens'
};

/** '0 day' → 'On the date'; '1 week' → '1 week before'. */
function offsetLabel(offset) {
  if (!offset || offset === '0 day') {
    return 'On the date';
  }
  return "".concat(offset, " before");
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
/* harmony export */   insertBranchAt: () => (/* binding */ insertBranchAt),
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

/**
 * Insert an If / Else in the middle of a path without breaking it.
 *
 * An If / Else must be the last step of its path, so inserting one ahead of
 * existing steps moves those steps into its Yes lane: the journey still runs
 * them, now only when the condition is true, and the No lane starts empty. The
 * picker says so before the person chooses.
 */
function insertBranchAt(list, index, node) {
  node.yes = list.splice(index, list.length - index);
  node.no = node.no || [];
  list.push(node);
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

/***/ "./resources/js/automations/workflow-builder/dom.js"
/*!**********************************************************!*\
  !*** ./resources/js/automations/workflow-builder/dom.js ***!
  \**********************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   el: () => (/* binding */ el),
/* harmony export */   icon: () => (/* binding */ icon)
/* harmony export */ });
// Automations V2 (contract §13.1) — the two DOM helpers every builder module
// shares. Elements are built with textContent, never innerHTML, so nothing a
// person typed into a step can ever become markup.
//
// Icons come from the design system's single icon seam: the Blade view renders
// each Lucide icon the builder uses once, into a hidden <template
// id="wf-icon-{name}">, and this clones it. The builder never ships its own
// icon set and never adds a dependency to draw one.

function el(tag, className, text) {
  var node = document.createElement(tag);
  if (className) {
    node.className = className;
  }
  if (text !== undefined && text !== null) {
    node.textContent = text;
  }
  return node;
}
function icon(name, className) {
  var wrap = el('span', "wf-icon".concat(className ? " ".concat(className) : ''));
  wrap.setAttribute('aria-hidden', 'true');
  var template = document.getElementById("wf-icon-".concat(name));
  if (template && template.content) {
    wrap.appendChild(template.content.cloneNode(true));
  }
  return wrap;
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/drawer-actions.js"
/*!*********************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/drawer-actions.js ***!
  \*********************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   ACTION_TYPES: () => (/* binding */ ACTION_TYPES),
/* harmony export */   populateAction: () => (/* binding */ populateAction),
/* harmony export */   readAction: () => (/* binding */ readAction)
/* harmony export */ });
/* harmony import */ var _dom_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./dom.js */ "./resources/js/automations/workflow-builder/dom.js");
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
function _toConsumableArray(r) { return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray(r) || _nonIterableSpread(); }
function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _iterableToArray(r) { if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r); }
function _arrayWithoutHoles(r) { if (Array.isArray(r)) return _arrayLikeToArray(r); }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
// Automations — the inspector forms of the cross-domain actions: Move opportunity,
// Send booking link, Send form, Send questionnaire, Create & send proposal or
// contract, and Request payment.
//
// Same rules as the rest of the drawer: each form is a hidden `<template>` the Blade
// view rendered, and every option list comes ONLY from the `catalogs` this Business's
// page was handed — never another Business's row, and never an identifier shown to a
// person. A resource the step still names but that is archived or switched off stays
// visible, marked, so the validator's message makes sense; a way of sending the
// account cannot use is shown disabled with its reason.

function option(value, label) {
  var opt = (0,_dom_js__WEBPACK_IMPORTED_MODULE_0__.el)('option', null, label);
  opt.value = String(value);
  return opt;
}
function fill(select, placeholder, rows) {
  select.innerHTML = '';
  select.appendChild(option('', placeholder));
  rows.forEach(function (row) {
    return select.appendChild(row);
  });
}
function numberOrNull(value) {
  return value === '' || value === null || value === undefined ? null : Number(value);
}

/** Minor units → "250.00"; null → "". */
function formatMajor(minor) {
  return minor === null || minor === undefined || minor === '' ? '' : (Number(minor) / 100).toFixed(2);
}

/** "250" / "250.5" / "250.00" → 25000 minor units, or null when it is not an amount. */
function parseMajor(text) {
  var clean = String(text || '').trim().replace(/,/g, '');
  if (!/^\d+(\.\d{1,2})?$/.test(clean)) {
    return null;
  }
  var minor = Math.round(Number(clean) * 100);
  return minor > 0 ? minor : null;
}

// ---------------------------------------------------------------------------
// Move opportunity
// ---------------------------------------------------------------------------

function populateMoveOpportunity(formEl, node, _ref) {
  var catalogs = _ref.catalogs;
  var pipelineSelect = formEl.querySelector('[data-role="wf-move-pipeline-select"]');
  var stageSelect = formEl.querySelector('[data-role="wf-move-stage-select"]');
  var empty = formEl.querySelector('[data-role="wf-move-no-pipelines"]');
  var pipelines = catalogs.crmPipelines || [];
  var stages = catalogs.crmStages || [];
  var keep = function keep(row, selected) {
    return !row.archived || String(row.id) === String(selected !== null && selected !== void 0 ? selected : '');
  };
  var label = function label(row) {
    return row.archived ? "".concat(row.name, " (archived)") : row.name;
  };
  fill(pipelineSelect, 'Choose a pipeline', pipelines.filter(function (row) {
    return keep(row, node.config.pipeline_id);
  }).map(function (row) {
    return option(row.id, label(row));
  }));
  pipelineSelect.value = node.config.pipeline_id != null ? String(node.config.pipeline_id) : '';
  empty.hidden = pipelines.length > 0;
  function fillStages(selected) {
    var rows = stages.filter(function (row) {
      return String(row.pipeline_id) === pipelineSelect.value && keep(row, selected);
    });
    fill(stageSelect, pipelineSelect.value === '' ? 'Choose a pipeline first' : 'Choose a stage', rows.map(function (row) {
      return option(row.id, label(row));
    }));
    stageSelect.value = selected != null && rows.some(function (row) {
      return String(row.id) === String(selected);
    }) ? String(selected) : '';
  }
  fillStages(node.config.stage_id);
  pipelineSelect.addEventListener('change', function () {
    return fillStages(null);
  });
}
function readMoveOpportunity(formEl) {
  return {
    pipeline_id: numberOrNull(formEl.querySelector('[data-role="wf-move-pipeline-select"]').value),
    stage_id: numberOrNull(formEl.querySelector('[data-role="wf-move-stage-select"]').value)
  };
}

// ---------------------------------------------------------------------------
// The three "send a link" actions share one delivery block
// ---------------------------------------------------------------------------

function populateChannels(formEl, node, _ref2) {
  var capabilities = _ref2.capabilities;
  var channels = Array.isArray(node.config.channels) ? node.config.channels : [];
  formEl.querySelectorAll('input[data-role="wf-channel"]').forEach(function (input) {
    var capability = (capabilities || {})[input.value === 'sms' ? 'sms' : 'email'];
    var unavailable = capability && capability.available === false;
    var note = formEl.querySelector("[data-role=\"wf-channel-note-".concat(input.value, "\"]"));
    input.checked = channels.includes(input.value);

    // Already chosen stays visible (and ticked) so the validator's message makes
    // sense; a way the account cannot use cannot be newly chosen.
    if (unavailable && !input.checked) {
      input.disabled = true;
    }
    if (note) {
      note.hidden = !unavailable;
      note.textContent = unavailable ? capability.reason || '' : '';
    }
  });
  formEl.querySelector('[data-field="subject"]').value = node.config.subject || '';
  formEl.querySelector('[data-field="message"]').value = node.config.message || '';
}
function readChannels(formEl) {
  return {
    channels: _toConsumableArray(formEl.querySelectorAll('input[data-role="wf-channel"]:checked')).map(function (input) {
      return input.value;
    }),
    subject: formEl.querySelector('[data-field="subject"]').value,
    message: formEl.querySelector('[data-field="message"]').value
  };
}
function populateBookingLink(formEl, node, context) {
  var select = formEl.querySelector('[data-role="wf-booking-type-select"]');
  var empty = formEl.querySelector('[data-role="wf-no-booking-types"]');
  var types = context.catalogs.bookingTypes || [];
  var locations = context.catalogs.locations || [];
  var selected = node.config.booking_type_id != null ? String(node.config.booking_type_id) : '';
  select.innerHTML = '';
  select.appendChild(option('', 'Choose a booking type'));

  // Grouped by Location, so two locations' "Consultation" are told apart.
  var byLocation = new Map();
  types.filter(function (row) {
    return row.active || String(row.id) === selected;
  }).forEach(function (row) {
    var list = byLocation.get(String(row.location_id)) || [];
    list.push(row);
    byLocation.set(String(row.location_id), list);
  });
  byLocation.forEach(function (rows, locationId) {
    var location = locations.find(function (candidate) {
      return String(candidate.id) === locationId;
    });
    var group = (0,_dom_js__WEBPACK_IMPORTED_MODULE_0__.el)('optgroup');
    group.label = location ? location.name || 'Unnamed location' : 'Another location';
    rows.forEach(function (row) {
      return group.appendChild(option(row.id, row.active ? row.name : "".concat(row.name, " (switched off)")));
    });
    select.appendChild(group);
  });
  select.value = selected;
  empty.hidden = types.length > 0;
  populateChannels(formEl, node, context);
}
function readBookingLink(formEl) {
  return _objectSpread({
    booking_type_id: numberOrNull(formEl.querySelector('[data-role="wf-booking-type-select"]').value)
  }, readChannels(formEl));
}
function populateFormLink(formEl, node, context, questionnaire) {
  var select = formEl.querySelector('[data-role="wf-form-select"]');
  var empty = formEl.querySelector('[data-role="wf-no-forms"]');
  var selected = node.config.form_id != null ? String(node.config.form_id) : '';
  // One definition serves both: a form is one page, a questionnaire two or more.
  var rows = (context.catalogs.forms || []).filter(function (row) {
    return (row.pages || 1) >= 2 === questionnaire;
  });
  fill(select, questionnaire ? 'Choose a questionnaire' : 'Choose a form', rows.filter(function (row) {
    return row.lifecycle === 'active' || String(row.id) === selected;
  }).map(function (row) {
    return option(row.id, row.lifecycle === 'active' ? row.name : "".concat(row.name, " (not switched on)"));
  }));
  select.value = selected;
  empty.hidden = rows.length > 0;
  populateChannels(formEl, node, context);
}
function readFormLink(formEl) {
  return _objectSpread({
    form_id: numberOrNull(formEl.querySelector('[data-role="wf-form-select"]').value)
  }, readChannels(formEl));
}

// ---------------------------------------------------------------------------
// Create & send proposal or contract
// ---------------------------------------------------------------------------

function itemOptions(catalogs, selected) {
  return (catalogs.catalogItems || []).filter(function (row) {
    return row.active || String(row.id) === String(selected !== null && selected !== void 0 ? selected : '');
  }).map(function (row) {
    return option(row.id, row.active ? row.name : "".concat(row.name, " (archived)"));
  });
}
function populateProposal(formEl, node, _ref3) {
  var catalogs = _ref3.catalogs;
  var itemSelect = formEl.querySelector('[data-role="wf-catalog-item-select"]');
  var empty = formEl.querySelector('[data-role="wf-no-catalog-items"]');
  var scheduleInputs = formEl.querySelectorAll('input[name="wf-proposal-schedule"]');
  var depositWrap = formEl.querySelector('[data-role="wf-deposit-fields"]');
  var percent = formEl.querySelector('[data-field="deposit_percent"]');
  formEl.querySelector('[data-field="title"]').value = node.config.title || '';
  fill(itemSelect, 'Choose a product or package', itemOptions(catalogs, node.config.catalog_item_id));
  itemSelect.value = node.config.catalog_item_id != null ? String(node.config.catalog_item_id) : '';
  empty.hidden = (catalogs.catalogItems || []).length > 0;
  formEl.querySelector('[data-field="quantity"]').value = node.config.quantity != null ? node.config.quantity : 1;
  var schedule = node.config.payment_schedule === 'deposit' ? 'deposit' : 'full';
  scheduleInputs.forEach(function (input) {
    input.checked = input.value === schedule;
  });
  percent.value = node.config.deposit_percent != null ? node.config.deposit_percent : 30;
  function sync() {
    depositWrap.hidden = formEl.querySelector('input[name="wf-proposal-schedule"]:checked').value !== 'deposit';
  }
  scheduleInputs.forEach(function (input) {
    return input.addEventListener('change', sync);
  });
  sync();
}
function readProposal(formEl) {
  var schedule = formEl.querySelector('input[name="wf-proposal-schedule"]:checked').value;
  var config = {
    title: formEl.querySelector('[data-field="title"]').value,
    catalog_item_id: numberOrNull(formEl.querySelector('[data-role="wf-catalog-item-select"]').value),
    quantity: Number(formEl.querySelector('[data-field="quantity"]').value) || 1,
    payment_schedule: schedule
  };
  if (schedule === 'deposit') {
    config.deposit_percent = Number(formEl.querySelector('[data-field="deposit_percent"]').value);
  }
  return config;
}

// ---------------------------------------------------------------------------
// Request payment
// ---------------------------------------------------------------------------

function populateRequestPayment(formEl, node, _ref4) {
  var catalogs = _ref4.catalogs,
    currency = _ref4.currency,
    triggerType = _ref4.triggerType;
  var sourceInputs = formEl.querySelectorAll('input[name="wf-payment-source"]');
  var documentWrap = formEl.querySelector('[data-role="wf-payment-document-fields"]');
  var invoiceWrap = formEl.querySelector('[data-role="wf-payment-invoice-fields"]');
  var documentOption = formEl.querySelector('input[name="wf-payment-source"][value="document"]');
  var documentNote = formEl.querySelector('[data-role="wf-payment-document-note"]');
  var basisInputs = formEl.querySelectorAll('input[name="wf-payment-basis"]');
  var itemWrap = formEl.querySelector('[data-role="wf-payment-item-fields"]');
  var amountWrap = formEl.querySelector('[data-role="wf-payment-amount-fields"]');
  var itemSelect = formEl.querySelector('[data-role="wf-catalog-item-select"]');
  var empty = formEl.querySelector('[data-role="wf-no-catalog-items"]');

  // "This journey's document" only exists when the workflow starts from one.
  var documentTriggers = ['document_sent', 'document_signed', 'payment_succeeded', 'payment_failed'];
  var hasDocument = documentTriggers.includes(typeof triggerType === 'function' ? triggerType() : triggerType);
  if (!hasDocument) {
    documentOption.disabled = true;
    documentNote.hidden = false;
  }

  // A new step starts on the journey's own document when there is one: that is what
  // "request payment" almost always means after a proposal is sent or signed.
  var source = hasDocument && node.config.source !== 'invoice' ? 'document' : 'invoice';
  sourceInputs.forEach(function (input) {
    input.checked = input.value === source;
  });
  formEl.querySelector('[data-field="title"]').value = node.config.title || '';
  fill(itemSelect, 'Choose a product or package', itemOptions(catalogs, node.config.catalog_item_id));
  itemSelect.value = node.config.catalog_item_id != null ? String(node.config.catalog_item_id) : '';
  empty.hidden = (catalogs.catalogItems || []).length > 0;
  formEl.querySelector('[data-field="quantity"]').value = node.config.quantity != null ? node.config.quantity : 1;
  formEl.querySelector('[data-field="amount"]').value = formatMajor(node.config.amount_minor);
  formEl.querySelector('[data-role="wf-currency"]').textContent = currency || '';
  var basis = node.config.amount_minor != null && node.config.catalog_item_id == null ? 'amount' : 'item';
  basisInputs.forEach(function (input) {
    input.checked = input.value === basis;
  });
  function sync() {
    var isDocument = formEl.querySelector('input[name="wf-payment-source"]:checked').value === 'document';
    documentWrap.hidden = !isDocument;
    invoiceWrap.hidden = isDocument;
    var byAmount = formEl.querySelector('input[name="wf-payment-basis"]:checked').value === 'amount';
    itemWrap.hidden = byAmount;
    amountWrap.hidden = !byAmount;
  }
  sourceInputs.forEach(function (input) {
    return input.addEventListener('change', sync);
  });
  basisInputs.forEach(function (input) {
    return input.addEventListener('change', sync);
  });
  sync();
}
function readRequestPayment(formEl) {
  if (formEl.querySelector('input[name="wf-payment-source"]:checked').value === 'document') {
    return {
      source: 'document'
    };
  }
  var config = {
    source: 'invoice',
    title: formEl.querySelector('[data-field="title"]').value
  };
  if (formEl.querySelector('input[name="wf-payment-basis"]:checked').value === 'amount') {
    // An unreadable amount stays unset, so the validator says what is missing
    // rather than the browser guessing a number.
    config.amount_minor = parseMajor(formEl.querySelector('[data-field="amount"]').value);
  } else {
    config.catalog_item_id = numberOrNull(formEl.querySelector('[data-role="wf-catalog-item-select"]').value);
    config.quantity = Number(formEl.querySelector('[data-field="quantity"]').value) || 1;
  }
  return config;
}

// ---------------------------------------------------------------------------

var ACTION_TYPES = ['move_opportunity', 'send_booking_link', 'send_form', 'send_questionnaire', 'create_send_proposal', 'request_payment'];
function populateAction(type, formEl, node, context) {
  switch (type) {
    case 'move_opportunity':
      return populateMoveOpportunity(formEl, node, context);
    case 'send_booking_link':
      return populateBookingLink(formEl, node, context);
    case 'send_form':
      return populateFormLink(formEl, node, context, false);
    case 'send_questionnaire':
      return populateFormLink(formEl, node, context, true);
    case 'create_send_proposal':
      return populateProposal(formEl, node, context);
    case 'request_payment':
      return populateRequestPayment(formEl, node, context);
    default:
      return undefined;
  }
}
function readAction(type, formEl) {
  switch (type) {
    case 'move_opportunity':
      return readMoveOpportunity(formEl);
    case 'send_booking_link':
      return readBookingLink(formEl);
    case 'send_form':
    case 'send_questionnaire':
      return readFormLink(formEl);
    case 'create_send_proposal':
      return readProposal(formEl);
    case 'request_payment':
      return readRequestPayment(formEl);
    default:
      return {};
  }
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
/* harmony import */ var _constants_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./constants.js */ "./resources/js/automations/workflow-builder/constants.js");
/* harmony import */ var _conditions_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./conditions.js */ "./resources/js/automations/workflow-builder/conditions.js");
/* harmony import */ var _dom_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./dom.js */ "./resources/js/automations/workflow-builder/dom.js");
/* harmony import */ var _drawer_actions_js__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./drawer-actions.js */ "./resources/js/automations/workflow-builder/drawer-actions.js");
function _slicedToArray(r, e) { return _arrayWithHoles(r) || _iterableToArrayLimit(r, e) || _unsupportedIterableToArray(r, e) || _nonIterableRest(); }
function _nonIterableRest() { throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _iterableToArrayLimit(r, l) { var t = null == r ? null : "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (null != t) { var e, n, i, u, a = [], f = !0, o = !1; try { if (i = (t = t.call(r)).next, 0 === l) { if (Object(t) !== t) return; f = !1; } else for (; !(f = (e = i.call(t)).done) && (a.push(e.value), a.length !== l); f = !0); } catch (r) { o = !0, n = r; } finally { try { if (!f && null != t["return"] && (u = t["return"](), Object(u) !== u)) return; } finally { if (o) throw n; } } return a; } }
function _arrayWithHoles(r) { if (Array.isArray(r)) return r; }
function _toConsumableArray(r) { return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray(r) || _nonIterableSpread(); }
function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _iterableToArray(r) { if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r); }
function _arrayWithoutHoles(r) { if (Array.isArray(r)) return _arrayLikeToArray(r); }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
// Automations V2 (contract §13.1, §14.2, V2-D) — the step inspector.
//
// A panel docked to the right of the canvas, so the workflow stays visible and
// the selected card stays highlighted while it is being configured. Its body is
// populated per node type from the hidden `<template>` partials the Blade view
// rendered server-side (contract §13.1: "one server-rendered form partial per
// node type"). This module never invents a field NodeTypeRegistry does not
// validate, and every option list it offers (contact groups, writable fields,
// date fields) comes only from the `catalogs` this Business's page was handed —
// never another Business's row.




function fillSelect(select, options, valueKey, labelKey, placeholder) {
  select.innerHTML = '';
  if (placeholder) {
    var opt = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, placeholder);
    opt.value = '';
    select.appendChild(opt);
  }
  options.forEach(function (row) {
    var opt = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, row[labelKey]);
    opt.value = String(row[valueKey]);
    select.appendChild(opt);
  });
}

/** Fields grouped under their contact group's name, so two "Notes" fields are told apart. */
function fillFieldSelect(select, fields, groups, placeholder) {
  select.innerHTML = '';
  var empty = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, placeholder);
  empty.value = '';
  select.appendChild(empty);
  groups.forEach(function (group) {
    var rows = fields.filter(function (field) {
      return String(field.contact_group_id) === String(group.id);
    });
    if (rows.length === 0) {
      return;
    }
    var optgroup = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('optgroup');
    optgroup.label = group.name;
    rows.forEach(function (field) {
      var opt = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, field.label);
      opt.value = String(field.id);
      optgroup.appendChild(opt);
    });
    select.appendChild(optgroup);
  });
}
function attachCounter(field, counterEl, max) {
  if (!field || !counterEl) {
    return;
  }
  var sync = function sync() {
    var length = field.value.length;
    counterEl.textContent = "".concat(length, " / ").concat(max);
    counterEl.classList.toggle('is-over', length > max);
  };
  field.addEventListener('input', sync);
  sync();
}
function createDrawer(_ref) {
  var drawerEl = _ref.drawerEl,
    catalogs = _ref.catalogs,
    dateOffsets = _ref.dateOffsets,
    limits = _ref.limits,
    locationScope = _ref.locationScope,
    capabilities = _ref.capabilities,
    currency = _ref.currency,
    triggerType = _ref.triggerType,
    onSave = _ref.onSave,
    onDelete = _ref.onDelete,
    onClose = _ref.onClose;
  var data = {
    locationScope: locationScope,
    capabilities: capabilities || {},
    currency: currency || '',
    triggerType: triggerType
  };
  var iconEl = drawerEl.querySelector('[data-role="wf-drawer-icon"]');
  var eyebrowEl = drawerEl.querySelector('[data-role="wf-drawer-eyebrow"]');
  var titleEl = drawerEl.querySelector('[data-role="wf-drawer-title"]');
  var descriptionEl = drawerEl.querySelector('[data-role="wf-drawer-description"]');
  var formEl = drawerEl.querySelector('[data-role="wf-drawer-form"]');
  var errorsEl = drawerEl.querySelector('[data-role="wf-drawer-errors"]');
  var saveButton = drawerEl.querySelector('[data-role="wf-drawer-save"]');
  var cancelButton = drawerEl.querySelector('[data-role="wf-drawer-cancel"]');
  var closeButton = drawerEl.querySelector('[data-role="wf-drawer-close"]');
  var deleteButton = drawerEl.querySelector('[data-role="wf-drawer-delete"]');
  var currentNode = null;
  var readOnly = false;
  function open(node, errorMessages) {
    var options = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : {};
    currentNode = node;
    readOnly = Boolean(options.readOnly);
    var catalogEntry = _constants_js__WEBPACK_IMPORTED_MODULE_0__.STEP_CATALOG.find(function (entry) {
      return entry.type === node.type;
    });
    iconEl.innerHTML = '';
    iconEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.icon)(_constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_ICONS[node.type] || 'zap', "wf-tone wf-tone--".concat(node.type)));
    eyebrowEl.textContent = node.type === 'trigger' ? 'Trigger' : 'Step';
    titleEl.textContent = node.type === 'trigger' ? 'What starts this workflow' : _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_LABELS[node.type];
    descriptionEl.textContent = node.type === 'trigger' ? 'Choose the moment a contact enters this workflow.' : catalogEntry && catalogEntry.description || '';
    formEl.innerHTML = '';
    var template = document.getElementById("wf-node-form-".concat(node.type));
    if (template) {
      formEl.appendChild(template.content.cloneNode(true));
    }
    populate(node);
    showErrors(errorMessages || []);
    deleteButton.hidden = node.type === 'trigger' || readOnly;
    saveButton.hidden = readOnly;
    cancelButton.textContent = readOnly ? 'Close' : 'Cancel';
    formEl.querySelectorAll('input, select, textarea, button').forEach(function (control) {
      control.disabled = control.disabled || readOnly;
    });
    drawerEl.hidden = false;

    // A choice list takes focus on its CHOSEN option, so the focus ring never
    // sits on a card that is not the one selected.
    var first = formEl.querySelector('input[type="radio"]:checked:not([disabled]), input:not([type="hidden"]):not([type="radio"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
    if (first && !options.keepFocus) {
      first.focus({
        preventScroll: true
      });
    }
  }
  function close() {
    if (drawerEl.hidden) {
      return;
    }
    drawerEl.hidden = true;
    var node = currentNode;
    currentNode = null;
    if (onClose) {
      onClose(node);
    }
  }
  function isOpen() {
    return !drawerEl.hidden;
  }
  function showErrors(messages) {
    errorsEl.innerHTML = '';
    if (!messages || messages.length === 0) {
      errorsEl.classList.add('d-none');
      return;
    }
    errorsEl.classList.remove('d-none');
    messages.forEach(function (message) {
      return errorsEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('p', 'mb-0', message));
    });
  }
  function populate(node) {
    if (node.type === 'trigger') {
      populateTrigger(node);
    } else if (node.type === 'if_else') {
      populateIfElse(node);
    } else if (node.type === 'update_contact_field') {
      populateUpdateContactField(node);
    } else if (node.type === 'add_tag' || node.type === 'remove_tag') {
      populateTagAction(node);
    } else if (node.type === 'wait') {
      populateWait(node);
    } else if (_drawer_actions_js__WEBPACK_IMPORTED_MODULE_3__.ACTION_TYPES.includes(node.type)) {
      (0,_drawer_actions_js__WEBPACK_IMPORTED_MODULE_3__.populateAction)(node.type, formEl, node, {
        catalogs: catalogs,
        capabilities: data.capabilities,
        currency: data.currency,
        triggerType: data.triggerType
      });
    } else {
      populateGeneric(node);
    }
  }
  function populateGeneric(node) {
    formEl.querySelectorAll('[data-field]').forEach(function (field) {
      var value = node.config[field.dataset.field];
      if (value !== undefined && value !== null) {
        field.value = value;
      }
    });
    attachCounter(formEl.querySelector('[data-field="body"]'), formEl.querySelector('[data-role="wf-body-counter"]'), 1600);
    attachCounter(formEl.querySelector('[data-field="message"]'), formEl.querySelector('[data-role="wf-message-counter"]'), 255);

    // Merge tags insert at the cursor rather than being typed from memory.
    formEl.querySelectorAll('[data-insert]').forEach(function (chip) {
      chip.addEventListener('click', function () {
        var _target$selectionStar, _target$selectionEnd;
        var target = formEl.querySelector('[data-field="body"], [data-field="message"]');
        if (!target) {
          return;
        }
        var start = (_target$selectionStar = target.selectionStart) !== null && _target$selectionStar !== void 0 ? _target$selectionStar : target.value.length;
        var end = (_target$selectionEnd = target.selectionEnd) !== null && _target$selectionEnd !== void 0 ? _target$selectionEnd : target.value.length;
        target.value = target.value.slice(0, start) + chip.dataset.insert + target.value.slice(end);
        target.focus();
        target.selectionStart = target.selectionEnd = start + chip.dataset.insert.length;
        target.dispatchEvent(new Event('input'));
      });
    });
  }
  function populateWait(node) {
    var modeInputs = formEl.querySelectorAll('input[name="wf-wait-mode"]');
    var durationFields = formEl.querySelector('[data-role="wf-wait-duration-fields"]');
    var datetimeFields = formEl.querySelector('[data-role="wf-wait-datetime-fields"]');
    var mode = node.config.mode === 'until_datetime' ? 'until_datetime' : 'duration';
    modeInputs.forEach(function (input) {
      input.checked = input.value === mode;
    });
    formEl.querySelector('[data-field="amount"]').value = node.config.amount != null ? node.config.amount : 1;
    formEl.querySelector('[data-field="unit"]').value = node.config.unit || 'days';
    // The server accepts "YYYY-MM-DD HH:MM" or the T form a datetime-local input writes.
    formEl.querySelector('[data-field="at"]').value = node.config.at ? String(node.config.at).replace(' ', 'T').slice(0, 16) : '';
    function sync() {
      var isDuration = formEl.querySelector('input[name="wf-wait-mode"]:checked').value === 'duration';
      durationFields.hidden = !isDuration;
      datetimeFields.hidden = isDuration;
    }
    modeInputs.forEach(function (input) {
      return input.addEventListener('change', sync);
    });
    sync();
  }
  function populateUpdateContactField(node) {
    var select = formEl.querySelector('[data-role="wf-writable-field-select"]');
    fillFieldSelect(select, catalogs.writableFields, catalogs.contactGroups, 'Choose a field');
    select.value = node.config.field_id != null ? String(node.config.field_id) : '';
    formEl.querySelector('[data-field="value"]').value = node.config.value != null ? node.config.value : '';
  }
  function populateTrigger(node) {
    var typeInputs = formEl.querySelectorAll('input[name="wf-trigger-type"]');
    var sections = formEl.querySelectorAll('[data-trigger-section]');
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
        label: (0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.offsetLabel)(value)
      };
    }), 'value', 'label', null);
    var selectedType = function selectedType() {
      var checked = formEl.querySelector('input[name="wf-trigger-type"]:checked');
      return checked ? checked.value : 'contact_created';
    };
    function refreshDateFieldOptions() {
      var rows = catalogs.dateFields.filter(function (f) {
        return String(f.contact_group_id) === String(dateGroupSelect.value);
      });
      fillSelect(dateFieldSelect, rows, 'id', 'label', rows.length ? 'Choose a date field' : 'This group has no date fields');
    }
    function syncVisibility() {
      var type = selectedType();
      sections.forEach(function (section) {
        // A section may serve several triggers (both tag triggers share one filter).
        section.hidden = !section.dataset.triggerSection.split(' ').includes(type);
      });
      formEl.querySelectorAll('[data-trigger-note]').forEach(function (note) {
        note.hidden = note.dataset.triggerNote !== type;
      });
      formEl.querySelectorAll('.wf-choice').forEach(function (choice) {
        choice.classList.toggle('is-checked', choice.querySelector('input').checked);
      });
    }
    function defaultPolicyFor(triggerType) {
      var info = (0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.triggerTypeInfo)(triggerType);
      return info ? info.defaultPolicy : 'once_ever';
    }
    typeInputs.forEach(function (input) {
      input.addEventListener('change', function () {
        syncVisibility();
        refreshDateFieldOptions();

        // Mirrors WorkflowDraftService::withTriggerChanged(): a policy
        // still carrying its trigger's DEFAULT origin follows the new
        // trigger silently; a customer's own explicit choice is left
        // alone, with a note rather than a silent override (§7.5).
        if (policySourceInput.value === 'default') {
          policySelect.value = defaultPolicyFor(selectedType());
          confirmNote.hidden = true;
        } else {
          confirmNote.hidden = policySelect.value === defaultPolicyFor(selectedType());
        }
      });
    });
    dateGroupSelect.addEventListener('change', refreshDateFieldOptions);
    policySelect.addEventListener('change', function () {
      // Any manual change is a deliberate customer choice from this
      // moment on.
      policySourceInput.value = 'user';
      confirmNote.hidden = true;
    });
    var type = _constants_js__WEBPACK_IMPORTED_MODULE_0__.TRIGGER_TYPES.some(function (entry) {
      return entry.value === node.config.trigger_type;
    }) ? node.config.trigger_type : 'contact_created';
    typeInputs.forEach(function (input) {
      input.checked = input.value === type;
    });
    sourceSelect.value = node.config.source || 'any';
    groupSelect.value = node.config.contact_group_id != null ? String(node.config.contact_group_id) : '';
    dateGroupSelect.value = node.config.contact_group_id != null ? String(node.config.contact_group_id) : '';
    refreshDateFieldOptions();
    dateFieldSelect.value = node.config.date_field_id != null ? String(node.config.date_field_id) : '';
    offsetSelect.value = node.config.offset || '0 day';
    formEl.querySelector('[data-field="send_at"]').value = node.config.send_at || '09:00';
    policySelect.value = node.config.enrollment_policy || defaultPolicyFor(type);
    policySourceInput.value = node.config.enrollment_policy_source || 'default';
    confirmNote.hidden = true;
    populateStageFilters(node.config);
    populateTagAndFormFilters(node.config);
    populateLocationScope(node.config);
    syncVisibility();
  }

  /**
   * "Where it applies": the whole business, ONE of THIS Business's Locations, or a
   * chosen list of them. A run is never multi-location — each is pinned to the one
   * Location of the fact that started it — this only decides which facts are
   * admitted. Options are the Locations THIS actor reaches (already ACL-filtered by
   * the server); an archived Location stays visible, marked, only where the workflow
   * already names it, so the validator's message makes sense.
   */
  function populateLocationScope(config) {
    var modeInputs = formEl.querySelectorAll('input[name="wf-scope-mode"]');
    var select = formEl.querySelector('[data-role="wf-location-scope-select"]');
    var list = formEl.querySelector('[data-role="wf-location-scope-list"]');
    var oneWrap = formEl.querySelector('[data-role="wf-scope-one-fields"]');
    var selectedWrap = formEl.querySelector('[data-role="wf-scope-selected-fields"]');
    var businessInput = formEl.querySelector('input[name="wf-scope-mode"][value="business"]');
    var businessNote = formEl.querySelector('[data-role="wf-scope-business-note"]');
    var noLocations = formEl.querySelector('[data-role="wf-scope-no-locations"]');
    var chosenOne = config.business_location_id != null ? String(config.business_location_id) : '';
    var chosenMany = Array.isArray(config.business_location_ids) ? config.business_location_ids.map(String) : [];
    var rows = (catalogs.locations || []).filter(function (row) {
      return row.active || String(row.id) === chosenOne || chosenMany.includes(String(row.id));
    }).map(function (row) {
      return {
        id: row.id,
        label: "".concat(row.name || 'Unnamed location').concat(row.active ? '' : ' (archived)')
      };
    });

    // The mode: an explicit one, else what the pre-mode document means.
    var mode = config.scope_mode;
    if (!['business', 'one', 'selected'].includes(mode)) {
      mode = chosenOne !== '' ? 'one' : 'business';
    }

    // Only an actor who reaches every Location may choose "Whole business": it runs
    // for all of them. Everyone else chooses their own, and the server refuses the
    // rest at save and publish.
    var businessWide = !data || !data.locationScope || data.locationScope.businessWide !== false;
    if (!businessWide) {
      businessInput.disabled = true;
      businessNote.hidden = false;
      if (mode === 'business') {
        mode = 'one';
      }
    }
    fillSelect(select, rows, 'id', 'label', 'Choose a location');
    select.value = chosenOne;
    list.innerHTML = '';
    rows.forEach(function (row) {
      var label = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('label', 'form-check');
      var input = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('input', 'form-check-input');
      input.type = 'checkbox';
      input.value = String(row.id);
      input.dataset.role = 'wf-scope-location';
      input.checked = chosenMany.includes(String(row.id));
      label.appendChild(input);
      label.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('span', 'form-check-label ms-1', row.label));
      list.appendChild(label);
    });
    noLocations.hidden = rows.length > 0;
    modeInputs.forEach(function (input) {
      input.checked = input.value === mode;
    });
    function sync() {
      var current = formEl.querySelector('input[name="wf-scope-mode"]:checked');
      var value = current ? current.value : 'business';
      oneWrap.hidden = value !== 'one';
      selectedWrap.hidden = value !== 'selected';
      formEl.querySelectorAll('[data-role="wf-scope-choices"] .wf-choice').forEach(function (choice) {
        choice.classList.toggle('is-checked', choice.querySelector('input').checked);
      });
    }
    modeInputs.forEach(function (input) {
      return input.addEventListener('change', sync);
    });
    sync();
  }
  /**
   * Tag triggers may narrow to one tag, and "Form submitted" to one form — each
   * optional ("any"). Options are this Business's own catalog only; an archived
   * tag stays visible, marked, only where the trigger already names it.
   */
  function populateTagAndFormFilters(config) {
    var tagSelect = formEl.querySelector('[data-role="wf-tag-filter-select"]');
    var formSelect = formEl.querySelector('[data-role="wf-form-filter-select"]');
    var noTags = formEl.querySelector('[data-role="wf-no-tags"]');
    var noForms = formEl.querySelector('[data-role="wf-no-forms"]');
    var tags = catalogs.tags || [];
    var forms = catalogs.forms || [];
    var selectedTag = config.tag_id != null ? String(config.tag_id) : '';
    var selectedForm = config.form_id != null ? String(config.form_id) : '';
    fillSelect(tagSelect, tags.filter(function (row) {
      return !row.archived || String(row.id) === selectedTag;
    }).map(function (row) {
      return {
        id: row.id,
        label: row.archived ? "".concat(row.name, " (archived)") : row.name
      };
    }), 'id', 'label', 'Any tag');
    tagSelect.value = selectedTag;
    noTags.hidden = tags.length > 0;

    // "Form submitted" may narrow to any one form; "Questionnaire submitted" only
    // to a questionnaire (two or more pages). The list follows the chosen trigger.
    function fillFormFilter() {
      var checked = formEl.querySelector('input[name="wf-trigger-type"]:checked');
      var questionnaires = checked && checked.value === 'questionnaire_submitted';
      var rows = questionnaires ? forms.filter(function (row) {
        return (row.pages || 1) >= 2;
      }) : forms;
      var previous = formSelect.value || selectedForm;
      fillSelect(formSelect, rows.map(function (row) {
        return {
          id: row.id,
          label: row.name
        };
      }), 'id', 'label', questionnaires ? 'Any questionnaire' : 'Any form');
      formSelect.value = _toConsumableArray(formSelect.options).some(function (opt) {
        return opt.value === previous;
      }) ? previous : '';
      noForms.hidden = rows.length > 0;
    }
    fillFormFilter();
    formEl.querySelectorAll('input[name="wf-trigger-type"]').forEach(function (input) {
      return input.addEventListener('change', fillFormFilter);
    });

    // Document and payment triggers may narrow to one kind of document.
    var kindSelect = formEl.querySelector('[data-role="wf-document-kind-select"]');
    if (kindSelect) {
      kindSelect.value = config.document_kind || '';
    }
  }

  /** "Add tag" / "Remove tag": one Business tag. Archived ones are hidden unless already chosen. */
  function populateTagAction(node) {
    var select = formEl.querySelector('[data-role="wf-tag-select"]');
    var noTags = formEl.querySelector('[data-role="wf-no-tags"]');
    var tags = catalogs.tags || [];
    var selected = node.config.tag_id != null ? String(node.config.tag_id) : '';

    // Add refuses an archived tag; Remove still accepts one. Keep either visible,
    // marked, only where the step already names it.
    var rows = tags.filter(function (row) {
      return !row.archived || String(row.id) === selected || node.type === 'remove_tag';
    }).map(function (row) {
      return {
        id: row.id,
        label: row.archived ? "".concat(row.name, " (archived)") : row.name
      };
    });
    fillSelect(select, rows, 'id', 'label', 'Choose a tag');
    select.value = selected;
    noTags.hidden = tags.length > 0;
  }

  /**
   * "Opportunity moves stage": pipeline, stage it leaves, stage it enters —
   * each optional. Options are this Business's CRM catalog only. Archived
   * pipelines and stages are hidden, except one the trigger already names,
   * which stays visible and marked so the validator's message makes sense.
   */
  function populateStageFilters(config) {
    var pipelineSelect = formEl.querySelector('[data-role="wf-crm-pipeline-select"]');
    var fromSelect = formEl.querySelector('[data-role="wf-crm-from-stage-select"]');
    var toSelect = formEl.querySelector('[data-role="wf-crm-to-stage-select"]');
    var noPipelines = formEl.querySelector('[data-role="wf-crm-no-pipelines"]');
    var pipelines = catalogs.crmPipelines || [];
    var stages = catalogs.crmStages || [];
    var kept = function kept(row, selected) {
      return !row.archived || String(row.id) === String(selected !== null && selected !== void 0 ? selected : '');
    };
    var labelled = function labelled(row) {
      return row.archived ? "".concat(row.name, " (archived)") : row.name;
    };
    pipelineSelect.innerHTML = '';
    var anyPipeline = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, 'Any pipeline');
    anyPipeline.value = '';
    pipelineSelect.appendChild(anyPipeline);
    pipelines.filter(function (row) {
      return kept(row, config.pipeline_id);
    }).forEach(function (row) {
      var opt = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, labelled(row));
      opt.value = String(row.id);
      pipelineSelect.appendChild(opt);
    });
    pipelineSelect.value = config.pipeline_id != null ? String(config.pipeline_id) : '';
    noPipelines.hidden = pipelines.length > 0;
    function fillStages(select, selected) {
      select.innerHTML = '';
      var any = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, 'Any stage');
      any.value = '';
      select.appendChild(any);
      pipelines.filter(function (pipeline) {
        return pipelineSelect.value === '' || String(pipeline.id) === pipelineSelect.value;
      }).forEach(function (pipeline) {
        var rows = stages.filter(function (row) {
          return String(row.pipeline_id) === String(pipeline.id) && kept(row, selected);
        });
        if (rows.length === 0) {
          return;
        }

        // Grouped by pipeline, so two pipelines' "Booked" are told apart.
        var group = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('optgroup');
        group.label = pipeline.name;
        rows.forEach(function (row) {
          var opt = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, labelled(row));
          opt.value = String(row.id);
          group.appendChild(opt);
        });
        select.appendChild(group);
      });
      select.value = selected != null && _toConsumableArray(select.options).some(function (opt) {
        return opt.value === String(selected);
      }) ? String(selected) : '';
    }
    fillStages(fromSelect, config.from_stage_id);
    fillStages(toSelect, config.to_stage_id);
    pipelineSelect.addEventListener('change', function () {
      fillStages(fromSelect, fromSelect.value || null);
      fillStages(toSelect, toSelect.value || null);
    });
  }
  function populateIfElse(node) {
    var matchSelect = formEl.querySelector('[data-field="match"]');
    matchSelect.value = node.config.match || 'all';
    var list = formEl.querySelector('[data-role="wf-conditions-list"]');
    var addButton = formEl.querySelector('[data-role="wf-add-condition"]');
    var limitNote = formEl.querySelector('[data-role="wf-condition-limit"]');
    var rowTemplate = document.getElementById('wf-if-else-condition-row');
    var maxConditions = limits && limits.maxConditionsPerBranch || 5;
    function syncLimit() {
      var count = list.querySelectorAll('[data-role="wf-condition-row"]').length;
      addButton.disabled = readOnly || count >= maxConditions;
      limitNote.hidden = count < maxConditions;
      list.querySelectorAll('[data-role="wf-condition-join"]').forEach(function (join, index) {
        join.textContent = matchSelect.value === 'any' ? 'or' : 'and';
        join.hidden = index === 0;
      });
    }
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
      var help = row.querySelector('[data-role="wf-condition-help"]');
      catalogs.writableFields.forEach(function (field) {
        var opt = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, field.label);
        opt.value = "contact.custom_field:".concat(field.id);
        customGroup.appendChild(opt);
      });
      if (customGroup.children.length === 0) {
        customGroup.remove();
      }

      // "Has tag" / "does not have tag": one subject per tag of this Business.
      var tagGroup = row.querySelector('[data-role="wf-condition-tag-group"]');
      (catalogs.tags || []).forEach(function (tag) {
        var opt = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, tag.archived ? "".concat(tag.name, " (archived)") : tag.name);
        opt.value = "contact.has_tag:".concat(tag.id);
        tagGroup.appendChild(opt);
      });
      if (tagGroup.children.length === 0) {
        tagGroup.remove();
      }

      // The deal, document, payment and appointment behind the journey — offered
      // only where the workflow's trigger gives them something to read (a stored
      // one stays visible so the validator's message makes sense).
      var factGroup = row.querySelector('[data-role="wf-condition-fact-group"]');
      var currentTrigger = typeof data.triggerType === 'function' ? data.triggerType() : data.triggerType;
      _toConsumableArray(factGroup.querySelectorAll('option')).forEach(function (opt) {
        if (!(0,_conditions_js__WEBPACK_IMPORTED_MODULE_1__.factSubjectAvailable)(opt.value, currentTrigger) && condition.subject !== opt.value) {
          opt.remove();
        }
      });
      if (factGroup.children.length === 0) {
        factGroup.remove();
      }

      /** The choices an operand is picked from, for the subjects whose operand is a choice. */
      function operandChoices(subject) {
        if (_conditions_js__WEBPACK_IMPORTED_MODULE_1__.GROUP_SUBJECTS.includes(subject)) {
          return (catalogs.contactGroups || []).map(function (group) {
            return {
              value: group.id,
              label: group.name
            };
          });
        }
        var meta = _conditions_js__WEBPACK_IMPORTED_MODULE_1__.FACT_SUBJECTS[subject];
        if (!meta) {
          return null;
        }
        if (meta.kind === 'reference') {
          // A stage, named with its pipeline so two pipelines' "Booked" differ.
          var pipelines = catalogs.crmPipelines || [];
          return (catalogs.crmStages || []).filter(function (stage) {
            return !stage.archived;
          }).map(function (stage) {
            var pipeline = pipelines.find(function (candidate) {
              return String(candidate.id) === String(stage.pipeline_id);
            });
            return {
              value: stage.id,
              label: pipeline ? "".concat(pipeline.name, " \u2014 ").concat(stage.name) : stage.name
            };
          });
        }
        return meta.kind === 'choice' ? Object.entries(meta.values).map(function (_ref2) {
          var _ref3 = _slicedToArray(_ref2, 2),
            value = _ref3[0],
            label = _ref3[1];
          return {
            value: value,
            label: label
          };
        }) : null;
      }
      function syncOperators() {
        var subject = subjectSelect.value;
        var previous = operatorSelect.value;
        operatorSelect.innerHTML = '';
        (0,_conditions_js__WEBPACK_IMPORTED_MODULE_1__.subjectOperators)(subject, catalogs).forEach(function (op) {
          var opt = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, (0,_conditions_js__WEBPACK_IMPORTED_MODULE_1__.operatorLabel)(subject, op));
          opt.value = op;
          operatorSelect.appendChild(opt);
        });
        if (_toConsumableArray(operatorSelect.options).some(function (opt) {
          return opt.value === previous;
        })) {
          operatorSelect.value = previous;
        }
        help.hidden = subject !== _conditions_js__WEBPACK_IMPORTED_MODULE_1__.REPLIED_SUBJECT;
      }
      function syncOperand() {
        var subject = subjectSelect.value;
        var takesValue = (0,_conditions_js__WEBPACK_IMPORTED_MODULE_1__.needsOperand)(operatorSelect.value);
        var choices = operandChoices(subject);
        var isChoice = choices !== null;
        if (isChoice) {
          var previous = operandGroupSelect.value;
          fillSelect(operandGroupSelect, choices, 'value', 'label', null);
          if (_toConsumableArray(operandGroupSelect.options).some(function (opt) {
            return opt.value === previous;
          })) {
            operandGroupSelect.value = previous;
          }
        }
        operandWrap.hidden = !takesValue || isChoice;
        operandGroupWrap.hidden = !takesValue || !isChoice;
        operandInput.type = (0,_conditions_js__WEBPACK_IMPORTED_MODULE_1__.isDateSubject)(subject, catalogs) ? 'date' : 'text';
      }
      subjectSelect.addEventListener('change', function () {
        syncOperators();
        syncOperand();
      });
      operatorSelect.addEventListener('change', syncOperand);
      row.querySelector('[data-role="wf-remove-condition"]').addEventListener('click', function () {
        row.remove();
        syncLimit();
      });
      subjectSelect.value = condition.subject || _conditions_js__WEBPACK_IMPORTED_MODULE_1__.REPLIED_SUBJECT;
      if (subjectSelect.value === '') {
        // A stored subject this Business no longer offers (a deleted
        // field): keep it visible and honest instead of silently
        // swapping in another subject.
        var orphan = (0,_dom_js__WEBPACK_IMPORTED_MODULE_2__.el)('option', null, String(condition.subject).startsWith('contact.has_tag:') ? 'A tag that no longer exists' : 'A field that no longer exists');
        orphan.value = condition.subject;
        subjectSelect.appendChild(orphan);
        subjectSelect.value = condition.subject;
      }
      syncOperators();
      operatorSelect.value = condition.operator && _toConsumableArray(operatorSelect.options).some(function (o) {
        return o.value === condition.operator;
      }) ? condition.operator : operatorSelect.options[0].value;
      syncOperand();
      if (operandChoices(subjectSelect.value) !== null) {
        operandGroupSelect.value = condition.operand != null ? String(condition.operand) : '';
      } else {
        operandInput.value = condition.operand != null ? condition.operand : '';
      }
      list.appendChild(row);
      syncLimit();
    }
    list.innerHTML = '';
    var conditions = node.config.conditions || [];
    if (conditions.length === 0 && !readOnly) {
      addRow({});
    } else {
      conditions.forEach(addRow);
    }
    matchSelect.addEventListener('change', syncLimit);
    addButton.addEventListener('click', function () {
      return addRow({});
    });
    syncLimit();
  }
  function readGeneric() {
    var config = {};
    formEl.querySelectorAll('[data-field]').forEach(function (field) {
      config[field.dataset.field] = field.value;
    });
    return config;
  }
  function readWait() {
    var mode = formEl.querySelector('input[name="wf-wait-mode"]:checked').value;
    if (mode === 'until_datetime') {
      return {
        mode: mode,
        at: formEl.querySelector('[data-field="at"]').value.replace('T', ' ')
      };
    }
    return {
      mode: mode,
      amount: Number(formEl.querySelector('[data-field="amount"]').value),
      unit: formEl.querySelector('[data-field="unit"]').value
    };
  }
  function readTrigger() {
    var checked = formEl.querySelector('input[name="wf-trigger-type"]:checked');
    var triggerType = checked ? checked.value : 'contact_created';
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
    } else if (triggerType === 'contact_created') {
      config.source = formEl.querySelector('select[data-field="source"]').value;
      var _groupValue = formEl.querySelector('[data-role="wf-contact-group-select"]').value;
      config.contact_group_id = _groupValue ? Number(_groupValue) : null;
    } else if (triggerType === 'contact_tag_added' || triggerType === 'contact_tag_removed') {
      var tagValue = formEl.querySelector('[data-role="wf-tag-filter-select"]').value;
      config.tag_id = tagValue ? Number(tagValue) : null;
    } else if (triggerType === 'form_submitted' || triggerType === 'questionnaire_submitted') {
      var formValue = formEl.querySelector('[data-role="wf-form-filter-select"]').value;
      config.form_id = formValue ? Number(formValue) : null;
    } else if (['document_sent', 'document_signed', 'payment_succeeded', 'payment_failed'].includes(triggerType)) {
      var kind = formEl.querySelector('[data-role="wf-document-kind-select"]').value;
      config.document_kind = kind || null;
    } else if (triggerType === 'opportunity_stage_changed') {
      ;
      [['pipeline_id', 'wf-crm-pipeline-select'], ['from_stage_id', 'wf-crm-from-stage-select'], ['to_stage_id', 'wf-crm-to-stage-select']].forEach(function (_ref4) {
        var _ref5 = _slicedToArray(_ref4, 2),
          key = _ref5[0],
          role = _ref5[1];
        var value = formEl.querySelector("[data-role=\"".concat(role, "\"]")).value;
        config[key] = value ? Number(value) : null;
      });
    }

    // Location scope applies to every trigger: the whole business, one location,
    // or a chosen list. Exactly the keys of the chosen mode are sent.
    var scopeMode = formEl.querySelector('input[name="wf-scope-mode"]:checked').value;
    config.scope_mode = scopeMode;
    if (scopeMode === 'one') {
      var scopeValue = formEl.querySelector('[data-role="wf-location-scope-select"]').value;
      config.business_location_id = scopeValue ? Number(scopeValue) : null;
    } else if (scopeMode === 'selected') {
      config.business_location_ids = _toConsumableArray(formEl.querySelectorAll('input[data-role="wf-scope-location"]:checked')).map(function (input) {
        return Number(input.value);
      });
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
      if ((0,_conditions_js__WEBPACK_IMPORTED_MODULE_1__.needsOperand)(operator)) {
        // A subject whose operand is a choice (a group, a stage, a status) reads
        // its select; every other reads the text box.
        var operandWrapper = row.querySelector('[data-role="wf-condition-operand-group-wrapper"]');
        condition.operand = operandWrapper && !operandWrapper.hidden ? row.querySelector('[data-role="wf-condition-operand-group"]').value : row.querySelector('[data-role="wf-condition-operand"]').value;
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
  function readTagAction() {
    var tagValue = formEl.querySelector('[data-role="wf-tag-select"]').value;
    return {
      tag_id: tagValue ? Number(tagValue) : null
    };
  }
  function save() {
    if (!currentNode || readOnly) {
      return;
    }
    var config;
    if (currentNode.type === 'trigger') {
      config = readTrigger();
    } else if (currentNode.type === 'if_else') {
      config = readIfElse();
    } else if (currentNode.type === 'update_contact_field') {
      config = readUpdateContactField();
    } else if (currentNode.type === 'add_tag' || currentNode.type === 'remove_tag') {
      config = readTagAction();
    } else if (currentNode.type === 'wait') {
      config = readWait();
    } else if (currentNode.type === 'end') {
      config = {};
    } else if (_drawer_actions_js__WEBPACK_IMPORTED_MODULE_3__.ACTION_TYPES.includes(currentNode.type)) {
      config = (0,_drawer_actions_js__WEBPACK_IMPORTED_MODULE_3__.readAction)(currentNode.type, formEl);
    } else {
      config = readGeneric();
    }
    var node = currentNode;
    onSave(node, config);
    close();
  }
  formEl.addEventListener('submit', function (event) {
    event.preventDefault();
    save();
  });
  saveButton.addEventListener('click', save);
  cancelButton.addEventListener('click', close);
  closeButton.addEventListener('click', close);
  deleteButton.addEventListener('click', function () {
    if (!currentNode) {
      return;
    }
    var node = currentNode;
    close();
    onDelete(node);
  });
  drawerEl.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
    }
  });
  return {
    open: open,
    close: close,
    isOpen: isOpen,
    currentKey: function currentKey() {
      return currentNode ? currentNode.key : null;
    }
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

/***/ "./resources/js/automations/workflow-builder/journeys.js"
/*!***************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/journeys.js ***!
  \***************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createJourneys: () => (/* binding */ createJourneys)
/* harmony export */ });
/* harmony import */ var _constants_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./constants.js */ "./resources/js/automations/workflow-builder/constants.js");
/* harmony import */ var _dom_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./dom.js */ "./resources/js/automations/workflow-builder/dom.js");
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
// Automations V2 (contract §13.1 tabs "Enrollment history" and "Execution logs") —
// who entered the workflow, where they are, and what happened to each of them,
// step by step.
//
// Read-only. It shows what the two existing JSON endpoints already return
// (GET /enrollments, GET /enrollments/{uid}/logs): the people are shown by number,
// never by id, the reasons are the bounded customer-readable ones, and nothing here
// can start, stop or change a journey. Loaded when its tab is first opened.


var STATUS_TONES = {
  active: 'info',
  waiting: 'info',
  completed: 'success',
  failed: 'danger',
  exited: 'secondary',
  cancelled: 'secondary'
};
var STEP_WORDS = {
  succeeded: 'Done',
  failed: 'Could not run',
  skipped: 'Skipped',
  waiting: 'Waiting',
  pending: 'Queued',
  running: 'Running'
};
function when(iso) {
  if (!iso) {
    return '';
  }
  var date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString([], {
    dateStyle: 'medium',
    timeStyle: 'short'
  });
}
function badge(text, tone) {
  return (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', "badge bg-".concat(tone || 'secondary'), text);
}
function createJourneys(_ref) {
  var api = _ref.api,
    basePath = _ref.basePath,
    historyEl = _ref.historyEl,
    logsEl = _ref.logsEl,
    showLogsTab = _ref.showLogsTab;
  var loadedHistory = false;
  var selectedUid = null;
  function note(container, text) {
    container.innerHTML = '';
    container.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'text-muted mb-0', text));
  }
  function loadHistory() {
    return _loadHistory.apply(this, arguments);
  }
  function _loadHistory() {
    _loadHistory = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
      var result, _t;
      return _regenerator().w(function (_context) {
        while (1) switch (_context.p = _context.n) {
          case 0:
            note(historyEl, 'Loading…');
            _context.p = 1;
            _context.n = 2;
            return api.enrollments(basePath);
          case 2:
            result = _context.v;
            if (!(result.status !== 200)) {
              _context.n = 3;
              break;
            }
            note(historyEl, 'The history could not be loaded right now.');
            return _context.a(2);
          case 3:
            renderHistory(result.body && result.body.enrollments || []);
            loadedHistory = true;
            _context.n = 5;
            break;
          case 4:
            _context.p = 4;
            _t = _context.v;
            note(historyEl, 'The history could not be loaded right now.');
          case 5:
            return _context.a(2);
        }
      }, _callee, null, [[1, 4]]);
    }));
    return _loadHistory.apply(this, arguments);
  }
  function renderHistory(rows) {
    historyEl.innerHTML = '';
    if (rows.length === 0) {
      historyEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'text-muted mb-0', 'Nobody has entered this workflow yet.'));
      return;
    }
    var table = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('table', 'table table-sm align-middle mb-0');
    var head = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('tr');
    ['Contact', 'Status', 'Started', 'Steps', ''].forEach(function (label) {
      return head.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('th', null, label));
    });
    table.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('thead')).appendChild(head);
    var body = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('tbody');
    rows.forEach(function (row) {
      var _row$step_count;
      var tr = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('tr');
      tr.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('td', null, row.contact_label || 'A contact'));
      var status = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('td');
      status.appendChild(badge(row.status_label, STATUS_TONES[row.status]));
      if (row.exit_reason_label) {
        status.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('div', 'small text-muted', row.exit_reason_label));
      }
      tr.appendChild(status);
      tr.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('td', null, when(row.enrolled_at)));
      tr.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('td', null, String((_row$step_count = row.step_count) !== null && _row$step_count !== void 0 ? _row$step_count : 0)));
      var action = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('td', 'text-end');
      var view = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('button', 'btn btn-sm btn-outline-secondary', 'View steps');
      view.type = 'button';
      view.dataset.role = 'wf-journey-view';
      view.addEventListener('click', function () {
        return openLogs(row);
      });
      action.appendChild(view);
      tr.appendChild(action);
      body.appendChild(tr);
    });
    table.appendChild(body);
    historyEl.appendChild(table);
  }
  function openLogs(_x) {
    return _openLogs.apply(this, arguments);
  }
  function _openLogs() {
    _openLogs = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee2(row) {
      var result, _t2;
      return _regenerator().w(function (_context2) {
        while (1) switch (_context2.p = _context2.n) {
          case 0:
            selectedUid = row.uid;
            showLogsTab();
            note(logsEl, 'Loading…');
            _context2.p = 1;
            _context2.n = 2;
            return api.enrollmentLogs(basePath, row.uid);
          case 2:
            result = _context2.v;
            if (!(selectedUid !== row.uid)) {
              _context2.n = 3;
              break;
            }
            return _context2.a(2);
          case 3:
            if (!(result.status !== 200)) {
              _context2.n = 4;
              break;
            }
            note(logsEl, 'The steps could not be loaded right now.');
            return _context2.a(2);
          case 4:
            renderLogs(row, result.body && result.body.steps || []);
            _context2.n = 6;
            break;
          case 5:
            _context2.p = 5;
            _t2 = _context2.v;
            note(logsEl, 'The steps could not be loaded right now.');
          case 6:
            return _context2.a(2);
        }
      }, _callee2, null, [[1, 5]]);
    }));
    return _openLogs.apply(this, arguments);
  }
  function renderLogs(row, steps) {
    logsEl.innerHTML = '';
    logsEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('h3', 'h6', "".concat(row.contact_label || 'A contact', " \xB7 ").concat(row.status_label)));
    if (steps.length === 0) {
      logsEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'text-muted mb-0', 'No steps have run for this contact yet.'));
      return;
    }
    var list = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('ol', 'list-group list-group-numbered');
    steps.forEach(function (step) {
      var item = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('li', 'list-group-item d-flex flex-column gap-1');
      var top = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('div', 'd-flex justify-content-between gap-2');
      top.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'fw-semibold', step.node_label || _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_LABELS[step.node_type] || step.node_type));
      top.appendChild(badge(STEP_WORDS[step.status] || step.status, step.status === 'failed' ? 'danger' : step.status === 'succeeded' ? 'success' : 'secondary'));
      item.appendChild(top);
      var detail = step.error_label || step.result;
      if (detail) {
        item.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', step.status === 'failed' ? 'small text-danger' : 'small text-muted', detail));
      }
      if (step.branch_taken) {
        item.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'small text-muted', step.branch_taken === 'yes' ? 'Took the Yes path' : 'Took the No path'));
      }
      var time = when(step.completed_at || step.started_at);
      if (time) {
        item.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'small text-muted', time));
      }
      list.appendChild(item);
    });
    logsEl.appendChild(list);
  }
  function showHistory() {
    if (!loadedHistory) {
      loadHistory();
    }
  }
  function showLogs() {
    if (selectedUid === null) {
      note(logsEl, 'Choose a contact in Enrollment history and pick “View steps” to see what happened, step by step.');
    }
  }
  return {
    showHistory: showHistory,
    showLogs: showLogs,
    refresh: loadHistory
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

// The triggers the cross-domain recipes start from. Each is "every time it
// happens", the default of its trigger, so the policy still says it is a default.
function occurrenceTrigger(type, extra) {
  return Object.assign({
    trigger_type: type,
    enrollment_policy: 'once_per_occurrence',
    enrollment_policy_source: 'default',
    failure_policy: 'halt'
  }, extra || {});
}

// A recipe is only a starting DRAFT: it never runs until it is published. Anything
// that points at one of the Business's own resources (a pipeline and stage, a form, a
// product) starts empty — a recipe cannot know them — and the builder says exactly
// what is still to choose. `requires` names the account capabilities a recipe needs;
// the chooser shows a recipe the account cannot use disabled, with the reason.
function listRecipes() {
  return [{
    key: 'welcome_new_contact',
    titleKey: 'welcome_new_contact',
    descriptionKey: 'welcome_new_contact_description',
    requires: ['sms'],
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
    requires: ['sms'],
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
    key: 'new_lead_follow_up',
    titleKey: 'new_lead_follow_up',
    descriptionKey: 'new_lead_follow_up_description',
    requires: ['email', 'sms'],
    build: function build() {
      return starter(occurrenceTrigger('form_submitted', {
        form_id: null
      }), [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('send_email', {
        subject: 'Thanks for getting in touch',
        body: 'Hi {first_name},\n\nThanks for reaching out. We have your details and will be in touch shortly.'
      }), (0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('wait', {
        mode: 'duration',
        amount: 1,
        unit: 'days'
      }), (0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('send_sms', {
        body: 'Hi {first_name}, just checking you got our email. Any questions, reply here.'
      })]);
    }
  }, {
    key: 'booking_follow_up',
    titleKey: 'booking_follow_up',
    descriptionKey: 'booking_follow_up_description',
    requires: ['crm', 'email'],
    build: function build() {
      return starter(occurrenceTrigger('appointment_scheduled'), [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('move_opportunity', {
        pipeline_id: null,
        stage_id: null
      }), (0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('send_email', {
        subject: 'Your appointment is booked',
        body: 'Hi {first_name},\n\nYour appointment is booked. We look forward to seeing you.'
      })]);
    }
  }, {
    key: 'proposal_follow_up',
    titleKey: 'proposal_follow_up',
    descriptionKey: 'proposal_follow_up_description',
    requires: ['documents', 'email'],
    build: function build() {
      var wait = (0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('wait', {
        mode: 'duration',
        amount: 3,
        unit: 'days'
      });
      var branch = (0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('if_else', {
        match: 'all',
        conditions: [{
          subject: 'document.signed',
          operator: 'is_false'
        }]
      });
      branch.yes = [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('send_email', {
        subject: 'A reminder about your proposal',
        body: 'Hi {first_name},\n\nJust a reminder that your proposal is waiting for your signature. Reply if you have any questions.'
      })];
      branch.no = [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('end', {})];
      return starter(occurrenceTrigger('document_sent', {
        document_kind: 'proposal'
      }), [wait, branch]);
    }
  }, {
    key: 'signed_to_payment',
    titleKey: 'signed_to_payment',
    descriptionKey: 'signed_to_payment_description',
    requires: ['payments'],
    build: function build() {
      return starter(occurrenceTrigger('document_signed', {
        document_kind: 'proposal'
      }), [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('request_payment', {
        source: 'document'
      })]);
    }
  }, {
    key: 'payment_complete',
    titleKey: 'payment_complete',
    descriptionKey: 'payment_complete_description',
    requires: ['crm', 'forms'],
    build: function build() {
      return starter(occurrenceTrigger('payment_succeeded'), [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('move_opportunity', {
        pipeline_id: null,
        stage_id: null
      }), (0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('send_questionnaire', {
        form_id: null,
        channels: ['email'],
        subject: '',
        message: ''
      }), (0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('internal_notification', {
        message: 'Payment received from {first_name} {last_name}'
      })]);
    }
  }, {
    key: 'date_reminder',
    titleKey: 'date_reminder',
    descriptionKey: 'date_reminder_description',
    requires: ['sms'],
    build: function build() {
      return starter(Object.assign({}, TRIGGER_DEFAULTS.contact_date_reached), [(0,_document_model_js__WEBPACK_IMPORTED_MODULE_0__.newNode)('send_sms', {
        body: 'Hi {first_name}, just a friendly reminder from {business_name}!'
      })]);
    }
  }];
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/step-picker.js"
/*!******************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/step-picker.js ***!
  \******************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createStepPicker: () => (/* binding */ createStepPicker)
/* harmony export */ });
/* harmony import */ var _constants_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./constants.js */ "./resources/js/automations/workflow-builder/constants.js");
/* harmony import */ var _dom_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./dom.js */ "./resources/js/automations/workflow-builder/dom.js");
function _toConsumableArray(r) { return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray(r) || _nonIterableSpread(); }
function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _iterableToArray(r) { if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r); }
function _arrayWithoutHoles(r) { if (Array.isArray(r)) return _arrayLikeToArray(r); }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
// Automations V2 (contract §13.1) — the step picker behind every "+".
//
// A searchable list of the steps that are VALID at that exact spot. The canvas
// decides which types those are (an End only closes a path; an If / Else is
// withheld at the nesting limit), so the picker can never offer a shape the
// document validator would refuse. Search matches the customer label, the
// description and a few everyday words ("delay", "sms", "branch").
//
// Keyboard: typing filters, ↑/↓ move, Enter adds, Escape closes.


function createStepPicker(hostEl) {
  var panel = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('div', 'wf-picker');
  panel.dataset.role = 'wf-step-picker';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Add a step');
  panel.hidden = true;
  var searchWrap = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('div', 'wf-picker__search');
  searchWrap.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.icon)('search'));
  var search = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('input', 'wf-picker__input');
  search.type = 'search';
  search.placeholder = 'Search steps';
  search.setAttribute('aria-label', 'Search steps');
  search.dataset.role = 'wf-step-search';
  searchWrap.appendChild(search);
  var list = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('div', 'wf-picker__list');
  list.setAttribute('role', 'listbox');
  var note = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'wf-picker__note');
  note.hidden = true;
  panel.appendChild(searchWrap);
  panel.appendChild(list);
  panel.appendChild(note);
  hostEl.appendChild(panel);
  var options = null;
  var anchor = null;
  var activeIndex = 0;
  var visible = [];
  function matches(entry, query) {
    if (query === '') {
      return true;
    }
    var haystack = [_constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_LABELS[entry.type], entry.description, entry.group].concat(_toConsumableArray(entry.keywords)).join(' ').toLowerCase();
    return query.split(/\s+/).filter(Boolean).every(function (word) {
      return haystack.includes(word);
    });
  }
  function render() {
    var query = search.value.trim().toLowerCase();
    list.innerHTML = '';
    visible = _constants_js__WEBPACK_IMPORTED_MODULE_0__.STEP_CATALOG.filter(function (entry) {
      return options.types.includes(entry.type) && matches(entry, query);
    });
    if (activeIndex >= visible.length) {
      activeIndex = Math.max(0, visible.length - 1);
    }
    if (visible.length === 0) {
      list.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'wf-picker__empty', 'No step matches that search.'));
    }
    var lastGroup = null;
    visible.forEach(function (entry, index) {
      if (entry.group !== lastGroup) {
        list.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'wf-picker__group', entry.group));
        lastGroup = entry.group;
      }
      var option = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('button', 'wf-picker__option');
      option.type = 'button';
      option.setAttribute('role', 'option');
      option.dataset.nodeType = entry.type;
      option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
      option.classList.toggle('is-active', index === activeIndex);

      // A step the account cannot use is shown, disabled, with the reason — never
      // offered and then refused at publish.
      var reason = unavailableReason(entry);
      if (reason) {
        option.classList.add('is-disabled');
        option.setAttribute('aria-disabled', 'true');
        option.dataset.unavailable = 'true';
      }
      option.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.icon)(entry.icon, "wf-tone wf-tone--".concat(entry.type)));
      var text = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'wf-picker__text');
      text.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'wf-picker__label', _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_LABELS[entry.type]));
      text.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'wf-picker__description', reason || options.hints && options.hints[entry.type] || entry.description));
      option.appendChild(text);
      option.addEventListener('mouseenter', function () {
        activeIndex = index;
        syncActive();
      });
      option.addEventListener('click', function () {
        return choose(entry.type);
      });
      list.appendChild(option);
    });
  }
  function syncActive() {
    list.querySelectorAll('.wf-picker__option').forEach(function (option, index) {
      var active = index === activeIndex;
      option.classList.toggle('is-active', active);
      option.setAttribute('aria-selected', active ? 'true' : 'false');
      if (active) {
        option.scrollIntoView({
          block: 'nearest'
        });
      }
    });
  }
  function position() {
    var rect = anchor.getBoundingClientRect();
    var width = panel.offsetWidth || 340;
    var height = panel.offsetHeight || 380;
    var left = Math.min(Math.max(12, rect.left + rect.width / 2 - width / 2), window.innerWidth - width - 12);
    var below = rect.bottom + 8;
    var top = below + height > window.innerHeight - 12 ? Math.max(12, rect.top - height - 8) : below;
    panel.style.left = "".concat(left, "px");
    panel.style.top = "".concat(top, "px");
  }

  /** The plain reason this step cannot be used by this account, or null when it can. */
  function unavailableReason(entry) {
    var capability = entry.requires && options && options.capabilities ? options.capabilities[entry.requires] : null;
    return capability && capability.available === false ? capability.reason || 'Not available on your account.' : null;
  }
  function choose(type) {
    var entry = _constants_js__WEBPACK_IMPORTED_MODULE_0__.STEP_CATALOG.find(function (candidate) {
      return candidate.type === type;
    });
    if (entry && unavailableReason(entry)) {
      return;
    }
    var chosen = options;
    close();
    chosen.onPick(type);
  }
  function open(anchorEl, pickerOptions) {
    anchor = anchorEl;
    options = pickerOptions;
    activeIndex = 0;
    search.value = '';
    note.hidden = !pickerOptions.note;
    note.textContent = pickerOptions.note || '';
    panel.hidden = false;
    anchor.setAttribute('aria-expanded', 'true');
    render();
    position();
    search.focus();
  }
  function close() {
    if (panel.hidden) {
      return;
    }
    panel.hidden = true;
    if (anchor) {
      anchor.setAttribute('aria-expanded', 'false');
      anchor.focus({
        preventScroll: true
      });
    }
    anchor = null;
    options = null;
  }
  search.addEventListener('input', function () {
    activeIndex = 0;
    render();
  });
  panel.addEventListener('keydown', function (event) {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      activeIndex = Math.min(visible.length - 1, activeIndex + 1);
      syncActive();
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      activeIndex = Math.max(0, activeIndex - 1);
      syncActive();
    } else if (event.key === 'Enter') {
      event.preventDefault();
      if (visible[activeIndex]) {
        choose(visible[activeIndex].type);
      }
    } else if (event.key === 'Escape') {
      event.preventDefault();
      close();
    }
  });
  document.addEventListener('mousedown', function (event) {
    if (!panel.hidden && !panel.contains(event.target) && event.target !== anchor && !(anchor && anchor.contains(event.target))) {
      close();
    }
  });
  var reposition = function reposition() {
    if (!panel.hidden && anchor && anchor.isConnected) {
      position();
    }
  };
  window.addEventListener('resize', reposition);
  // Capture phase: the canvas scrolls inside its own viewport, not the window.
  window.addEventListener('scroll', reposition, true);
  return {
    open: open,
    close: close,
    isOpen: function isOpen() {
      return !panel.hidden;
    }
  };
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/summaries.js"
/*!****************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/summaries.js ***!
  \****************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   summarize: () => (/* binding */ summarize)
/* harmony export */ });
/* harmony import */ var _constants_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./constants.js */ "./resources/js/automations/workflow-builder/constants.js");
/* harmony import */ var _conditions_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./conditions.js */ "./resources/js/automations/workflow-builder/conditions.js");
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
// Automations V2 (contract §13.1) — what each card SAYS.
//
// A card on the canvas is only useful if it tells a person what the step does
// for this contact, in plain language, without opening it: "Wait 2 days",
// "Text: “Hi {first_name}…”", "If the customer has not replied yet". This
// module turns a node's stored config into that sentence. It reads only the
// document and the Business catalogs the page was handed; it never shows an
// identifier, and an unfinished step reads as the thing still to do.


var EXCERPT_LENGTH = 90;
function excerpt(text) {
  var clean = String(text || '').replace(/\s+/g, ' ').trim();
  return clean.length > EXCERPT_LENGTH ? "".concat(clean.slice(0, EXCERPT_LENGTH - 1), "\u2026") : clean;
}
function groupName(catalogs, id) {
  var group = (catalogs.contactGroups || []).find(function (row) {
    return String(row.id) === String(id);
  });
  return group ? group.name : null;
}
function fieldLabel(rows, id) {
  var field = (rows || []).find(function (row) {
    return String(row.id) === String(id);
  });
  return field ? field.label : null;
}

/** A CRM pipeline or stage name from the Business catalog, or null when not set or not found. */
function crmName(rows, id) {
  if (id === null || id === undefined || id === '') {
    return null;
  }
  var row = (rows || []).find(function (candidate) {
    return String(candidate.id) === String(id);
  });
  return row ? row.name : null;
}

/** A tag or form name from the Business catalog, or null when not set or not found. */
function namedRow(rows, id) {
  return crmName(rows, id);
}

/** "by email", "by text", "by email and text". */
function channelText(channels) {
  var list = Array.isArray(channels) ? channels : [];
  var words = [list.includes('email') ? 'email' : null, list.includes('sms') ? 'text' : null].filter(Boolean);
  return words.length ? "by ".concat(words.join(' and ')) : '— choose how to send it';
}
function hasChannels(config) {
  return Array.isArray(config.channels) && config.channels.length > 0;
}

/** The Location(s) a workflow is limited to, in words; null for the whole business. */
function scopeText(config, catalogs) {
  if (config.scope_mode === 'selected') {
    var names = (Array.isArray(config.business_location_ids) ? config.business_location_ids : []).map(function (id) {
      return namedRow(catalogs.locations, id);
    }).filter(Boolean);
    return names.length ? names.join(', ') : null;
  }
  return namedRow(catalogs.locations, config.business_location_id);
}
function plural(amount, unit) {
  var singular = unit.replace(/s$/, '');
  return Number(amount) === 1 ? "1 ".concat(singular) : "".concat(amount, " ").concat(unit);
}
function formatMoment(value) {
  var match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ''));
  if (!match) {
    return null;
  }
  var date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4]), Number(match[5]));
  return date.toLocaleString(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short'
  });
}
function formatTime(value) {
  var match = /^(\d{2}):(\d{2})$/.exec(String(value || ''));
  if (!match) {
    return null;
  }
  var date = new Date(2000, 0, 1, Number(match[1]), Number(match[2]));
  return date.toLocaleTimeString(undefined, {
    hour: 'numeric',
    minute: '2-digit'
  });
}

/**
 * @returns {{ title: string, summary: string, incomplete: boolean }}
 */
function summarize(node, catalogs) {
  var config = node.config || {};
  switch (node.type) {
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.TRIGGER:
      {
        var trigger = summarizeTrigger(config, catalogs);
        var location = scopeText(config, catalogs);

        // "· Downtown" when limited to a Location, "· Downtown, Uptown" for a chosen
        // list; nothing for the whole business.
        return location && trigger.summary ? _objectSpread(_objectSpread({}, trigger), {}, {
          summary: "".concat(trigger.summary, " \xB7 ").concat(location)
        }) : trigger;
      }
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.MOVE_OPPORTUNITY:
      {
        var pipeline = crmName(catalogs.crmPipelines, config.pipeline_id);
        var stage = crmName(catalogs.crmStages, config.stage_id);
        return pipeline && stage ? {
          summary: "Move the opportunity to \u201C".concat(stage, "\u201D in ").concat(pipeline),
          incomplete: false
        } : {
          summary: 'Choose a pipeline and a stage',
          incomplete: true
        };
      }
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.SEND_BOOKING_LINK:
      {
        var type = namedRow(catalogs.bookingTypes, config.booking_type_id);
        return type ? {
          summary: "Send the \u201C".concat(type, "\u201D booking link ").concat(channelText(config.channels)),
          incomplete: !hasChannels(config)
        } : {
          summary: 'Choose a booking type to send',
          incomplete: true
        };
      }
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.SEND_FORM:
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.SEND_QUESTIONNAIRE:
      {
        var noun = node.type === _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.SEND_FORM ? 'form' : 'questionnaire';
        var form = namedRow(catalogs.forms, config.form_id);
        return form ? {
          summary: "Send the ".concat(noun, " \u201C").concat(form, "\u201D ").concat(channelText(config.channels)),
          incomplete: !hasChannels(config)
        } : {
          summary: "Choose a ".concat(noun, " to send"),
          incomplete: true
        };
      }
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.CREATE_SEND_PROPOSAL:
      {
        var item = namedRow(catalogs.catalogItems, config.catalog_item_id);
        var title = String(config.title || '').trim();
        if (!title || !item) {
          return {
            summary: !title ? 'Give the proposal a title' : 'Choose a product or package',
            incomplete: true
          };
        }
        var deposit = config.payment_schedule === 'deposit' ? " \xB7 ".concat(config.deposit_percent, "% deposit") : '';
        return {
          summary: "Email \u201C".concat(excerpt(title), "\u201D (").concat(item, ")").concat(deposit),
          incomplete: false
        };
      }
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.REQUEST_PAYMENT:
      {
        if (config.source === 'document') {
          return {
            summary: 'Email a payment link for this workflow’s document',
            incomplete: false
          };
        }
        var _title = String(config.title || '').trim();
        var _item = namedRow(catalogs.catalogItems, config.catalog_item_id);
        var amount = config.amount_minor ? (Number(config.amount_minor) / 100).toFixed(2) : null;
        if (!_title || !_item && !amount) {
          return {
            summary: !_title ? 'Give the invoice a title' : 'Choose a product or enter an amount',
            incomplete: true
          };
        }
        return {
          summary: "Invoice \u201C".concat(excerpt(_title), "\u201D \u2014 ").concat(_item || amount),
          incomplete: false
        };
      }
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.SEND_SMS:
      return config.body && String(config.body).trim() !== '' ? {
        summary: "\u201C".concat(excerpt(config.body), "\u201D"),
        incomplete: false
      } : {
        summary: 'Write the message to send',
        incomplete: true
      };
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.INTERNAL_NOTIFICATION:
      return config.message && String(config.message).trim() !== '' ? {
        summary: "\u201C".concat(excerpt(config.message), "\u201D"),
        incomplete: false
      } : {
        summary: 'Write what your team should be told',
        incomplete: true
      };
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.SEND_EMAIL:
      return config.subject && String(config.subject).trim() !== '' && config.body && String(config.body).trim() !== '' ? {
        summary: "\u201C".concat(excerpt(config.subject), "\u201D"),
        incomplete: false
      } : {
        summary: 'Write the email subject and message',
        incomplete: true
      };
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.ADD_TAG:
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.REMOVE_TAG:
      {
        var tag = namedRow(catalogs.tags, config.tag_id);
        var verb = node.type === _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.ADD_TAG ? 'Add' : 'Remove';
        return tag ? {
          summary: "".concat(verb, " the tag \u201C").concat(tag, "\u201D"),
          incomplete: false
        } : {
          summary: 'Choose a tag',
          incomplete: true
        };
      }
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.UPDATE_CONTACT_FIELD:
      {
        var label = fieldLabel(catalogs.writableFields, config.field_id);
        if (!label) {
          return {
            summary: 'Choose a field to update',
            incomplete: true
          };
        }
        return String(config.value || '') === '' ? {
          summary: "Clear ".concat(label),
          incomplete: false
        } : {
          summary: "Set ".concat(label, " to \u201C").concat(excerpt(config.value), "\u201D"),
          incomplete: false
        };
      }
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.WAIT:
      if (config.mode === 'until_datetime') {
        var moment = formatMoment(config.at);
        return moment ? {
          summary: "Until ".concat(moment),
          incomplete: false
        } : {
          summary: 'Choose when to continue',
          incomplete: true
        };
      }
      return Number(config.amount) > 0 && config.unit ? {
        summary: "Wait ".concat(plural(config.amount, config.unit)),
        incomplete: false
      } : {
        summary: 'Choose how long to wait',
        incomplete: true
      };
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.IF_ELSE:
      {
        var conditions = Array.isArray(config.conditions) ? config.conditions : [];
        if (conditions.length === 0) {
          return {
            summary: 'Add a condition to check',
            incomplete: true
          };
        }
        var joiner = config.match === 'any' ? ' or ' : ' and ';
        // Read as one sentence after "If": built-in subjects go lower-case
        // ("If customer has not replied yet and first name is …"), while a
        // custom field keeps the capitals its Business gave its label.
        var sentence = conditions.map(function (condition) {
          var text = (0,_conditions_js__WEBPACK_IMPORTED_MODULE_1__.describeCondition)(condition, catalogs);
          return (0,_conditions_js__WEBPACK_IMPORTED_MODULE_1__.customFieldId)(condition && condition.subject) === null ? text.charAt(0).toLowerCase() + text.slice(1) : text;
        }).join(joiner);
        return {
          summary: "If ".concat(sentence),
          incomplete: false
        };
      }
    case _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_TYPES.END:
      return {
        summary: 'The workflow stops here for this contact',
        incomplete: false
      };
    default:
      return {
        summary: '',
        incomplete: false
      };
  }
}
function documentKindText(kind) {
  return kind === 'invoice' ? 'an invoice' : kind === 'proposal' ? 'a proposal or contract' : 'a proposal, contract or invoice';
}
function summarizeTrigger(config, catalogs) {
  var info = (0,_constants_js__WEBPACK_IMPORTED_MODULE_0__.triggerTypeInfo)(config.trigger_type);
  if (!info) {
    return {
      title: 'Trigger',
      summary: 'Choose what starts this workflow',
      incomplete: true
    };
  }
  var group = groupName(catalogs, config.contact_group_id);
  switch (info.value) {
    case 'contact_created':
      {
        var when = group ? "When a contact is added to ".concat(group) : 'When any new contact is added';
        var source = config.source && config.source !== 'any' ? " \xB7 ".concat(_constants_js__WEBPACK_IMPORTED_MODULE_0__.CONTACT_SOURCE_LABELS[config.source] || '') : '';
        return {
          title: info.title,
          summary: "".concat(when).concat(source),
          incomplete: false
        };
      }
    case 'message_received':
      return {
        title: info.title,
        summary: 'When a contact texts your business',
        incomplete: false
      };
    case 'contact_date_reached':
      {
        var field = fieldLabel(catalogs.dateFields, config.date_field_id);
        if (!group || !field) {
          return {
            title: info.title,
            summary: 'Choose a group and a date field',
            incomplete: true
          };
        }
        var time = formatTime(config.send_at);
        var _when = !config.offset || config.offset === '0 day' ? 'On' : "".concat(config.offset, " before");
        return {
          title: info.title,
          summary: "".concat(_when, " ").concat(field, " \xB7 ").concat(group).concat(time ? " \xB7 at ".concat(time) : ''),
          incomplete: false
        };
      }
    case 'manual_enrollment':
      return {
        title: info.title,
        summary: 'When you add a contact to this workflow',
        incomplete: false
      };
    case 'opportunity_created':
      return {
        title: info.title,
        summary: 'When a new opportunity is added, in any pipeline',
        incomplete: false
      };
    case 'opportunity_stage_changed':
      {
        var pipeline = crmName(catalogs.crmPipelines, config.pipeline_id);
        var from = crmName(catalogs.crmStages, config.from_stage_id);
        var to = crmName(catalogs.crmStages, config.to_stage_id);
        var move = 'When an opportunity moves stage';
        if (from && to) {
          move = "When an opportunity moves from ".concat(from, " to ").concat(to);
        } else if (to) {
          move = "When an opportunity moves to ".concat(to);
        } else if (from) {
          move = "When an opportunity leaves ".concat(from);
        }
        return {
          title: info.title,
          summary: "".concat(move).concat(pipeline ? " \xB7 ".concat(pipeline) : ''),
          incomplete: false
        };
      }
    case 'opportunity_won':
      return {
        title: info.title,
        summary: 'When an opportunity is marked won',
        incomplete: false
      };
    case 'opportunity_lost':
      return {
        title: info.title,
        summary: 'When an opportunity is marked lost',
        incomplete: false
      };
    case 'contact_tag_added':
    case 'contact_tag_removed':
      {
        var tag = namedRow(catalogs.tags, config.tag_id);
        var verb = info.value === 'contact_tag_added' ? 'added to' : 'removed from';
        return {
          title: info.title,
          summary: tag ? "When \u201C".concat(tag, "\u201D is ").concat(verb, " a contact") : "When any tag is ".concat(verb, " a contact"),
          incomplete: false
        };
      }
    case 'form_submitted':
      {
        var form = namedRow(catalogs.forms, config.form_id);
        return {
          title: info.title,
          summary: form ? "When \u201C".concat(form, "\u201D is submitted") : 'When any form is submitted',
          incomplete: false
        };
      }
    case 'questionnaire_submitted':
      {
        var _form = namedRow(catalogs.forms, config.form_id);
        return {
          title: info.title,
          summary: _form ? "When \u201C".concat(_form, "\u201D is submitted") : 'When any questionnaire is submitted',
          incomplete: false
        };
      }
    case 'document_sent':
    case 'document_signed':
      {
        var _verb = info.value === 'document_sent' ? 'sent' : 'signed';
        return {
          title: info.title,
          summary: "When ".concat(documentKindText(config.document_kind), " is ").concat(_verb),
          incomplete: false
        };
      }
    case 'payment_succeeded':
    case 'payment_failed':
      {
        var _verb2 = info.value === 'payment_succeeded' ? 'succeeds' : 'fails';
        return {
          title: info.title,
          summary: "When a payment for ".concat(documentKindText(config.document_kind), " ").concat(_verb2),
          incomplete: false
        };
      }
    case 'appointment_scheduled':
      return {
        title: info.title,
        summary: 'When an appointment is booked for a contact',
        incomplete: false
      };
    case 'appointment_cancelled':
      return {
        title: info.title,
        summary: 'When an appointment is cancelled',
        incomplete: false
      };
    case 'appointment_rescheduled':
      return {
        title: info.title,
        summary: 'When an appointment is moved to another time',
        incomplete: false
      };
    default:
      return {
        title: info.title,
        summary: '',
        incomplete: false
      };
  }
}

/***/ },

/***/ "./resources/js/automations/workflow-builder/test-panel.js"
/*!*****************************************************************!*\
  !*** ./resources/js/automations/workflow-builder/test-panel.js ***!
  \*****************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createTestPanel: () => (/* binding */ createTestPanel)
/* harmony export */ });
/* harmony import */ var _constants_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./constants.js */ "./resources/js/automations/workflow-builder/constants.js");
/* harmony import */ var _dom_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./dom.js */ "./resources/js/automations/workflow-builder/dom.js");
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
// Automations V2 (contract §13.1 "Test workflow", §16) — try the workflow on
// one real contact, with no side effects.
//
// A person searches this Business's contacts by name or number and picks one;
// the page then asks WorkflowSimulator for the path that contact would take
// through the saved draft. Nothing is sent, changed or enrolled — the
// simulator's own guarantee — and this panel says so. The result is shown two
// ways: as a plain step-by-step story here, and as a highlighted path on the
// canvas (the caller's onPath), so a branch that goes the "wrong" way is
// obvious at a glance.


var DID_WORDS = {
  started: 'Starts the workflow',
  would_run: 'Would run',
  would_wait: 'Would wait',
  branched: 'Checks the condition',
  ended: 'Ends the workflow',
  skipped: 'Would stop here',
  held: 'Would pause here'
};
var ENDED_WORDS = {
  path_end: 'The path runs out of steps, so the workflow finishes for this contact.',
  end_step: 'The workflow ends at an End step.',
  step_limit: 'The test stopped after the most steps one run can take.',
  stopped: 'The workflow would stop for this contact at the step above.'
};
var REFUSED_WORDS = {
  contact_belongs_to_another_business: 'That contact is not part of this business.',
  contact_outside_workflow_location: 'That contact is not at this workflow’s location, so it would never run for them.',
  workflow_cannot_be_walked: 'This workflow can’t be tested until the highlighted problems are fixed.',
  version_has_no_business: 'This workflow can’t be tested right now.'
};
function createTestPanel(_ref) {
  var panelEl = _ref.panelEl,
    api = _ref.api,
    basePath = _ref.basePath,
    beforeRun = _ref.beforeRun,
    onPath = _ref.onPath,
    onClose = _ref.onClose;
  var searchInput = panelEl.querySelector('[data-role="wf-test-search"]');
  var contactsEl = panelEl.querySelector('[data-role="wf-test-contacts"]');
  var resultEl = panelEl.querySelector('[data-role="wf-test-result"]');
  var pickEl = panelEl.querySelector('[data-role="wf-test-pick"]');
  var clearButton = panelEl.querySelector('[data-role="wf-test-clear"]');
  var closeButton = panelEl.querySelector('[data-role="wf-test-close"]');
  var searchTimer = null;
  var searchSeq = 0;
  function open() {
    panelEl.hidden = false;
    showPicker();
    searchInput.focus({
      preventScroll: true
    });
    search();
  }
  function close() {
    if (panelEl.hidden) {
      return;
    }
    panelEl.hidden = true;
    onPath(null);
    if (onClose) {
      onClose();
    }
  }
  function isOpen() {
    return !panelEl.hidden;
  }
  function showPicker() {
    pickEl.hidden = false;
    resultEl.hidden = true;
    clearButton.hidden = true;
    resultEl.innerHTML = '';
  }
  function search() {
    return _search.apply(this, arguments);
  }
  function _search() {
    _search = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
      var seq, result, contacts, _t;
      return _regenerator().w(function (_context) {
        while (1) switch (_context.p = _context.n) {
          case 0:
            seq = ++searchSeq;
            contactsEl.innerHTML = '';
            contactsEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'wf-test__muted', 'Searching…'));
            _context.p = 1;
            _context.n = 2;
            return api.testContacts(basePath, searchInput.value.trim());
          case 2:
            result = _context.v;
            _context.n = 4;
            break;
          case 3:
            _context.p = 3;
            _t = _context.v;
            result = {
              status: 0,
              body: null
            };
          case 4:
            if (!(seq !== searchSeq)) {
              _context.n = 5;
              break;
            }
            return _context.a(2);
          case 5:
            contactsEl.innerHTML = '';
            if (!(result.status === 401)) {
              _context.n = 6;
              break;
            }
            contactsEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'wf-test__muted', 'You don’t have permission to view contacts.'));
            return _context.a(2);
          case 6:
            contacts = result.body && result.body.contacts || [];
            if (!(result.status !== 200)) {
              _context.n = 7;
              break;
            }
            contactsEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'wf-test__muted', 'Contacts couldn’t be loaded. Try again.'));
            return _context.a(2);
          case 7:
            if (!(contacts.length === 0)) {
              _context.n = 8;
              break;
            }
            contactsEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'wf-test__muted', searchInput.value.trim() ? 'No contact matches that search.' : 'This business has no contacts yet.'));
            return _context.a(2);
          case 8:
            contacts.forEach(function (contact) {
              var button = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('button', 'wf-test__contact');
              button.type = 'button';
              button.dataset.role = 'wf-test-contact';
              button.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.icon)('user'));
              var text = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'wf-test__contact-text');
              text.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'wf-test__contact-name', contact.name || contact.phone));
              if (contact.name) {
                text.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'wf-test__contact-phone', contact.phone));
              }
              button.appendChild(text);
              button.addEventListener('click', function () {
                return run(contact);
              });
              contactsEl.appendChild(button);
            });
          case 9:
            return _context.a(2);
        }
      }, _callee, null, [[1, 3]]);
    }));
    return _search.apply(this, arguments);
  }
  function run(_x) {
    return _run.apply(this, arguments);
  }
  function _run() {
    _run = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee2(contact) {
      var result, heading, body, steps, timeline, end, _t2;
      return _regenerator().w(function (_context2) {
        while (1) switch (_context2.p = _context2.n) {
          case 0:
            pickEl.hidden = true;
            resultEl.hidden = false;
            clearButton.hidden = false;
            resultEl.innerHTML = '';
            resultEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'wf-test__muted', 'Running the test…'));
            _context2.n = 1;
            return beforeRun();
          case 1:
            _context2.p = 1;
            _context2.n = 2;
            return api.simulate(basePath, contact.uid);
          case 2:
            result = _context2.v;
            _context2.n = 4;
            break;
          case 3:
            _context2.p = 3;
            _t2 = _context2.v;
            result = {
              status: 0,
              body: null
            };
          case 4:
            resultEl.innerHTML = '';
            heading = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('div', 'wf-test__subject');
            heading.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.icon)('user'));
            heading.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', null, "Testing with ".concat(contact.name || contact.phone)));
            resultEl.appendChild(heading);
            body = result.body || {};
            if (!(result.status !== 200 || body.refused)) {
              _context2.n = 5;
              break;
            }
            resultEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'wf-test__notice', REFUSED_WORDS[body.refused] || body.message || 'The test couldn’t run. Try again.'));
            onPath(null);
            return _context2.a(2);
          case 5:
            if (body.validation && Object.keys(body.validation).length > 0) {
              resultEl.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'wf-test__notice', 'This draft still has problems to fix before it can publish. The path below shows what would happen as it stands.'));
            }
            steps = Array.isArray(body.path) ? body.path : [];
            timeline = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('ol', 'wf-test__timeline');
            steps.forEach(function (step) {
              var item = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('li', 'wf-test__step');
              item.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.icon)(_constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_ICONS[step.type] || 'zap', "wf-tone wf-tone--".concat(step.type)));
              var text = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('div', 'wf-test__step-text');
              text.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'wf-test__step-title', step.type === 'trigger' ? 'Trigger' : _constants_js__WEBPACK_IMPORTED_MODULE_0__.NODE_LABELS[step.type] || 'Step'));
              var line = DID_WORDS[step.did] || '';
              if (step.did === 'branched' && step.branch) {
                line = "Takes the ".concat(step.branch === 'yes' ? 'Yes' : 'No', " path");
              }
              if (step.did === 'would_wait' && step.resume_at) {
                line = "Would wait until ".concat(new Date(step.resume_at).toLocaleString(undefined, {
                  dateStyle: 'medium',
                  timeStyle: 'short'
                }));
              }
              text.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'wf-test__step-did', line));

              // The simulator's detail explains what testing withheld ("Nothing
              // is sent while testing") or why a path stopped. For a start, a
              // branch or a timed wait the line above already says it.
              var explains = ['would_run', 'held', 'skipped'].includes(step.did) || step.did === 'would_wait' && !step.resume_at;
              if (step.detail && explains) {
                text.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', 'wf-test__step-detail', step.detail));
              }
              item.appendChild(text);
              timeline.appendChild(item);
            });
            resultEl.appendChild(timeline);
            if (body.ended && ENDED_WORDS[body.ended]) {
              end = (0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('p', 'wf-test__end');
              end.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.icon)('flag'));
              end.appendChild((0,_dom_js__WEBPACK_IMPORTED_MODULE_1__.el)('span', null, ENDED_WORDS[body.ended]));
              resultEl.appendChild(end);
            }
            onPath(new Map(steps.map(function (step) {
              return [step.key, step];
            })));
          case 6:
            return _context2.a(2);
        }
      }, _callee2, null, [[1, 3]]);
    }));
    return _run.apply(this, arguments);
  }
  searchInput.addEventListener('input', function () {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(search, 250);
  });
  clearButton.addEventListener('click', function () {
    onPath(null);
    showPicker();
    searchInput.focus({
      preventScroll: true
    });
  });
  closeButton.addEventListener('click', close);
  panelEl.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
    }
  });
  return {
    open: open,
    close: close,
    isOpen: isOpen
  };
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
    // Pan from the empty canvas only: pressing a card, a "+" or a menu is a
    // click on that control, never the start of a drag.
    if (event.button !== 0 || event.target.closest('button, a, input, select, textarea, [role="button"], .dropdown-menu')) {
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
/* harmony import */ var _step_picker_js__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./step-picker.js */ "./resources/js/automations/workflow-builder/step-picker.js");
/* harmony import */ var _test_panel_js__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./test-panel.js */ "./resources/js/automations/workflow-builder/test-panel.js");
/* harmony import */ var _journeys_js__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./journeys.js */ "./resources/js/automations/workflow-builder/journeys.js");
/* harmony import */ var _recipes_js__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ./recipes.js */ "./resources/js/automations/workflow-builder/recipes.js");
/* harmony import */ var _document_model_js__WEBPACK_IMPORTED_MODULE_10__ = __webpack_require__(/*! ./document-model.js */ "./resources/js/automations/workflow-builder/document-model.js");
/* harmony import */ var _validation_js__WEBPACK_IMPORTED_MODULE_11__ = __webpack_require__(/*! ./validation.js */ "./resources/js/automations/workflow-builder/validation.js");
/* harmony import */ var _constants_js__WEBPACK_IMPORTED_MODULE_12__ = __webpack_require__(/*! ./constants.js */ "./resources/js/automations/workflow-builder/constants.js");
function _toConsumableArray(r) { return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray(r) || _nonIterableSpread(); }
function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _iterableToArray(r) { if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r); }
function _arrayWithoutHoles(r) { if (Array.isArray(r)) return _arrayLikeToArray(r); }
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













var STATUS_LABELS = {
  draft: 'Draft',
  published: 'Published',
  paused: 'Paused',
  archived: 'Archived'
};
function initBuilder(root) {
  var dataEl = document.getElementById('wf-builder-data');
  if (!dataEl) {
    return;
  }
  var data = JSON.parse(dataEl.textContent);
  var doc = data.draft.definition;
  var errors = data.draft.errors || {};
  var selectedKey = null;
  var path = null;
  var status = data.workflow.status || 'draft';
  var readOnly = status === 'archived';
  var canvasRootEl = root.querySelector('[data-role="wf-canvas-root"]');
  var saveStateEl = root.querySelector('[data-role="wf-save-state"]');
  var bannerEl = root.querySelector('[data-role="wf-document-errors"]');
  var undoButton = root.querySelector('[data-role="wf-undo"]');
  var redoButton = root.querySelector('[data-role="wf-redo"]');
  var publishButton = root.querySelector('[data-role="wf-publish"]');
  var publishLabel = root.querySelector('[data-role="wf-publish-label"]');
  var pauseButton = root.querySelector('[data-role="wf-pause"]');
  var resumeButton = root.querySelector('[data-role="wf-resume"]');
  var statusEl = root.querySelector('[data-role="wf-status"]');
  var noticeEl = root.querySelector('[data-role="wf-header-notice"]');
  var testButton = root.querySelector('[data-role="wf-test-workflow"]');
  var viewportEl = root.querySelector('[data-role="wf-canvas-viewport"]');
  var surfaceEl = root.querySelector('[data-role="wf-canvas-surface"]');
  var workspaceEl = root.querySelector('[data-role="wf-workspace"]');
  var api = (0,_api_js__WEBPACK_IMPORTED_MODULE_5__.createApiClient)(data.basePath);
  var history = (0,_history_js__WEBPACK_IMPORTED_MODULE_2__.createHistory)(doc);
  var zoomPan = (0,_zoom_pan_js__WEBPACK_IMPORTED_MODULE_3__.createZoomPan)(viewportEl, surfaceEl);
  var picker = (0,_step_picker_js__WEBPACK_IMPORTED_MODULE_6__.createStepPicker)(root);
  root.querySelector('[data-role="wf-zoom-in"]').addEventListener('click', zoomPan.zoomIn);
  root.querySelector('[data-role="wf-zoom-out"]').addEventListener('click', zoomPan.zoomOut);
  root.querySelector('[data-role="wf-zoom-reset"]').addEventListener('click', zoomPan.reset);
  root.querySelector('[data-role="wf-zoom-fit"]').addEventListener('click', zoomPan.fit);

  // ---------------------------------------------------------------
  // Header: save state, status, lifecycle
  // ---------------------------------------------------------------

  function setSaveState(state) {
    saveStateEl.dataset.state = state;
    var label = {
      saving: 'Saving…',
      saved: 'All changes saved',
      error: 'Offline — changes kept locally'
    }[state];
    saveStateEl.querySelector('[data-role="wf-save-state-label"]').textContent = label || '';
  }
  function showNotice(message, tone) {
    noticeEl.textContent = message || '';
    noticeEl.dataset.tone = tone || 'info';
    noticeEl.hidden = !message;
  }
  function renderStatus() {
    statusEl.dataset.status = status;
    statusEl.textContent = STATUS_LABELS[status] || status;
    pauseButton.hidden = status !== 'published';
    resumeButton.hidden = status !== 'paused';
    publishButton.hidden = readOnly;
    publishLabel.textContent = status === 'draft' ? 'Publish' : 'Publish changes';
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
      bannerEl.innerHTML = '';
      bannerEl.appendChild(Object.assign(document.createElement('p'), {
        className: 'mb-0',
        textContent: 'This workflow changed somewhere else. Reload to get the latest version before editing.'
      }));
    }
  });

  // ---------------------------------------------------------------
  // Panels: inspector and test
  // ---------------------------------------------------------------

  function syncPanels() {
    workspaceEl.classList.toggle('has-panel', drawer.isOpen() || testPanel.isOpen());
  }
  var drawer = (0,_drawer_js__WEBPACK_IMPORTED_MODULE_1__.createDrawer)({
    drawerEl: root.querySelector('[data-role="wf-drawer"]'),
    catalogs: data.catalogs,
    dateOffsets: data.dateOffsets,
    limits: data.limits,
    locationScope: data.locationScope,
    capabilities: data.capabilities || {},
    currency: data.currency || '',
    // The trigger as the document has it NOW, so a condition or an action only
    // offers what the workflow's trigger can give it.
    triggerType: function triggerType() {
      return doc && doc.root && doc.root.config ? doc.root.config.trigger_type : null;
    },
    onSave: function onSave(node, config) {
      node.config = config;
      onDocumentChanged();
    },
    onDelete: function onDelete(node) {
      deleteNode(node, true);
    },
    onClose: function onClose(node) {
      selectedKey = null;
      syncPanels();
      rerender();
      var card = node && canvasRootEl.querySelector("[data-node-key=\"".concat(CSS.escape(node.key), "\"]"));
      if (card) {
        card.focus({
          preventScroll: true
        });
      }
    }
  });
  var testPanel = (0,_test_panel_js__WEBPACK_IMPORTED_MODULE_7__.createTestPanel)({
    panelEl: root.querySelector('[data-role="wf-test-panel"]'),
    api: api,
    basePath: data.basePath,
    beforeRun: function beforeRun() {
      return autosave.flushPending();
    },
    onPath: function onPath(nextPath) {
      path = nextPath;
      rerender();
    },
    onClose: function onClose() {
      syncPanels();
    }
  });

  // The two read-only tabs: who entered the workflow, and what happened to each.
  var historyPane = root.querySelector('[data-role="wf-journeys-history"]');
  var logsPane = root.querySelector('[data-role="wf-journeys-logs"]');
  if (historyPane && logsPane) {
    var logsTab = root.querySelector('[data-bs-target="#wf-tabs-logs"]');
    var journeys = (0,_journeys_js__WEBPACK_IMPORTED_MODULE_8__.createJourneys)({
      api: api,
      basePath: data.basePath,
      historyEl: historyPane,
      logsEl: logsPane,
      showLogsTab: function showLogsTab() {
        return logsTab && logsTab.click();
      }
    });
    var historyTab = root.querySelector('[data-bs-target="#wf-tabs-enrollments"]');
    historyTab && historyTab.addEventListener('click', function () {
      return journeys.showHistory();
    });
    logsTab && logsTab.addEventListener('click', function () {
      return journeys.showLogs();
    });
  }
  function selectNode(node) {
    if (testPanel.isOpen()) {
      testPanel.close();
    }
    selectedKey = node.key;
    drawer.open(node, errors[node.key] || [], {
      readOnly: readOnly
    });
    syncPanels();
    rerender();
  }

  // ---------------------------------------------------------------
  // Document edits
  // ---------------------------------------------------------------

  function findContainingList(target, list) {
    if (list.includes(target)) {
      return list;
    }
    var _iterator = _createForOfIteratorHelper(list),
      _step;
    try {
      for (_iterator.s(); !(_step = _iterator.n()).done;) {
        var node = _step.value;
        if (node.type === _constants_js__WEBPACK_IMPORTED_MODULE_12__.NODE_TYPES.IF_ELSE) {
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
  function stepCount(node) {
    if (node.type !== _constants_js__WEBPACK_IMPORTED_MODULE_12__.NODE_TYPES.IF_ELSE) {
      return 0;
    }
    var count = function count(list) {
      return list.reduce(function (total, child) {
        return total + 1 + stepCount(child);
      }, 0);
    };
    return count(node.yes || []) + count(node.no || []);
  }
  function deleteNode(node, alreadyConfirmed) {
    if (readOnly) {
      return;
    }
    var inner = stepCount(node);
    if (!alreadyConfirmed || inner > 0) {
      var message = inner > 0 ? "Delete this If / Else and the ".concat(inner, " step").concat(inner === 1 ? '' : 's', " inside it?") : 'Delete this step?';
      if (!window.confirm(message)) {
        return;
      }
    }
    var list = findContainingList(node, doc.root.next || []);
    if (list) {
      if (selectedKey === node.key) {
        drawer.close();
      }
      (0,_document_model_js__WEBPACK_IMPORTED_MODULE_10__.removeFrom)(list, node);
      onDocumentChanged();
    }
  }
  function onDocumentChanged() {
    history.push(doc);
    if (path) {
      // A test result describes the document it ran on, not this one.
      path = null;
    }
    rerender();
    autosave.schedule(doc);
  }
  function requestAdd(anchorEl, list, index, depth) {
    var trailing = index === list.length;
    var canBranch = !(data.limits && depth >= data.limits.maxBranchDepth);
    var types = _constants_js__WEBPACK_IMPORTED_MODULE_12__.INSERTABLE_TYPES.filter(function (type) {
      if (type === _constants_js__WEBPACK_IMPORTED_MODULE_12__.NODE_TYPES.IF_ELSE) {
        return canBranch;
      }

      // End closes a path, so it is only offered where nothing follows.
      if (type === _constants_js__WEBPACK_IMPORTED_MODULE_12__.NODE_TYPES.END) {
        return trailing;
      }
      return true;
    });
    var hints = {};
    if (!trailing && canBranch) {
      hints[_constants_js__WEBPACK_IMPORTED_MODULE_12__.NODE_TYPES.IF_ELSE] = 'The steps below move into its Yes path.';
    }
    picker.open(anchorEl, {
      types: types,
      hints: hints,
      capabilities: data.capabilities || {},
      note: canBranch ? '' : 'If / Else can’t be nested any deeper here.',
      onPick: function onPick(type) {
        var node = (0,_document_model_js__WEBPACK_IMPORTED_MODULE_10__.newNode)(type, (0,_constants_js__WEBPACK_IMPORTED_MODULE_12__.defaultConfigFor)(type));
        if (type === _constants_js__WEBPACK_IMPORTED_MODULE_12__.NODE_TYPES.IF_ELSE && !trailing) {
          (0,_document_model_js__WEBPACK_IMPORTED_MODULE_10__.insertBranchAt)(list, index, node);
        } else {
          (0,_document_model_js__WEBPACK_IMPORTED_MODULE_10__.insertAt)(list, index, node);
        }
        onDocumentChanged();
        selectNode(node);
      }
    });
  }
  function rerender() {
    var nodeCount = (0,_document_model_js__WEBPACK_IMPORTED_MODULE_10__.countNodes)(doc);
    (0,_canvas_renderer_js__WEBPACK_IMPORTED_MODULE_0__.renderCanvas)(doc, canvasRootEl, {
      onSelect: selectNode,
      onRequestAdd: requestAdd,
      onDelete: function onDelete(node) {
        deleteNode(node, false);
      },
      onMove: function onMove(node, list, direction) {
        if ((0,_document_model_js__WEBPACK_IMPORTED_MODULE_10__.moveWithin)(list, node, direction)) {
          onDocumentChanged();
        }
      }
    }, {
      errors: errors,
      nodeCount: nodeCount,
      limits: data.limits,
      selectedKey: selectedKey,
      readOnly: readOnly,
      catalogs: data.catalogs,
      path: path
    });
    (0,_validation_js__WEBPACK_IMPORTED_MODULE_11__.renderDocumentBanner)(bannerEl, errors, 'This workflow has :count issue(s) to fix before it can publish.');
    undoButton.disabled = readOnly || !history.canUndo();
    redoButton.disabled = readOnly || !history.canRedo();
    var blocked = (0,_validation_js__WEBPACK_IMPORTED_MODULE_11__.hasErrors)(errors);
    publishButton.disabled = blocked;
    publishButton.title = blocked ? "Fix ".concat((0,_validation_js__WEBPACK_IMPORTED_MODULE_11__.countIssues)(errors), " issue").concat((0,_validation_js__WEBPACK_IMPORTED_MODULE_11__.countIssues)(errors) === 1 ? '' : 's', " before publishing") : '';
  }

  // ---------------------------------------------------------------
  // Toolbar actions
  // ---------------------------------------------------------------

  function restore(restored) {
    if (!restored) {
      return;
    }
    drawer.close();
    doc = restored;
    path = null;
    rerender();
    autosave.schedule(doc);
  }
  undoButton.addEventListener('click', function () {
    return restore(history.undo());
  });
  redoButton.addEventListener('click', function () {
    return restore(history.redo());
  });
  publishButton.addEventListener('click', /*#__PURE__*/_asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
    var result;
    return _regenerator().w(function (_context) {
      while (1) switch (_context.n) {
        case 0:
          publishButton.disabled = true;
          showNotice('Publishing…', 'info');
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
          showNotice('Fix the highlighted steps before publishing.', 'danger');
          rerender();
          return _context.a(2);
        case 3:
          if (!(result.status >= 200 && result.status < 300)) {
            _context.n = 4;
            break;
          }
          // A publish turns this draft into the live version; reloading opens
          // the fresh draft the next edit saves against.
          showNotice('Published. Contacts who match the trigger now enter this workflow.', 'success');
          window.location.reload();
          return _context.a(2);
        case 4:
          showNotice(result.body && result.body.message || 'Publishing didn’t work. Try again.', 'danger');
          rerender();
        case 5:
          return _context.a(2);
      }
    }, _callee);
  })));
  function changeStatus(_x, _x2, _x3) {
    return _changeStatus.apply(this, arguments);
  }
  function _changeStatus() {
    _changeStatus = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee2(button, call, done) {
      var result;
      return _regenerator().w(function (_context2) {
        while (1) switch (_context2.n) {
          case 0:
            button.disabled = true;
            _context2.n = 1;
            return call(data.basePath);
          case 1:
            result = _context2.v;
            button.disabled = false;
            if (!(result.status >= 200 && result.status < 300 && result.body && result.body.workflow)) {
              _context2.n = 2;
              break;
            }
            status = result.body.workflow.status;
            renderStatus();
            showNotice(done, 'success');
            return _context2.a(2);
          case 2:
            showNotice(result.body && result.body.message || 'That didn’t work. Try again.', 'danger');
          case 3:
            return _context2.a(2);
        }
      }, _callee2);
    }));
    return _changeStatus.apply(this, arguments);
  }
  pauseButton.addEventListener('click', function () {
    if (window.confirm('Pause this workflow? No new contacts enter, and contacts already in it wait where they are until you resume.')) {
      changeStatus(pauseButton, api.pause, 'Paused. Contacts already in the workflow wait until you resume it.');
    }
  });
  resumeButton.addEventListener('click', function () {
    changeStatus(resumeButton, api.resume, 'Resumed. The workflow is live again.');
  });
  testButton.addEventListener('click', function () {
    drawer.close();
    selectedKey = null;
    testPanel.open();
    syncPanels();
  });

  // ---------------------------------------------------------------
  // Keyboard: ↑/↓ between steps, Enter opens, Delete removes, Esc closes
  // ---------------------------------------------------------------

  canvasRootEl.addEventListener('keydown', function (event) {
    if (!['ArrowDown', 'ArrowUp'].includes(event.key) || !event.target.matches('[data-role="wf-step-card"]')) {
      return;
    }
    var cards = _toConsumableArray(canvasRootEl.querySelectorAll('[data-role="wf-step-card"]'));
    var index = cards.indexOf(event.target);
    var next = cards[index + (event.key === 'ArrowDown' ? 1 : -1)];
    if (next) {
      event.preventDefault();
      next.focus();
      next.scrollIntoView({
        block: 'nearest',
        inline: 'nearest'
      });
    }
  });
  renderStatus();
  rerender();
  if (readOnly) {
    showNotice('This workflow is archived. You can review it, but not change it.', 'info');
  }
}
function initChooser(root) {
  var scratchCard = root.querySelector('[data-role="wf-chooser-scratch"]');
  var recipesContainer = root.querySelector('[data-role="wf-chooser-recipes"]');
  var nameInput = root.querySelector('[data-role="wf-chooser-name"]');
  var errorEl = root.querySelector('[data-role="wf-chooser-error"]');
  var createUrl = root.dataset.createUrl;
  var labelsEl = document.getElementById('wf-recipe-labels');
  var labels = labelsEl ? JSON.parse(labelsEl.textContent) : {};
  var capabilitiesEl = document.getElementById('wf-capabilities');
  var capabilities = capabilitiesEl ? JSON.parse(capabilitiesEl.textContent) : {};
  var unavailablePrefix = capabilitiesEl ? capabilitiesEl.dataset.unavailablePrefix || '' : '';
  var api = (0,_api_js__WEBPACK_IMPORTED_MODULE_5__.createApiClient)(createUrl);
  var busy = false;
  function showError(message) {
    if (errorEl) {
      errorEl.textContent = message || '';
      errorEl.hidden = !message;
    }
  }

  // §20.2 — creating a workflow takes a name and a trigger type, nothing
  // else. A recipe is then only a starting document: it is saved into the new
  // workflow's draft through the same autosave any edit uses.
  function createWorkflow(_x4, _x5) {
    return _createWorkflow.apply(this, arguments);
  }
  function _createWorkflow() {
    _createWorkflow = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee3(fallbackName, definition) {
      var name, triggerType, created, redirect, firstError, opened;
      return _regenerator().w(function (_context3) {
        while (1) switch (_context3.p = _context3.n) {
          case 0:
            if (!busy) {
              _context3.n = 1;
              break;
            }
            return _context3.a(2);
          case 1:
            name = nameInput && nameInput.value.trim() || fallbackName;
            triggerType = definition && definition.root.config.trigger_type || 'contact_created';
            busy = true;
            showError('');
            root.classList.add('is-busy');
            _context3.p = 2;
            _context3.n = 3;
            return api.create({
              name: name,
              trigger_type: triggerType
            });
          case 3:
            created = _context3.v;
            redirect = created.body && created.body.redirect;
            if (!(created.status !== 201 || !redirect)) {
              _context3.n = 4;
              break;
            }
            firstError = created.body && created.body.errors ? Object.values(created.body.errors)[0][0] : null;
            showError(firstError || created.body && created.body.message || 'The workflow couldn’t be created. Try again.');
            return _context3.a(2);
          case 4:
            if (!definition) {
              _context3.n = 6;
              break;
            }
            _context3.n = 5;
            return api.openDraft(redirect);
          case 5:
            opened = _context3.v;
            if (!(opened.body && typeof opened.body.revision === 'number')) {
              _context3.n = 6;
              break;
            }
            _context3.n = 6;
            return api.saveDraft(redirect, definition, opened.body.revision);
          case 6:
            window.location.href = redirect;
          case 7:
            _context3.p = 7;
            busy = false;
            root.classList.remove('is-busy');
            return _context3.f(7);
          case 8:
            return _context3.a(2);
        }
      }, _callee3, null, [[2,, 7, 8]]);
    }));
    return _createWorkflow.apply(this, arguments);
  }
  scratchCard.addEventListener('click', function () {
    return createWorkflow('Untitled workflow', null);
  });
  scratchCard.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      createWorkflow('Untitled workflow', null);
    }
  });
  (0,_recipes_js__WEBPACK_IMPORTED_MODULE_9__.listRecipes)().forEach(function (recipe) {
    var col = document.createElement('div');
    col.className = 'col-12 col-md-6 col-xl-3';
    var card = document.createElement('button');
    card.type = 'button';
    card.className = 'wf-chooser-card';
    card.dataset.role = 'wf-chooser-recipe';
    card.dataset.recipeKey = recipe.key;
    var labelSet = labels[recipe.key] || {};
    var title = document.createElement('span');
    title.className = 'wf-chooser-card__title';
    title.textContent = labelSet.title || recipe.titleKey;
    var description = document.createElement('span');
    description.className = 'wf-chooser-card__description';
    description.textContent = labelSet.description || recipe.descriptionKey;
    card.appendChild(title);
    card.appendChild(description);
    col.appendChild(card);
    recipesContainer.appendChild(col);

    // A recipe the account cannot run is shown, disabled, with the reason — never
    // created and then refused at publish.
    var missing = (recipe.requires || []).map(function (key) {
      return capabilities[key];
    }).filter(function (entry) {
      return entry && entry.available === false;
    });
    if (missing.length > 0) {
      card.disabled = true;
      card.classList.add('is-disabled');
      card.dataset.unavailable = 'true';
      var reason = document.createElement('span');
      reason.className = 'wf-chooser-card__reason';
      reason.textContent = "".concat(unavailablePrefix).concat(missing[0].reason || '');
      card.appendChild(reason);
      return;
    }
    card.addEventListener('click', function () {
      createWorkflow(labelSet.title || 'Untitled workflow', recipe.build());
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