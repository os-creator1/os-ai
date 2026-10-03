/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./resources/js/documents/editor/api.js"
/*!**********************************************!*\
  !*** ./resources/js/documents/editor/api.js ***!
  \**********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createApi: () => (/* binding */ createApi),
/* harmony export */   errorMessage: () => (/* binding */ errorMessage)
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
// Contract 17B — every request the editor makes. All document MUTATIONS go
// through one promise queue so two saves / line operations in the same tab can
// never race, and each one reads store.lock at the moment it actually runs
// (the previous response has already updated it). Reads (search, dates) bypass
// the queue.
//
// Answers follow the controller's contract:
//   200 {status:'ok', lock_version, ...}   409 {status:'conflict', lock_version}
//   422 {status:'invalid', message, errors}   404 {status:'error'}

function csrfToken() {
  var meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') : '';
}
function createApi(store) {
  var chain = Promise.resolve();
  var pending = 0;
  function send(_x, _x2, _x3) {
    return _send.apply(this, arguments);
  }
  function _send() {
    _send = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee2(method, url, body) {
      var options, response, json, _t, _t2;
      return _regenerator().w(function (_context2) {
        while (1) switch (_context2.p = _context2.n) {
          case 0:
            options = {
              method: method,
              credentials: 'same-origin',
              headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest'
              }
            };
            if (body !== undefined) {
              options.headers['Content-Type'] = 'application/json';
              options.body = JSON.stringify(body);
            }
            _context2.p = 1;
            _context2.n = 2;
            return fetch(url, options);
          case 2:
            response = _context2.v;
            _context2.n = 4;
            break;
          case 3:
            _context2.p = 3;
            _t = _context2.v;
            return _context2.a(2, {
              ok: false,
              status: 0,
              network: true,
              json: {
                status: 'error',
                message: 'You appear to be offline.'
              }
            });
          case 4:
            json = null;
            _context2.p = 5;
            _context2.n = 6;
            return response.json();
          case 6:
            json = _context2.v;
            _context2.n = 8;
            break;
          case 7:
            _context2.p = 7;
            _t2 = _context2.v;
            json = {
              status: 'error',
              message: response.status === 419 ? 'Your session expired. Reload the page to continue.' : 'Something went wrong.'
            };
          case 8:
            return _context2.a(2, {
              ok: response.ok && json && json.status === 'ok',
              status: response.status,
              json: json || {}
            });
        }
      }, _callee2, null, [[5, 7], [1, 3]]);
    }));
    return _send.apply(this, arguments);
  }
  var api = {
    /** A read: not queued, no lock. */get: function get(url) {
      return send('GET', url);
    },
    /** A write that is not a document mutation (e.g. create a catalog item). */post: function post(url, body) {
      return send('POST', url, body);
    },
    /**
     * A document mutation. `build` may be a body object or a function
     * returning one; it runs when its turn comes. `expected_lock_version`
     * is added from the store. On 200 the new lock version is adopted; on
     * 409 the whole editor flips to the conflict state (never retried, never
     * overwritten).
     */
    mutate: function mutate(method, url, build) {
      pending += 1;
      var run = /*#__PURE__*/function () {
        var _ref = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
          var body, result;
          return _regenerator().w(function (_context) {
            while (1) switch (_context.n) {
              case 0:
                if (!(store.save.state === 'conflict')) {
                  _context.n = 1;
                  break;
                }
                return _context.a(2, {
                  ok: false,
                  status: 409,
                  json: {
                    status: 'conflict'
                  },
                  skipped: true
                });
              case 1:
                body = typeof build === 'function' ? build() : build;
                if (!(body === null)) {
                  _context.n = 2;
                  break;
                }
                return _context.a(2, {
                  ok: true,
                  status: 200,
                  json: {},
                  skipped: true
                });
              case 2:
                _context.n = 3;
                return send(method, url, _objectSpread(_objectSpread({}, body || {}), {}, {
                  expected_lock_version: store.lock
                }));
              case 3:
                result = _context.v;
                if (result.status === 409) {
                  store.setSave('conflict', 'This document was changed in another tab.');
                  store.emit('conflict');
                } else if (result.ok && result.json.lock_version !== undefined && result.json.lock_version !== null) {
                  store.lock = result.json.lock_version;
                }
                return _context.a(2, result);
            }
          }, _callee);
        }));
        return function run() {
          return _ref.apply(this, arguments);
        };
      }();
      var task = chain.then(run, run);
      chain = task.then(function () {
        pending -= 1;
      }, function () {
        pending -= 1;
      });
      return task;
    },
    /** Resolves once every queued mutation has finished. */idle: function idle() {
      return chain;
    },
    busy: function busy() {
      return pending > 0;
    }
  };
  return api;
}

/** The first human-readable message in an error answer. */
function errorMessage(result, fallback) {
  var json = result && result.json || {};
  if (json.errors && _typeof(json.errors) === 'object') {
    var keys = Object.keys(json.errors);
    if (keys.length > 0) {
      var first = json.errors[keys[0]];
      return Array.isArray(first) ? first[0] : String(first);
    }
  }
  return json.message || fallback || 'Something went wrong.';
}

/***/ },

/***/ "./resources/js/documents/editor/autosave.js"
/*!***************************************************!*\
  !*** ./resources/js/documents/editor/autosave.js ***!
  \***************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createAutosave: () => (/* binding */ createAutosave)
/* harmony export */ });
/* harmony import */ var _api_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./api.js */ "./resources/js/documents/editor/api.js");
/* harmony import */ var _blocks_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./blocks.js */ "./resources/js/documents/editor/blocks.js");
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
// Contract 17B — debounced autosave of blocks + title.
//
// markDirty() schedules one save ~800ms after the LAST edit. The save is queued
// behind any in-flight request, serialises the CURRENT blocks when its turn
// comes (so it is never stale) and uses the latest lock version. A 409 stops
// everything (api.js sets the conflict state); a 422 shows the field message and
// waits for the next edit; a network failure retries on its own.



var DELAY = 800;
var RETRY = 5000;
function createAutosave(store, api) {
  var timer = null;
  var retryTimer = null;
  function schedule(wait) {
    clearTimeout(timer);
    timer = setTimeout(save, wait);
  }
  function save() {
    return _save.apply(this, arguments);
  }
  function _save() {
    _save = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee2() {
      var result;
      return _regenerator().w(function (_context2) {
        while (1) switch (_context2.n) {
          case 0:
            clearTimeout(timer);
            clearTimeout(retryTimer);
            if (!(!store.editable || store.save.state === 'conflict' || !store.dirty)) {
              _context2.n = 1;
              break;
            }
            return _context2.a(2);
          case 1:
            _context2.n = 2;
            return api.mutate('PUT', store.urls.blocks, function () {
              if (!store.dirty) {
                return null; // a flush already saved this
              }
              store.dirty = false;
              store.setSave('saving');

              // A template saves its name and type beside the blocks; a document saves its title.
              return store.isTemplate ? {
                blocks: (0,_blocks_js__WEBPACK_IMPORTED_MODULE_1__.savePayload)(store.blocks),
                name: store.title,
                template_type: store.templateType
              } : {
                blocks: (0,_blocks_js__WEBPACK_IMPORTED_MODULE_1__.savePayload)(store.blocks),
                title: store.title
              };
            });
          case 2:
            result = _context2.v;
            if (!result.skipped) {
              _context2.n = 3;
              break;
            }
            if (store.save.state === 'saving') {
              store.setSave(store.dirty ? 'dirty' : 'saved');
            }
            return _context2.a(2);
          case 3:
            if (!result.ok) {
              _context2.n = 4;
              break;
            }
            // An edit made while this request was in flight is still pending.
            store.setSave(store.dirty ? 'dirty' : 'saved');
            if (store.dirty) {
              schedule(DELAY);
            }
            return _context2.a(2);
          case 4:
            if (!(result.status === 409)) {
              _context2.n = 5;
              break;
            }
            return _context2.a(2);
          case 5:
            store.dirty = true;
            if (result.network) {
              store.setSave('error', 'Could not save. Retrying...');
              retryTimer = setTimeout(save, RETRY);
            } else {
              store.setSave('error', (0,_api_js__WEBPACK_IMPORTED_MODULE_0__.errorMessage)(result, 'This change could not be saved.'));
            }
          case 6:
            return _context2.a(2);
        }
      }, _callee2);
    }));
    return _save.apply(this, arguments);
  }
  return {
    markDirty: function markDirty() {
      if (!store.editable || store.save.state === 'conflict') {
        return;
      }
      store.dirty = true;
      if (store.save.state !== 'saving') {
        store.setSave('dirty');
      }
      schedule(DELAY);
    },
    /** Save now (Save button, Preview, Send); resolves when nothing is pending. */flush: function flush() {
      return _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
        return _regenerator().w(function (_context) {
          while (1) switch (_context.n) {
            case 0:
              if (!(store.dirty && store.save.state !== 'conflict')) {
                _context.n = 1;
                break;
              }
              _context.n = 1;
              return save();
            case 1:
              _context.n = 2;
              return api.idle();
            case 2:
              if (!(store.dirty && store.save.state !== 'conflict' && store.save.state !== 'error')) {
                _context.n = 4;
                break;
              }
              _context.n = 3;
              return save();
            case 3:
              _context.n = 4;
              return api.idle();
            case 4:
              return _context.a(2, store.save.state === 'saved');
          }
        }, _callee);
      }))();
    },
    cancel: function cancel() {
      clearTimeout(timer);
      clearTimeout(retryTimer);
    }
  };
}

/***/ },

/***/ "./resources/js/documents/editor/blocks.js"
/*!*************************************************!*\
  !*** ./resources/js/documents/editor/blocks.js ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   SINGLE: () => (/* binding */ SINGLE),
/* harmony export */   TYPE_LABELS: () => (/* binding */ TYPE_LABELS),
/* harmony export */   cloneBlock: () => (/* binding */ cloneBlock),
/* harmony export */   isSavable: () => (/* binding */ isSavable),
/* harmony export */   newBlock: () => (/* binding */ newBlock),
/* harmony export */   savePayload: () => (/* binding */ savePayload)
/* harmony export */ });
/* harmony import */ var _dom_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./dom.js */ "./resources/js/documents/editor/dom.js");
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
// Contract 17B — block factories and the few rules about blocks the UI itself
// has to know. BlockSchema (server) remains the only authority; these produce
// shapes it accepts.


var SINGLE = ['product_list', 'signature'];
var FACTORIES = {
  text: function text() {
    return {
      type: 'text',
      data: {
        align: 'left',
        runs: []
      }
    };
  },
  heading: function heading() {
    return {
      type: 'heading',
      data: {
        level: 2,
        align: 'left',
        runs: []
      }
    };
  },
  image: function image() {
    return {
      type: 'image',
      data: {
        catalog_image_uid: '',
        alt: '',
        width_pct: 100
      }
    };
  },
  divider: function divider() {
    return {
      type: 'divider',
      data: {}
    };
  },
  spacer: function spacer() {
    return {
      type: 'spacer',
      data: {
        height: 24
      }
    };
  },
  page_break: function page_break() {
    return {
      type: 'page_break',
      data: {}
    };
  },
  section: function section() {
    return {
      type: 'section',
      data: {
        title: ''
      }
    };
  },
  business_details: function business_details() {
    return {
      type: 'business_details',
      data: {
        show: ['name', 'phone', 'email', 'website']
      }
    };
  },
  product: function product() {
    return {
      type: 'product_list',
      data: {
        show_description: true,
        show_quantity: true
      }
    };
  },
  custom_line: function custom_line() {
    return {
      type: 'product_list',
      data: {
        show_description: true,
        show_quantity: true
      }
    };
  },
  payment_terms: function payment_terms() {
    return {
      type: 'payment_terms',
      data: {}
    };
  },
  signature: function signature() {
    return {
      type: 'signature',
      data: {
        label: 'Signature'
      }
    };
  },
  contact_name: function contact_name() {
    return {
      type: 'text',
      data: {
        align: 'left',
        runs: [{
          merge: 'contact.full_name'
        }]
      }
    };
  },
  contact_email: function contact_email() {
    return {
      type: 'text',
      data: {
        align: 'left',
        runs: [{
          merge: 'contact.email'
        }]
      }
    };
  }
};
function newBlock(toolId) {
  var make = FACTORIES[toolId];
  return make ? _objectSpread({
    id: (0,_dom_js__WEBPACK_IMPORTED_MODULE_0__.uuid)()
  }, make()) : null;
}
function cloneBlock(block) {
  return {
    id: (0,_dom_js__WEBPACK_IMPORTED_MODULE_0__.uuid)(),
    type: block.type,
    data: JSON.parse(JSON.stringify(block.data || {}))
  };
}

/**
 * An image block with no image chosen yet cannot be saved (the server requires
 * one of the Business's catalog images). It stays on the canvas as a
 * placeholder and is simply left out of the save until an image is picked.
 */
function isSavable(block) {
  return !(block.type === 'image' && !(block.data && block.data.catalog_image_uid));
}
function savePayload(blocks) {
  return blocks.filter(isSavable);
}
var TYPE_LABELS = {
  text: 'Text',
  heading: 'Heading',
  image: 'Image',
  divider: 'Divider',
  spacer: 'Spacer',
  page_break: 'Page break',
  section: 'Section',
  business_details: 'Business details',
  product_list: 'Pricing',
  payment_terms: 'Payment terms',
  signature: 'Signature'
};

/***/ },

/***/ "./resources/js/documents/editor/canvas.js"
/*!*************************************************!*\
  !*** ./resources/js/documents/editor/canvas.js ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   DRAG_TYPES: () => (/* binding */ DRAG_TYPES),
/* harmony export */   createCanvas: () => (/* binding */ createCanvas)
/* harmony export */ });
/* harmony import */ var _dom__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./dom */ "./resources/js/documents/editor/dom.js");
/* harmony import */ var _serializer__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./serializer */ "./resources/js/documents/editor/serializer.js");
/* harmony import */ var _blocks__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./blocks */ "./resources/js/documents/editor/blocks.js");
/* harmony import */ var _product_block__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./product-block */ "./resources/js/documents/editor/product-block.js");
// Contract 17B — the printable page canvas.
//
// A structured vertical flow of block wrappers (never absolute positioning).
// Each wrapper carries a small floating action bar (drag handle, up, down,
// duplicate, delete) that only shows on hover / selection. Text and headings
// are contenteditable; everything else is drawn the way the server renderer
// draws it (same doc-* classes) so Preview and the recipient's page match.
//
// Drag and drop is native HTML5: blocks from the toolbox (data type
// text/x-de-tool) and existing blocks by their handle (text/x-de-block), with
// one visible drop indicator between blocks. The up/down buttons and
// Alt+Arrow keys are the non-drag path.





var TOOL = 'text/x-de-tool';
var BLOCK = 'text/x-de-block';
function createCanvas(ctx) {
  var store = ctx.store,
    actions = ctx.actions,
    host = ctx.host,
    scroller = ctx.scroller;
  var editable = function editable() {
    return store.editable;
  };
  var indicator = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-drop',
    'data-role': 'drop-indicator',
    hidden: true,
    'aria-hidden': 'true'
  });

  // ---- rendering -----------------------------------------------------------

  function render() {
    ctx.inline.detach();
    while (host.firstChild) {
      host.removeChild(host.firstChild);
    }
    if (store.blocks.length === 0) {
      host.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
        "class": 'de-empty',
        'data-role': 'canvas-empty'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('strong', {
        text: editable() ? 'Start building your document' : 'This document is empty'
      }), editable() ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('p', {
        text: 'Drag a block from the left, or click one to add it here.'
      }) : null]));
    }
    store.blocks.forEach(function (block) {
      return host.appendChild(wrapperFor(block));
    });
    host.appendChild(indicator);
  }
  function wrapperFor(block) {
    var wrapper = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-block doc-block doc-block-' + block.type + (store.selectedId === block.id ? ' is-selected' : ''),
      'data-block-id': block.id,
      'data-block-type': block.type,
      tabindex: '0',
      'aria-label': _blocks__WEBPACK_IMPORTED_MODULE_2__.TYPE_LABELS[block.type] || block.type
    });
    wrapper.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-block__body'
    }, [content(block, wrapper)]));
    if (editable()) {
      wrapper.appendChild(actionBar(block));
    }
    wrapper.addEventListener('mousedown', function () {
      return select(block.id);
    });
    wrapper.addEventListener('focus', function (event) {
      if (event.target === wrapper) {
        select(block.id);
      }
    });
    wrapper.addEventListener('keydown', function (event) {
      if (!editable() || event.target !== wrapper) {
        return;
      }
      if (event.altKey && event.key === 'ArrowUp') {
        event.preventDefault();
        actions.moveBy(block.id, -1);
      } else if (event.altKey && event.key === 'ArrowDown') {
        event.preventDefault();
        actions.moveBy(block.id, 1);
      }
    });
    return wrapper;
  }
  function actionBar(block) {
    var single = _blocks__WEBPACK_IMPORTED_MODULE_2__.SINGLE.indexOf(block.type) !== -1;
    var index = store.indexOf(block.id);
    var btn = function btn(name, label, fn, extra) {
      return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
        type: 'button',
        "class": 'de-mini-btn' + (extra ? ' ' + extra : ''),
        title: label,
        'aria-label': label,
        onmousedown: function onmousedown(event) {
          return event.stopPropagation();
        },
        onclick: function onclick(event) {
          event.stopPropagation();
          fn();
        }
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)(name)]);
    };
    var handle = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      "class": 'de-handle',
      draggable: 'true',
      title: 'Drag to reorder',
      role: 'img',
      'aria-label': 'Drag to reorder'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)('grip-vertical')]);
    handle.addEventListener('dragstart', function (event) {
      ctx.drag = {
        kind: 'block',
        id: block.id
      };
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData(BLOCK, block.id);
      event.dataTransfer.setData('text/plain', block.id);
      var node = host.querySelector('[data-block-id="' + block.id + '"]');
      if (node && event.dataTransfer.setDragImage) {
        event.dataTransfer.setDragImage(node, 16, 16);
      }
      if (node) {
        node.classList.add('is-dragging');
      }
    });
    handle.addEventListener('dragend', function () {
      ctx.drag = null;
      hideIndicator();
      var node = host.querySelector('.is-dragging');
      if (node) {
        node.classList.remove('is-dragging');
      }
    });
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-block__bar',
      role: 'toolbar',
      'aria-label': 'Block actions'
    }, [handle, index > 0 ? btn('chevron-up', 'Move up', function () {
      return actions.moveBy(block.id, -1);
    }) : null, index < store.blocks.length - 1 ? btn('chevron-down', 'Move down', function () {
      return actions.moveBy(block.id, 1);
    }) : null, single ? null : btn('copy', 'Duplicate', function () {
      return actions.duplicate(block.id);
    }), btn('trash-2', 'Delete', function () {
      return actions.remove(block.id);
    }, 'de-mini-btn--danger')]);
  }
  function content(block, wrapper) {
    var data = block.data || {};
    var ops = ctx.productOps;
    switch (block.type) {
      case 'heading':
      case 'text':
        return textBlock(block, wrapper);
      case 'section':
        return sectionBlock(block);
      case 'divider':
        return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('hr', {
          "class": 'doc-divider'
        });
      case 'spacer':
        return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
          "class": 'de-spacer',
          style: 'height:' + Math.max(8, Math.min(120, parseInt(data.height, 10) || 24)) + 'px',
          title: 'Spacer',
          'aria-label': 'Spacer'
        });
      case 'page_break':
        return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
          "class": 'de-page-break',
          'data-role': 'page-break'
        }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
          text: 'Page break'
        }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
          text: 'A new page starts here when printed'
        })]);
      case 'image':
        return imageBlock(block);
      case 'business_details':
        return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
          "class": 'doc-details'
        }, (data.show || []).map(function (field) {
          return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', null, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
            "class": 'doc-merge',
            title: 'Filled in from your business details',
            text: field === 'name' && store.business.name ? store.business.name : 'Business ' + field
          })]);
        }));
      case 'product_list':
        return (0,_product_block__WEBPACK_IMPORTED_MODULE_3__.renderProductBlock)(block, {
          store: store,
          ops: ops
        });
      case 'payment_terms':
        return (0,_product_block__WEBPACK_IMPORTED_MODULE_3__.renderPaymentTerms)(block, {
          store: store,
          ops: ops
        });
      case 'signature':
        return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
          "class": 'doc-placeholder',
          'data-role': 'signature-placeholder'
        }, [(data.label || 'Signature') + ' — the signer types their name here.']);
      default:
        return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
          "class": 'doc-placeholder',
          text: block.type
        });
    }
  }
  function imageBlock(block) {
    var data = block.data || {};
    var image = store.images.find(function (candidate) {
      return candidate.uid === data.catalog_image_uid;
    });
    if (image) {
      return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('img', {
        "class": 'doc-image',
        src: image.url,
        alt: data.alt || '',
        style: 'width:' + Math.max(10, Math.min(100, parseInt(data.width_pct, 10) || 100)) + '%'
      });
    }
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'doc-placeholder de-image-empty',
      'data-role': 'image-placeholder'
    }, [data.catalog_image_uid ? 'This image is no longer available.' : store.images.length === 0 ? 'Add a picture to one of your packages or products first, then choose it here.' : 'Choose one of your catalog images in the panel on the right.']);
  }
  function sectionBlock(block) {
    var node = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'doc-section',
      contenteditable: editable() ? 'true' : 'false',
      'data-placeholder': 'Section title',
      spellcheck: 'true',
      role: 'textbox',
      'aria-label': 'Section title'
    });
    node.textContent = block.data && block.data.title || '';
    node.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
      }
    });
    node.addEventListener('paste', function (event) {
      return pastePlain(event);
    });
    node.addEventListener('input', function () {
      block.data.title = node.textContent.replace(/\s+/g, ' ').trim().slice(0, 200);
      ctx.autosave.markDirty();
    });
    return node;
  }
  function textBlock(block, wrapper) {
    var data = block.data || {};
    var isHeading = block.type === 'heading';
    var level = Math.max(1, Math.min(3, parseInt(data.level, 10) || 2));
    var align = ['left', 'center', 'right'].indexOf(data.align) !== -1 ? data.align : 'left';
    var node = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)(isHeading ? 'h' + level : 'p', {
      "class": (isHeading ? '' : 'doc-text ') + 'doc-align-' + align + ' de-editable',
      contenteditable: editable() ? 'true' : 'false',
      'data-placeholder': isHeading ? 'Heading' : 'Type your text, or insert a merge field',
      spellcheck: 'true',
      role: 'textbox',
      'aria-multiline': 'true',
      'aria-label': isHeading ? 'Heading text' : 'Text'
    });
    (0,_serializer__WEBPACK_IMPORTED_MODULE_1__.runsToDom)(document, node, data.runs || [], function (token) {
      return store.mergePreview(token) || store.mergeLabel(token);
    }, function (token) {
      return 'Merge field: ' + store.mergeLabel(token);
    });
    if (!editable()) {
      return node;
    }
    node.addEventListener('focus', function () {
      select(block.id);
      ctx.inline.attach(node, block, wrapper);
    });
    node.addEventListener('input', function () {
      if (node.textContent === '' && !node.querySelector('[data-token]')) {
        while (node.firstChild) {
          node.removeChild(node.firstChild);
        }
      }
      block.data.runs = (0,_serializer__WEBPACK_IMPORTED_MODULE_1__.domToRuns)(node);
      ctx.autosave.markDirty();
    });
    node.addEventListener('paste', function (event) {
      return pastePlain(event);
    });
    node.addEventListener('drop', function (event) {
      var types = Array.prototype.slice.call(event.dataTransfer && event.dataTransfer.types || []);
      if (types.indexOf(TOOL) === -1 && types.indexOf(BLOCK) === -1) {
        event.preventDefault(); // never accept dropped rich text / files
      }
    });
    node.addEventListener('click', function (event) {
      if (event.target.closest && event.target.closest('a')) {
        event.preventDefault();
      }
    });
    node.addEventListener('keydown', function (event) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        ctx.inline.openLink();
        return;
      }
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        if (caretAtEnd(node)) {
          actions.insertAfter(block.id, 'text');
        } else {
          document.execCommand('insertLineBreak');
        }
      } else if (event.key === 'Enter' && event.shiftKey) {
        event.preventDefault();
        document.execCommand('insertLineBreak');
      }
    });
    return node;
  }
  function caretAtEnd(node) {
    var selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || !selection.isCollapsed || !node.contains(selection.anchorNode)) {
      return false;
    }
    var range = document.createRange();
    range.selectNodeContents(node);
    range.setStart(selection.anchorNode, selection.anchorOffset);
    return range.toString().replace(/​/g, '') === '' && !range.cloneContents().querySelector('[data-token]');
  }
  function pastePlain(event) {
    event.preventDefault();
    var clipboard = event.clipboardData || window.clipboardData;
    var text = clipboard ? clipboard.getData('text/plain') : '';
    if (text) {
      document.execCommand('insertText', false, text.replace(/\r\n?/g, '\n'));
    }
  }

  // ---- selection ------------------------------------------------------------

  function select(id) {
    if (store.selectedId === id) {
      return;
    }
    ctx.inline.detach();
    store.selectedId = id;
    Array.prototype.forEach.call(host.querySelectorAll('.de-block'), function (node) {
      node.classList.toggle('is-selected', node.getAttribute('data-block-id') === id);
    });
    store.emit('selection');
  }
  function deselect() {
    if (store.selectedId === null) {
      return;
    }
    ctx.inline.detach();
    store.selectedId = null;
    Array.prototype.forEach.call(host.querySelectorAll('.de-block'), function (node) {
      return node.classList.remove('is-selected');
    });
    store.emit('selection');
  }
  scroller.addEventListener('mousedown', function (event) {
    if (event.target === scroller || event.target === host) {
      deselect();
    }
  });

  /** Redraw one block in place (not text blocks while typing). */
  function renderBlock(id) {
    var block = store.block(id);
    var old = host.querySelector('[data-block-id="' + id + '"]');
    if (!block || !old) {
      return;
    }
    old.replaceWith(wrapperFor(block));
  }
  function renderTypes(types) {
    store.blocks.forEach(function (block) {
      if (types.indexOf(block.type) !== -1) {
        renderBlock(block.id);
      }
    });
  }
  function focusBlock(id, atEnd) {
    var node = host.querySelector('[data-block-id="' + id + '"]');
    if (!node) {
      return;
    }
    var field = node.querySelector('[contenteditable="true"]');
    if (field) {
      field.focus();
      if (atEnd !== false) {
        var range = document.createRange();
        range.selectNodeContents(field);
        range.collapse(false);
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
      }
    } else {
      node.focus({
        preventScroll: true
      });
    }
    node.scrollIntoView({
      block: 'nearest',
      behavior: 'smooth'
    });
  }

  // ---- drag and drop ----------------------------------------------------------

  function dragKind(event) {
    var types = Array.prototype.slice.call(event.dataTransfer && event.dataTransfer.types || []);
    if (types.indexOf(TOOL) !== -1) {
      return 'tool';
    }
    if (types.indexOf(BLOCK) !== -1) {
      return 'block';
    }
    return null;
  }
  function wrappers() {
    return Array.prototype.slice.call(host.querySelectorAll(':scope > .de-block'));
  }
  function indexAt(clientY) {
    var list = wrappers();
    for (var i = 0; i < list.length; i += 1) {
      var rect = list[i].getBoundingClientRect();
      if (clientY < rect.top + rect.height / 2) {
        return i;
      }
    }
    return list.length;
  }
  function showIndicator(index) {
    var list = wrappers();
    var top = 0;
    if (list.length > 0) {
      top = index < list.length ? list[index].offsetTop - 8 : list[list.length - 1].offsetTop + list[list.length - 1].offsetHeight + 4;
    } else {
      top = 48;
    }
    indicator.style.top = top + 'px';
    indicator.hidden = false;
    indicator.setAttribute('data-index', String(index));
  }
  function hideIndicator() {
    indicator.hidden = true;
  }
  host.addEventListener('dragover', function (event) {
    if (!editable()) {
      return;
    }
    var kind = dragKind(event);
    if (!kind) {
      return;
    }
    event.preventDefault();
    event.dataTransfer.dropEffect = kind === 'tool' ? 'copy' : 'move';
    showIndicator(indexAt(event.clientY));
    var rect = scroller.getBoundingClientRect();
    if (event.clientY < rect.top + 60) {
      scroller.scrollTop -= 14;
    } else if (event.clientY > rect.bottom - 60) {
      scroller.scrollTop += 14;
    }
  });
  host.addEventListener('dragleave', function (event) {
    if (!event.relatedTarget || !host.contains(event.relatedTarget)) {
      hideIndicator();
    }
  });
  host.addEventListener('drop', function (event) {
    if (!editable()) {
      return;
    }
    var kind = dragKind(event);
    if (!kind) {
      return;
    }
    event.preventDefault();
    var index = indexAt(event.clientY);
    hideIndicator();
    if (kind === 'tool') {
      actions.addTool(event.dataTransfer.getData(TOOL), index);
    } else {
      var id = event.dataTransfer.getData(BLOCK);
      var from = store.indexOf(id);
      if (from !== -1) {
        actions.moveBlock(id, index > from ? index - 1 : index);
      }
    }
    ctx.drag = null;
  });
  document.addEventListener('dragend', hideIndicator);

  // The structure the canvas shows changes with the commerce payload too.
  store.subscribe(function (topic) {
    if (topic === 'commerce') {
      renderTypes(['product_list', 'payment_terms']);
    }
  });
  return {
    render: render,
    renderBlock: renderBlock,
    renderTypes: renderTypes,
    select: select,
    deselect: deselect,
    focusBlock: focusBlock,
    hideIndicator: hideIndicator
  };
}
var DRAG_TYPES = {
  TOOL: TOOL,
  BLOCK: BLOCK
};

/***/ },

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

/***/ },

/***/ "./resources/js/documents/editor/index.js"
/*!************************************************!*\
  !*** ./resources/js/documents/editor/index.js ***!
  \************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   icon: () => (/* reexport safe */ _dom__WEBPACK_IMPORTED_MODULE_3__.icon),
/* harmony export */   init: () => (/* binding */ init),
/* harmony export */   savePayload: () => (/* reexport safe */ _blocks__WEBPACK_IMPORTED_MODULE_4__.savePayload)
/* harmony export */ });
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./api */ "./resources/js/documents/editor/api.js");
/* harmony import */ var _autosave__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./autosave */ "./resources/js/documents/editor/autosave.js");
/* harmony import */ var _canvas__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./canvas */ "./resources/js/documents/editor/canvas.js");
/* harmony import */ var _dom__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./dom */ "./resources/js/documents/editor/dom.js");
/* harmony import */ var _blocks__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./blocks */ "./resources/js/documents/editor/blocks.js");
/* harmony import */ var _inline_text__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./inline-text */ "./resources/js/documents/editor/inline-text.js");
/* harmony import */ var _inspector__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./inspector */ "./resources/js/documents/editor/inspector.js");
/* harmony import */ var _line_modal__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./line-modal */ "./resources/js/documents/editor/line-modal.js");
/* harmony import */ var _modal__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./modal */ "./resources/js/documents/editor/modal.js");
/* harmony import */ var _product_wizard__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ./product-wizard */ "./resources/js/documents/editor/product-wizard.js");
/* harmony import */ var _save_template__WEBPACK_IMPORTED_MODULE_10__ = __webpack_require__(/*! ./save-template */ "./resources/js/documents/editor/save-template.js");
/* harmony import */ var _send_dialog__WEBPACK_IMPORTED_MODULE_11__ = __webpack_require__(/*! ./send-dialog */ "./resources/js/documents/editor/send-dialog.js");
/* harmony import */ var _state__WEBPACK_IMPORTED_MODULE_12__ = __webpack_require__(/*! ./state */ "./resources/js/documents/editor/state.js");
/* harmony import */ var _toolbox__WEBPACK_IMPORTED_MODULE_13__ = __webpack_require__(/*! ./toolbox */ "./resources/js/documents/editor/toolbox.js");
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function _createForOfIteratorHelper(r, e) { var t = "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (!t) { if (Array.isArray(r) || (t = _unsupportedIterableToArray(r)) || e && r && "number" == typeof r.length) { t && (r = t); var _n = 0, F = function F() {}; return { s: F, n: function n() { return _n >= r.length ? { done: !0 } : { done: !1, value: r[_n++] }; }, e: function e(r) { throw r; }, f: F }; } throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); } var o, a = !0, u = !1; return { s: function s() { t = t.call(r); }, n: function n() { var r = t.next(); return a = r.done, r; }, e: function e(r) { u = !0, o = r; }, f: function f() { try { a || null == t["return"] || t["return"](); } finally { if (u) throw o; } } }; }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
function _slicedToArray(r, e) { return _arrayWithHoles(r) || _iterableToArrayLimit(r, e) || _unsupportedIterableToArray(r, e) || _nonIterableRest(); }
function _nonIterableRest() { throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
function _iterableToArrayLimit(r, l) { var t = null == r ? null : "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (null != t) { var e, n, i, u, a = [], f = !0, o = !1; try { if (i = (t = t.call(r)).next, 0 === l) { if (Object(t) !== t) return; f = !1; } else for (; !(f = (e = i.call(t)).done) && (a.push(e.value), a.length !== l); f = !0); } catch (r) { o = !0, n = r; } finally { try { if (!f && null != t["return"] && (u = t["return"](), Object(u) !== u)) return; } finally { if (o) throw n; } } return a; } }
function _arrayWithHoles(r) { if (Array.isArray(r)) return r; }
// Contract 17B — the Proposal / Contract visual editor, entry point.
//
// Architecture (vanilla ES modules, no framework):
//   state.js        the store: blocks, title, commerce payload, lock version, save state
//   api.js          fetch + ONE promise queue for every document mutation
//   autosave.js     ~800ms debounced PUT editor.blocks, flush for Save / Preview / Send
//   canvas.js       page canvas: block wrappers, selection, native drag and drop
//   inline-text.js  mini toolbar for contenteditable text (serializer.js: DOM <-> runs)
//   inspector.js    contextual right panel
//   toolbox.js      left toolbox (server-rendered buttons) + narrow-screen drawer
//   product-*.js    pricing block drawing, add-product wizard, custom line form
//   send-dialog.js  checklist + Email/SMS send
//
// All truth stays on the server: the editor sends blocks and plan INTENT,
// and renders whatever lines / totals / schedule the server answers with.















var SAVE_TEXT = {
  saved: 'All changes saved',
  dirty: 'Unsaved changes',
  saving: 'Saving...'
};
function init(root) {
  if (!root || root.getAttribute('data-initialized') === '1') {
    return;
  }
  root.setAttribute('data-initialized', '1');
  var bootNode = document.getElementById('document-editor-bootstrap');
  if (!bootNode) {
    return;
  }
  var store = (0,_state__WEBPACK_IMPORTED_MODULE_12__.createStore)(JSON.parse(bootNode.textContent));
  var api = (0,_api__WEBPACK_IMPORTED_MODULE_0__.createApi)(store);
  var banners = root.querySelector('[data-role="editor-banners"]');
  var modalRoot = root.querySelector('[data-role="modal-root"]');

  // ---- toasts ---------------------------------------------------------------

  var toasts = (0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('div', {
    "class": 'de-toasts',
    'aria-live': 'polite'
  });
  root.appendChild(toasts);
  function notify(message, kind, link) {
    var toast = (0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('div', {
      "class": 'de-toast de-toast--' + (kind || 'info'),
      role: 'status'
    }, [message]);
    if (link && link.href) {
      toast.appendChild(document.createTextNode(' '));
      toast.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('a', {
        href: link.href,
        'data-role': 'toast-link',
        text: link.text || 'Open'
      }));
    }
    toasts.appendChild(toast);
    setTimeout(function () {
      return toast.remove();
    }, link ? 9000 : kind === 'error' ? 7000 : 3500);
  }

  // ---- legacy: only the upgrade prompt ----------------------------------------

  if (root.getAttribute('data-legacy') === '1') {
    initLegacy();
    return;
  }
  var autosave = (0,_autosave__WEBPACK_IMPORTED_MODULE_1__.createAutosave)(store, api);
  var canvasHost = root.querySelector('[data-role="editor-canvas"]');
  var ctx = {
    root: root,
    store: store,
    api: api,
    autosave: autosave,
    notify: notify,
    modalRoot: modalRoot,
    host: canvasHost,
    scroller: root.querySelector('.de-canvas-scroll'),
    drag: null,
    actions: {},
    productOps: {}
  };
  ctx.inline = (0,_inline_text__WEBPACK_IMPORTED_MODULE_5__.createInline)(ctx);
  ctx.canvas = (0,_canvas__WEBPACK_IMPORTED_MODULE_2__.createCanvas)(ctx);
  ctx.toolbox = (0,_toolbox__WEBPACK_IMPORTED_MODULE_13__.createToolbox)(ctx);
  ctx.inspector = (0,_inspector__WEBPACK_IMPORTED_MODULE_6__.createInspector)(ctx);
  var actions = ctx.actions,
    canvas = ctx.canvas;

  // ---- block operations ---------------------------------------------------------

  function limit() {
    return store.toolbox.limits && store.toolbox.limits.max_blocks || 200;
  }
  function defaultIndex() {
    var selected = store.selectedId ? store.indexOf(store.selectedId) : -1;
    if (selected !== -1) {
      return selected + 1;
    }
    var last = store.blocks[store.blocks.length - 1];
    return last && last.type === 'signature' ? store.blocks.length - 1 : store.blocks.length;
  }
  function changed(focusId) {
    store.selectedId = focusId !== undefined ? focusId : store.selectedId;
    canvas.render();
    ctx.toolbox.refresh();
    store.emit('selection');
    autosave.markDirty();
  }
  function insertBlock(block, index) {
    if (!block) {
      return null;
    }
    if (store.blocks.length >= limit()) {
      notify('A document can have at most ' + limit() + ' blocks.', 'error');
      return null;
    }
    var at = index === null || index === undefined ? defaultIndex() : Math.max(0, Math.min(index, store.blocks.length));
    store.blocks.splice(at, 0, block);
    changed(block.id);
    canvas.focusBlock(block.id);
    return block;
  }
  function ensureProductBlock(index) {
    var existing = store.blocks.find(function (b) {
      return b.type === 'product_list';
    });
    if (existing) {
      if (store.selectedId !== existing.id) {
        canvas.select(existing.id);
      }
      return existing;
    }
    return insertBlock((0,_blocks__WEBPACK_IMPORTED_MODULE_4__.newBlock)('product'), index);
  }
  actions.addTool = function (toolId, index) {
    if (!store.editable) {
      return;
    }
    switch (toolId) {
      case 'product':
      case 'custom_line':
        // A template holds only the generic product area: no wizard, no catalog, no lines (17B §6).
        if (store.isTemplate) {
          ensureProductBlock(index);
          return;
        }
        if (ensureProductBlock(index)) {
          if (toolId === 'product') {
            ctx.productOps.addProduct();
          } else {
            ctx.productOps.customLine();
          }
        }
        return;
      case 'signature':
        {
          var existing = store.blocks.find(function (b) {
            return b.type === 'signature';
          });
          if (existing) {
            notify('A document has one signature block.', 'info');
            canvas.select(existing.id);
            canvas.focusBlock(existing.id);
            return;
          }
          insertBlock((0,_blocks__WEBPACK_IMPORTED_MODULE_4__.newBlock)('signature'), index);
          return;
        }
      case 'payment_terms':
        if (store.hasType('payment_terms')) {
          notify('Payment terms are already on this document.', 'info');
          return;
        }
        insertBlock((0,_blocks__WEBPACK_IMPORTED_MODULE_4__.newBlock)('payment_terms'), index);
        return;
      default:
        insertBlock((0,_blocks__WEBPACK_IMPORTED_MODULE_4__.newBlock)(toolId), index);
    }
  };
  actions.moveBlock = function (id, toIndex) {
    var from = store.indexOf(id);
    if (from === -1 || from === toIndex) {
      return;
    }
    var _store$blocks$splice = store.blocks.splice(from, 1),
      _store$blocks$splice2 = _slicedToArray(_store$blocks$splice, 1),
      block = _store$blocks$splice2[0];
    store.blocks.splice(Math.max(0, Math.min(toIndex, store.blocks.length)), 0, block);
    changed(id);
  };
  actions.moveBy = function (id, delta) {
    var from = store.indexOf(id);
    var to = from + delta;
    if (from === -1 || to < 0 || to >= store.blocks.length) {
      return;
    }
    actions.moveBlock(id, to);
    var node = canvasHost.querySelector('[data-block-id="' + id + '"]');
    if (node) {
      node.focus({
        preventScroll: true
      });
      node.scrollIntoView({
        block: 'nearest'
      });
    }
  };
  actions.duplicate = function (id) {
    var block = store.block(id);
    if (!block || _blocks__WEBPACK_IMPORTED_MODULE_4__.SINGLE.indexOf(block.type) !== -1) {
      return;
    }
    insertBlock((0,_blocks__WEBPACK_IMPORTED_MODULE_4__.cloneBlock)(block), store.indexOf(id) + 1);
  };
  actions.insertAfter = function (id, toolId) {
    insertBlock((0,_blocks__WEBPACK_IMPORTED_MODULE_4__.newBlock)(toolId), store.indexOf(id) + 1);
  };
  actions.remove = /*#__PURE__*/function () {
    var _ref = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee(id) {
      var block, ok, _iterator, _step, line, result, index, _t;
      return _regenerator().w(function (_context) {
        while (1) switch (_context.p = _context.n) {
          case 0:
            block = store.block(id);
            if (block) {
              _context.n = 1;
              break;
            }
            return _context.a(2);
          case 1:
            if (!(block.type === 'product_list' && (store.commerce.lines || []).length > 0)) {
              _context.n = 12;
              break;
            }
            _context.n = 2;
            return confirmDialog('Remove the pricing block?', 'This also removes its ' + store.commerce.lines.length + ' line(s) from the document.', 'Remove pricing');
          case 2:
            ok = _context.v;
            if (ok) {
              _context.n = 3;
              break;
            }
            return _context.a(2);
          case 3:
            _iterator = _createForOfIteratorHelper(store.commerce.lines.slice());
            _context.p = 4;
            _iterator.s();
          case 5:
            if ((_step = _iterator.n()).done) {
              _context.n = 9;
              break;
            }
            line = _step.value;
            _context.n = 6;
            return api.mutate('DELETE', store.urls.line_template.replace('__LINE__', line.uid), {});
          case 6:
            result = _context.v;
            if (!result.ok) {
              _context.n = 7;
              break;
            }
            store.adoptCommerce(result.json);
            _context.n = 8;
            break;
          case 7:
            if (result.status !== 409) {
              notify((0,_api__WEBPACK_IMPORTED_MODULE_0__.errorMessage)(result, 'A line could not be removed.'), 'error');
            }
            return _context.a(2);
          case 8:
            _context.n = 5;
            break;
          case 9:
            _context.n = 11;
            break;
          case 10:
            _context.p = 10;
            _t = _context.v;
            _iterator.e(_t);
          case 11:
            _context.p = 11;
            _iterator.f();
            return _context.f(11);
          case 12:
            index = store.indexOf(id);
            if (!(index === -1)) {
              _context.n = 13;
              break;
            }
            return _context.a(2);
          case 13:
            store.blocks.splice(index, 1);
            changed(store.selectedId === id ? null : store.selectedId);
          case 14:
            return _context.a(2);
        }
      }, _callee, null, [[4, 10, 11, 12]]);
    }));
    return function (_x) {
      return _ref.apply(this, arguments);
    };
  }();
  actions.updateBlock = function (id, patch, options) {
    var block = store.block(id);
    if (!block || !store.editable) {
      return;
    }
    Object.assign(block.data, patch);
    autosave.markDirty();
    if (!options || options.rerender !== false) {
      canvas.renderBlock(id);
    }
  };
  actions.convertText = function (id, level) {
    var block = store.block(id);
    if (!block || block.type !== 'text' && block.type !== 'heading') {
      return;
    }
    var data = {
      align: block.data.align || 'left',
      runs: block.data.runs || []
    };
    if (level === 0) {
      block.type = 'text';
      block.data = data;
    } else {
      block.type = 'heading';
      block.data = _objectSpread({
        level: level
      }, data);
    }
    changed(id);
    canvas.focusBlock(id);
  };

  // ---- product operations -----------------------------------------------------------

  function lineUrl(uid) {
    return store.urls.line_template.replace('__LINE__', uid);
  }
  function handle(result, failure) {
    if (result.ok) {
      store.adoptCommerce(result.json);
    } else {
      if (result.status !== 409) {
        notify((0,_api__WEBPACK_IMPORTED_MODULE_0__.errorMessage)(result, failure), 'error');
      }
      store.emit('commerce');
    }
  }
  var hasTerms = function hasTerms() {
    return !!store.commerce.plan || (store.commerce.schedule || []).length > 0;
  };
  Object.assign(ctx.productOps, {
    addProduct: function addProduct() {
      ensureProductBlock();
      (0,_product_wizard__WEBPACK_IMPORTED_MODULE_9__.openProductWizard)(ctx, {
        mode: (store.commerce.lines || []).length === 0 && !hasTerms() ? 'first' : 'add'
      });
    },
    createProduct: function createProduct() {
      ensureProductBlock();
      (0,_product_wizard__WEBPACK_IMPORTED_MODULE_9__.openProductWizard)(ctx, {
        mode: (store.commerce.lines || []).length === 0 && !hasTerms() ? 'first' : 'add',
        startCreate: true
      });
    },
    customLine: function customLine() {
      ensureProductBlock();
      (0,_line_modal__WEBPACK_IMPORTED_MODULE_7__.openCustomLineModal)(ctx);
    },
    editTerms: function editTerms() {
      if ((store.commerce.lines || []).length === 0) {
        ctx.productOps.addProduct();
        return;
      }
      (0,_product_wizard__WEBPACK_IMPORTED_MODULE_9__.openProductWizard)(ctx, {
        mode: 'terms'
      });
    },
    changeLine: function changeLine(line) {
      (0,_product_wizard__WEBPACK_IMPORTED_MODULE_9__.openProductWizard)(ctx, {
        mode: 'replace',
        line: line
      });
    },
    setQuantity: function setQuantity(uid, quantity) {
      return _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee2() {
        var _t2;
        return _regenerator().w(function (_context2) {
          while (1) switch (_context2.n) {
            case 0:
              _t2 = handle;
              _context2.n = 1;
              return api.mutate('PATCH', lineUrl(uid), {
                quantity: quantity
              });
            case 1:
              _t2(_context2.v, 'The quantity could not be changed.');
            case 2:
              return _context2.a(2);
          }
        }, _callee2);
      }))();
    },
    removeLine: function removeLine(line) {
      return _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee3() {
        var _t3;
        return _regenerator().w(function (_context3) {
          while (1) switch (_context3.n) {
            case 0:
              _t3 = handle;
              _context3.n = 1;
              return api.mutate('DELETE', lineUrl(line.uid), {});
            case 1:
              _t3(_context3.v, 'The line could not be removed.');
            case 2:
              return _context3.a(2);
          }
        }, _callee3);
      }))();
    },
    moveLine: function moveLine(uid, delta) {
      return _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee4() {
        var uids, from, to, _t4;
        return _regenerator().w(function (_context4) {
          while (1) switch (_context4.n) {
            case 0:
              uids = (store.commerce.lines || []).map(function (line) {
                return line.uid;
              });
              from = uids.indexOf(uid);
              to = from + delta;
              if (!(from === -1 || to < 0 || to >= uids.length)) {
                _context4.n = 1;
                break;
              }
              return _context4.a(2);
            case 1:
              uids.splice(to, 0, uids.splice(from, 1)[0]);
              _t4 = handle;
              _context4.n = 2;
              return api.mutate('PUT', store.urls.lines_order, {
                line_uids: uids
              });
            case 2:
              _t4(_context4.v, 'The lines could not be reordered.');
            case 3:
              return _context4.a(2);
          }
        }, _callee4);
      }))();
    }
  });

  // ---- confirm ---------------------------------------------------------------------------

  function confirmDialog(title, text, confirmLabel) {
    return new Promise(function (resolve) {
      var answered = false;
      var modal = (0,_modal__WEBPACK_IMPORTED_MODULE_8__.openModal)(modalRoot, {
        title: title,
        size: 'sm',
        onClose: function onClose() {
          if (!answered) resolve(false);
        }
      });
      modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('p', {
        text: text
      }));
      modal.footer.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('button', {
        type: 'button',
        "class": 'de-btn',
        text: 'Cancel',
        onclick: function onclick() {
          return modal.close();
        }
      }));
      modal.footer.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('button', {
        type: 'button',
        "class": 'de-btn de-btn--danger',
        'data-role': 'confirm-yes',
        text: confirmLabel,
        onclick: function onclick() {
          answered = true;
          resolve(true);
          modal.close();
        }
      }));
    });
  }

  // ---- header ------------------------------------------------------------------------------

  var indicator = root.querySelector('[data-role="save-indicator"]');
  var titleInput = root.querySelector('[data-role="editor-title"]');
  var errorBanner = null;
  var conflictBanner = null;
  function paintSave() {
    var _store$save = store.save,
      state = _store$save.state,
      message = _store$save.message;
    if (indicator) {
      indicator.setAttribute('data-state', state);
      indicator.textContent = state === 'error' ? 'Not saved' : state === 'conflict' ? 'Out of date' : SAVE_TEXT[state] || '';
      indicator.title = message || '';
    }
    if (errorBanner && state !== 'error') {
      errorBanner.remove();
      errorBanner = null;
    }
    if (state === 'error' && banners) {
      if (!errorBanner) {
        errorBanner = (0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('div', {
          "class": 'de-banner de-banner--danger',
          'data-role': 'save-error-banner'
        });
        banners.appendChild(errorBanner);
      }
      errorBanner.textContent = message || 'This change could not be saved.';
    }
    if (state === 'conflict' && banners && !conflictBanner) {
      conflictBanner = (0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('div', {
        "class": 'de-banner de-banner--danger',
        'data-role': 'conflict-banner'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('span', {
        text: 'This ' + (store.isTemplate ? 'template' : 'document') + ' was changed in another tab. Your recent edits here were not saved. '
      }), (0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('button', {
        type: 'button',
        "class": 'de-btn de-btn--sm',
        'data-role': 'conflict-reload',
        text: 'Reload',
        onclick: function onclick() {
          return window.location.reload();
        }
      })]);
      banners.appendChild(conflictBanner);
    }
  }
  store.subscribe(function (topic) {
    if (topic === 'save') {
      paintSave();
      if (store.save.state === 'conflict') {
        setBusyControls();
      }
    }
    if (topic === 'selection' || topic === 'commerce') {
      ctx.toolbox.refresh();
    }
  });
  function setBusyControls() {
    // Stop editing: nothing more can be saved from this stale copy, and nothing is overwritten.
    root.classList.add('is-conflict');
    store.editable = false;
    canvasHost.querySelectorAll('[contenteditable="true"]').forEach(function (node) {
      return node.setAttribute('contenteditable', 'false');
    });
    ctx.inline.detach();
    ctx.toolbox.refresh();
    root.querySelectorAll('[data-role="action-send"], [data-role="action-save"]').forEach(function (node) {
      node.disabled = true;
    });
  }
  if (titleInput && store.editable) {
    titleInput.addEventListener('input', function () {
      var value = titleInput.value.trim();
      if (value === '' || value === store.title) {
        return;
      }
      store.title = value;
      autosave.markDirty();
    });
    titleInput.addEventListener('blur', function () {
      if (titleInput.value.trim() === '') {
        titleInput.value = store.title;
      }
    });
    titleInput.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        titleInput.blur();
      }
    });
  }

  // Template mode: the type select beside the name (Proposal / Contract).
  var typeSelect = root.querySelector('[data-role="template-type"]');
  if (typeSelect && store.isTemplate) {
    typeSelect.value = store.templateType || 'proposal';
    typeSelect.disabled = !store.editable;
    typeSelect.addEventListener('change', function () {
      store.templateType = typeSelect.value;
      autosave.markDirty();
    });
  }

  // Document mode: save this layout as a template (the product and contact are never saved).
  var saveTemplateButton = root.querySelector('[data-role="action-save-template"]');
  if (saveTemplateButton && !store.isTemplate) {
    saveTemplateButton.addEventListener('click', function () {
      return (0,_save_template__WEBPACK_IMPORTED_MODULE_10__.openSaveTemplateDialog)(ctx);
    });
  }

  // Platform templates: Assign / Publish / Unpublish leave the page, so unsaved edits are saved first and a failed
  // save keeps the user here (nothing is navigated or posted over a conflict or an error).
  root.querySelectorAll('[data-flush-first]').forEach(function (node) {
    node.addEventListener('click', /*#__PURE__*/function () {
      var _ref2 = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee5(event) {
        var ok, _t5;
        return _regenerator().w(function (_context5) {
          while (1) switch (_context5.n) {
            case 0:
              event.preventDefault();
              if (!store.editable) {
                _context5.n = 2;
                break;
              }
              _context5.n = 1;
              return autosave.flush();
            case 1:
              _t5 = _context5.v;
              _context5.n = 3;
              break;
            case 2:
              _t5 = true;
            case 3:
              ok = _t5;
              if (!(!ok && store.save.state !== 'saved')) {
                _context5.n = 4;
                break;
              }
              notify('Fix the save problem first, then try again.', 'error');
              return _context5.a(2);
            case 4:
              if (node.dataset.flushFirst === 'form' && node.form) {
                node.form.submit();
              } else if (node.href) {
                window.location.href = node.href;
              }
            case 5:
              return _context5.a(2);
          }
        }, _callee5);
      }));
      return function (_x2) {
        return _ref2.apply(this, arguments);
      };
    }());
  });
  var saveButton = root.querySelector('[data-role="action-save"]');
  if (saveButton) {
    saveButton.addEventListener('click', /*#__PURE__*/_asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee6() {
      var ok;
      return _regenerator().w(function (_context6) {
        while (1) switch (_context6.n) {
          case 0:
            _context6.n = 1;
            return autosave.flush();
          case 1:
            ok = _context6.v;
            if (ok) {
              notify('Saved.', 'success');
            }
          case 2:
            return _context6.a(2);
        }
      }, _callee6);
    })));
  }
  var previewButton = root.querySelector('[data-role="action-preview"]');
  if (previewButton) {
    previewButton.addEventListener('click', /*#__PURE__*/_asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee7() {
      var ok, modal;
      return _regenerator().w(function (_context7) {
        while (1) switch (_context7.n) {
          case 0:
            if (!store.editable) {
              _context7.n = 2;
              break;
            }
            _context7.n = 1;
            return autosave.flush();
          case 1:
            ok = _context7.v;
            if (!ok && store.save.state !== 'conflict') {
              notify('Preview shows your last saved version.', 'info');
            }
          case 2:
            modal = (0,_modal__WEBPACK_IMPORTED_MODULE_8__.openModal)(modalRoot, {
              title: 'Preview',
              size: 'xl',
              className: 'de-preview'
            });
            modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('iframe', {
              "class": 'de-preview__frame',
              src: store.urls.preview,
              title: 'Document preview',
              'data-role': 'preview-frame'
            }));
            modal.footer.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('a', {
              "class": 'de-btn',
              href: store.urls.preview,
              target: '_blank',
              rel: 'noopener',
              text: 'Open in new tab'
            }));
            modal.footer.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_3__.el)('button', {
              type: 'button',
              "class": 'de-btn de-btn--primary',
              text: 'Close',
              onclick: function onclick() {
                return modal.close();
              }
            }));
          case 3:
            return _context7.a(2);
        }
      }, _callee7);
    })));
  }
  var sendButton = root.querySelector('[data-role="action-send"]');
  if (sendButton) {
    sendButton.addEventListener('click', function () {
      return (0,_send_dialog__WEBPACK_IMPORTED_MODULE_11__.openSendDialog)(ctx);
    });
  }
  var moreButton = root.querySelector('[data-role="action-more"]');
  var moreMenu = root.querySelector('[data-role="more-menu"]');
  if (moreButton && moreMenu) {
    var setMenu = function setMenu(open) {
      moreMenu.hidden = !open;
      moreButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    moreButton.addEventListener('click', function (event) {
      event.stopPropagation();
      setMenu(moreMenu.hidden);
    });
    document.addEventListener('click', function () {
      return setMenu(false);
    });
    moreMenu.addEventListener('click', function (event) {
      return event.stopPropagation();
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        setMenu(false);
      }
    });
  }
  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && store.editable) {
      event.preventDefault();
      autosave.flush();
    }
  });

  // The guard stays on while anything is unsaved or in flight.
  window.addEventListener('beforeunload', function (event) {
    if (store.editable && (store.save.state === 'dirty' || store.save.state === 'saving' || store.dirty || api.busy())) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  // ---- go ----------------------------------------------------------------------------------------

  canvas.render();
  ctx.toolbox.refresh();
  paintSave();

  // Handy for tests and support; not part of the page contract.
  root.__documentEditor = {
    store: store,
    api: api,
    autosave: autosave,
    canvas: canvas,
    actions: actions,
    ctx: ctx
  };

  // ---- legacy ------------------------------------------------------------------------------------

  function initLegacy() {
    var button = root.querySelector('[data-role="legacy-upgrade-button"]');
    var message = root.querySelector('[data-role="legacy-error"]');
    if (!button) {
      return;
    }
    button.addEventListener('click', /*#__PURE__*/_asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee8() {
      var result;
      return _regenerator().w(function (_context8) {
        while (1) switch (_context8.n) {
          case 0:
            button.disabled = true;
            message.hidden = true;
            _context8.n = 1;
            return api.mutate('POST', store.urls.upgrade, {});
          case 1:
            result = _context8.v;
            if (!result.ok) {
              _context8.n = 2;
              break;
            }
            window.location.reload();
            return _context8.a(2);
          case 2:
            button.disabled = false;
            message.textContent = result.status === 409 ? 'This document was changed in another tab. Reload the page and try again.' : (0,_api__WEBPACK_IMPORTED_MODULE_0__.errorMessage)(result, 'The document could not be upgraded.');
            message.hidden = false;
          case 3:
            return _context8.a(2);
        }
      }, _callee8);
    })));
  }
}
window.DocumentEditor = {
  init: init
};


/***/ },

/***/ "./resources/js/documents/editor/inline-text.js"
/*!******************************************************!*\
  !*** ./resources/js/documents/editor/inline-text.js ***!
  \******************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createInline: () => (/* binding */ createInline)
/* harmony export */ });
/* harmony import */ var _dom__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./dom */ "./resources/js/documents/editor/dom.js");
/* harmony import */ var _serializer__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./serializer */ "./resources/js/documents/editor/serializer.js");
// Contract 17B — the mini toolbar for inline text editing. Deliberately small:
// bold, italic, underline, link (http/https/mailto/tel), alignment, paragraph /
// heading level, and "Insert merge field". No colours, no fonts, no word
// processor. Text is pasted as plain text only (handled by the canvas).
//
// Formatting uses the browser's own editing commands on the contenteditable;
// the canvas turns the resulting DOM into BlockSchema runs with
// serializer.domToRuns on every `input`. The toolbar lives INSIDE the selected
// block's wrapper so it travels with the block and never needs positioning.



function createInline(ctx) {
  var store = ctx.store,
    actions = ctx.actions;
  var editable = null;
  var block = null;
  var savedRange = null;
  var buttons = {};
  var toolbar = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-mini',
    role: 'toolbar',
    'aria-label': 'Text formatting',
    'data-role': 'mini-toolbar'
  });

  // Keep the text selection: nothing in the toolbar may take focus on mousedown.
  toolbar.addEventListener('mousedown', function (event) {
    if (event.target.tagName !== 'INPUT') {
      event.preventDefault();
    }
  });
  function button(key, iconName, label, onclick, text) {
    var node = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-mini-btn',
      title: label,
      'aria-label': label,
      'data-cmd': key,
      onclick: onclick
    }, [iconName ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)(iconName) : null, text ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      text: text
    }) : null]);
    buttons[key] = node;
    return node;
  }
  var group = function group(children) {
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-mini__group'
    }, children);
  };
  var linkInput = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
    type: 'text',
    "class": 'de-input de-input--sm',
    placeholder: 'https://, mailto: or tel:',
    'aria-label': 'Link address',
    'data-role': 'link-input'
  });
  var linkError = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-mini__error',
    hidden: true
  });
  var linkPanel = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-mini__panel',
    'data-role': 'link-panel',
    hidden: true
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-mini__row'
  }, [linkInput, (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
    type: 'button',
    "class": 'de-btn de-btn--sm de-btn--primary',
    onclick: function onclick() {
      return applyLink();
    },
    text: 'Apply'
  }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
    type: 'button',
    "class": 'de-btn de-btn--sm',
    onclick: function onclick() {
      return removeLink();
    },
    text: 'Remove'
  })]), linkError]);
  linkInput.addEventListener('keydown', function (event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      applyLink();
    } else if (event.key === 'Escape') {
      event.preventDefault();
      closePanels();
      restoreSelection();
    }
  });
  var mergePanel = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-mini__panel de-mini__panel--list',
    'data-role': 'merge-panel',
    hidden: true
  });
  toolbar.appendChild(group([button('bold', 'bold', 'Bold', function () {
    return command('bold');
  }), button('italic', 'italic', 'Italic', function () {
    return command('italic');
  }), button('underline', 'underline', 'Underline', function () {
    return command('underline');
  }), button('link', 'link', 'Link', function () {
    return toggleLink();
  })]));
  toolbar.appendChild(group([button('left', 'align-left', 'Align left', function () {
    return align('left');
  }), button('center', 'align-center', 'Align center', function () {
    return align('center');
  }), button('right', 'align-right', 'Align right', function () {
    return align('right');
  })]));
  toolbar.appendChild(group([button('p', null, 'Paragraph', function () {
    return level(0);
  }, 'P'), button('h1', null, 'Heading 1', function () {
    return level(1);
  }, 'H1'), button('h2', null, 'Heading 2', function () {
    return level(2);
  }, 'H2'), button('h3', null, 'Heading 3', function () {
    return level(3);
  }, 'H3')]));
  toolbar.appendChild(group([button('merge', null, 'Insert merge field', function () {
    return toggleMerge();
  }, 'Insert merge field')]));
  toolbar.appendChild(linkPanel);
  toolbar.appendChild(mergePanel);

  // ---- selection helpers -------------------------------------------------

  function rememberSelection() {
    var selection = window.getSelection();
    if (selection && selection.rangeCount > 0 && editable && editable.contains(selection.anchorNode)) {
      savedRange = selection.getRangeAt(0).cloneRange();
    }
  }
  function restoreSelection() {
    if (!editable) {
      return;
    }
    editable.focus();
    if (savedRange) {
      var selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(savedRange);
    }
  }
  function changed() {
    if (editable) {
      editable.dispatchEvent(new Event('input', {
        bubbles: true
      }));
    }
  }
  function closePanels() {
    linkPanel.hidden = true;
    mergePanel.hidden = true;
    linkError.hidden = true;
  }

  // ---- commands ------------------------------------------------------------

  function command(name) {
    if (!editable) {
      return;
    }
    editable.focus();
    document.execCommand('styleWithCSS', false, false);
    document.execCommand(name, false, null);
    refresh();
  }
  function anchorAtSelection() {
    var selection = window.getSelection();
    var node = selection && selection.anchorNode;
    while (node && node !== editable) {
      if (node.nodeType === 1 && node.nodeName === 'A') {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }
  function toggleLink() {
    if (!linkPanel.hidden) {
      closePanels();
      return;
    }
    rememberSelection();
    mergePanel.hidden = true;
    var anchor = anchorAtSelection();
    linkInput.value = anchor ? anchor.getAttribute('href') || '' : '';
    linkError.hidden = true;
    linkPanel.hidden = false;
    linkInput.focus();
  }
  function applyLink() {
    var href = (0,_serializer__WEBPACK_IMPORTED_MODULE_1__.normalizeHref)(linkInput.value);
    if (linkInput.value.trim() === '') {
      removeLink();
      return;
    }
    if (href === null) {
      linkError.textContent = 'Use an http(s) address, an email address or a phone number.';
      linkError.hidden = false;
      return;
    }
    restoreSelection();
    var anchor = anchorAtSelection();
    var selection = window.getSelection();
    if (selection.isCollapsed && anchor) {
      var range = document.createRange();
      range.selectNodeContents(anchor);
      selection.removeAllRanges();
      selection.addRange(range);
    } else if (selection.isCollapsed) {
      linkPanel.hidden = false;
      linkError.textContent = 'Select the words you want to link first.';
      linkError.hidden = false;
      return;
    }
    document.execCommand('createLink', false, href);
    closePanels();
    changed();
    refresh();
  }
  function removeLink() {
    restoreSelection();
    var anchor = anchorAtSelection();
    var selection = window.getSelection();
    if (selection.isCollapsed && anchor) {
      var range = document.createRange();
      range.selectNodeContents(anchor);
      selection.removeAllRanges();
      selection.addRange(range);
    }
    document.execCommand('unlink', false, null);
    closePanels();
    changed();
    refresh();
  }
  function toggleMerge() {
    if (!mergePanel.hidden) {
      closePanels();
      return;
    }
    rememberSelection();
    linkPanel.hidden = true;
    while (mergePanel.firstChild) {
      mergePanel.removeChild(mergePanel.firstChild);
    }
    var current = null;
    (store.toolbox.merge_fields || []).forEach(function (field) {
      if (field.group !== current) {
        current = field.group;
        mergePanel.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
          "class": 'de-mini__heading',
          text: field.group
        }));
      }
      var preview = store.mergePreview(field.token);
      mergePanel.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
        type: 'button',
        "class": 'de-mini__item',
        'data-token': field.token,
        onclick: function onclick() {
          return insertMerge(field.token);
        }
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        text: field.label
      }), preview ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
        text: preview
      }) : null]));
    });
    mergePanel.hidden = false;
  }
  function insertMerge(token) {
    restoreSelection();
    var selection = window.getSelection();
    var range = selection.rangeCount > 0 && editable.contains(selection.anchorNode) ? selection.getRangeAt(0) : null;
    var chip = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      "class": 'doc-merge',
      'data-token': token,
      contenteditable: 'false',
      title: 'Merge field: ' + store.mergeLabel(token)
    });
    chip.textContent = store.mergePreview(token) || store.mergeLabel(token);
    var space = document.createTextNode(' ');
    if (range) {
      range.deleteContents();
      range.insertNode(space);
      range.insertNode(chip);
      range.setStartAfter(space);
      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
    } else {
      editable.appendChild(chip);
      editable.appendChild(space);
    }
    closePanels();
    changed();
  }
  function align(value) {
    if (block) {
      actions.updateBlock(block.id, {
        align: value
      }, {
        rerender: false
      });
      editable.className = editable.className.replace(/doc-align-(left|center|right)/, 'doc-align-' + value);
      refresh();
    }
  }
  function level(value) {
    if (!block) {
      return;
    }
    actions.convertText(block.id, value);
  }

  // ---- state ---------------------------------------------------------------

  function refresh() {
    if (!block || !editable) {
      return;
    }
    ['bold', 'italic', 'underline'].forEach(function (cmd) {
      var on = false;
      try {
        on = document.queryCommandState(cmd);
      } catch (e) {
        on = false;
      }
      buttons[cmd].classList.toggle('is-active', on);
    });
    buttons.link.classList.toggle('is-active', !!anchorAtSelection());
    ['left', 'center', 'right'].forEach(function (a) {
      return buttons[a].classList.toggle('is-active', (block.data.align || 'left') === a);
    });
    var current = block.type === 'heading' ? 'h' + (block.data.level || 2) : 'p';
    ['p', 'h1', 'h2', 'h3'].forEach(function (k) {
      return buttons[k].classList.toggle('is-active', k === current);
    });
  }
  document.addEventListener('selectionchange', function () {
    if (editable && document.activeElement === editable) {
      refresh();
    }
  });
  return {
    attach: function attach(node, target, wrapper) {
      editable = node;
      block = target;
      savedRange = null;
      closePanels();
      wrapper.insertBefore(toolbar, wrapper.firstChild);
      toolbar.hidden = false;
      refresh();
    },
    detach: function detach() {
      editable = null;
      block = null;
      closePanels();
      if (toolbar.parentNode) {
        toolbar.parentNode.removeChild(toolbar);
      }
    },
    openLink: function openLink() {
      toggleLink();
    },
    refresh: refresh,
    get attached() {
      return editable;
    }
  };
}

/***/ },

/***/ "./resources/js/documents/editor/inspector.js"
/*!****************************************************!*\
  !*** ./resources/js/documents/editor/inspector.js ***!
  \****************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createInspector: () => (/* binding */ createInspector)
/* harmony export */ });
/* harmony import */ var _dom__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./dom */ "./resources/js/documents/editor/dom.js");
/* harmony import */ var _blocks__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./blocks */ "./resources/js/documents/editor/blocks.js");
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
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
// Contract 17B — the contextual right panel. It exists only while the selected
// block has something worth setting beyond its text: image (catalog picture,
// alt text, width), spacer height, signature label, business details,
// heading level / alignment, and the pricing block's two display switches.
// Text blocks are formatted from the mini toolbar instead, so selecting one
// leaves the panel closed.



var WITH_PANEL = ['heading', 'image', 'spacer', 'signature', 'business_details', 'product_list'];
function createInspector(ctx) {
  var store = ctx.store,
    actions = ctx.actions,
    root = ctx.root;
  var panel = root.querySelector('[data-role="inspector"]');
  if (!panel) {
    return {
      refresh: function refresh() {}
    };
  }
  function field(label, control, hint) {
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
      "class": 'de-field'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      "class": 'de-field__label',
      text: label
    }), control, hint ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
      "class": 'de-field__hint',
      text: hint
    }) : null]);
  }
  function segmented(options, current, onPick, label) {
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-seg',
      role: 'group',
      'aria-label': label
    }, options.map(function (option) {
      return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
        type: 'button',
        "class": 'de-seg__btn' + (option.value === current ? ' is-active' : ''),
        'aria-pressed': option.value === current ? 'true' : 'false',
        onclick: function onclick() {
          return onPick(option.value);
        }
      }, [option.icon ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)(option.icon) : null, option.text ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        text: option.text
      }) : null]);
    }));
  }
  function body(block) {
    var data = block.data || {};
    var readOnly = !store.editable;
    var set = function set(patch, rerender) {
      return actions.updateBlock(block.id, patch, {
        rerender: rerender !== false
      });
    };
    switch (block.type) {
      case 'heading':
        return [field('Heading level', segmented([{
          value: 1,
          text: 'H1'
        }, {
          value: 2,
          text: 'H2'
        }, {
          value: 3,
          text: 'H3'
        }], data.level || 2, function (value) {
          set({
            level: value
          });
          refreshSoon();
        }, 'Heading level')), field('Alignment', segmented([{
          value: 'left',
          icon: 'align-left'
        }, {
          value: 'center',
          icon: 'align-center'
        }, {
          value: 'right',
          icon: 'align-right'
        }], data.align || 'left', function (value) {
          set({
            align: value
          });
          refreshSoon();
        }, 'Alignment'))];
      case 'spacer':
        {
          var number = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
            type: 'number',
            "class": 'de-input',
            min: '8',
            max: '120',
            step: '1',
            value: String(data.height || 24),
            disabled: readOnly
          });
          var range = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
            type: 'range',
            min: '8',
            max: '120',
            step: '4',
            value: String(data.height || 24),
            disabled: readOnly,
            'aria-label': 'Spacer height'
          });
          var apply = function apply(raw, source) {
            var value = Math.max(8, Math.min(120, parseInt(raw, 10) || 24));
            if (source !== number) number.value = String(value);
            if (source !== range) range.value = String(value);
            set({
              height: value
            });
          };
          number.addEventListener('input', function () {
            return apply(number.value, number);
          });
          range.addEventListener('input', function () {
            return apply(range.value, range);
          });
          return [field('Height (pixels)', (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
            "class": 'de-row'
          }, [range, number]))];
        }
      case 'signature':
        {
          var input = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
            type: 'text',
            "class": 'de-input',
            maxlength: '100',
            value: data.label || 'Signature',
            disabled: readOnly
          });
          input.addEventListener('input', function () {
            return set({
              label: input.value.trim() === '' ? 'Signature' : input.value
            });
          });
          return [field('Label', input, 'Shown above the signer’s typed name.')];
        }
      case 'business_details':
        {
          var show = data.show || [];
          return [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('fieldset', {
            "class": 'de-field'
          }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('legend', {
            "class": 'de-field__label',
            text: 'Show'
          })].concat(_toConsumableArray([['name', 'Business name'], ['phone', 'Phone'], ['email', 'Email'], ['website', 'Website']].map(function (_ref) {
            var _ref2 = _slicedToArray(_ref, 2),
              key = _ref2[0],
              text = _ref2[1];
            var box = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
              type: 'checkbox',
              checked: show.indexOf(key) !== -1,
              disabled: readOnly
            });
            box.addEventListener('change', function () {
              var next = ['name', 'phone', 'email', 'website'].filter(function (candidate) {
                return candidate === key ? box.checked : show.indexOf(candidate) !== -1;
              });
              set({
                show: next
              });
              refreshSoon();
            });
            return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
              "class": 'de-check'
            }, [box, (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
              text: text
            })]);
          }))))];
        }
      case 'product_list':
        {
          var toggle = function toggle(key, text) {
            var box = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
              type: 'checkbox',
              checked: data[key] !== false,
              disabled: readOnly
            });
            box.addEventListener('change', function () {
              return set(_defineProperty({}, key, box.checked));
            });
            return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
              "class": 'de-check'
            }, [box, (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
              text: text
            })]);
          };
          return [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('fieldset', {
            "class": 'de-field'
          }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('legend', {
            "class": 'de-field__label',
            text: 'Show to the recipient'
          }), toggle('show_description', 'Product descriptions'), toggle('show_quantity', 'Quantities')])];
        }
      case 'image':
        return imagePanel(block, set, readOnly);
      default:
        return [];
    }
  }
  function imagePanel(block, set, readOnly) {
    var data = block.data || {};
    var children = [];
    if (store.images.length === 0) {
      children.push((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('p', {
        "class": 'de-muted',
        text: 'You have no pictures yet. Add one to a package or product in your catalog, then reload to choose it here.'
      }));
    } else {
      children.push((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
        "class": 'de-field'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        "class": 'de-field__label',
        text: 'Picture'
      }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
        "class": 'de-images',
        'data-role': 'image-picker'
      }, store.images.map(function (image) {
        return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
          type: 'button',
          "class": 'de-images__item' + (image.uid === data.catalog_image_uid ? ' is-active' : ''),
          title: image.name || 'Picture',
          'aria-label': (image.name || 'Picture') + (image.uid === data.catalog_image_uid ? ' (selected)' : ''),
          'aria-pressed': image.uid === data.catalog_image_uid ? 'true' : 'false',
          disabled: readOnly,
          'data-image-uid': image.uid,
          onclick: function onclick() {
            set({
              catalog_image_uid: image.uid,
              alt: data.alt || image.alt || image.name || ''
            });
            refreshSoon();
          }
        }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('img', {
          src: image.url,
          alt: ''
        })]);
      }))]));
    }
    var alt = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
      type: 'text',
      "class": 'de-input',
      maxlength: '200',
      value: data.alt || '',
      placeholder: 'Describe the picture',
      disabled: readOnly
    });
    alt.addEventListener('input', function () {
      return set({
        alt: alt.value
      }, false);
    });
    children.push(field('Alt text', alt, 'Read aloud by screen readers.'));
    var width = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
      type: 'range',
      min: '10',
      max: '100',
      step: '5',
      value: String(data.width_pct || 100),
      disabled: readOnly
    });
    var out = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('output', {
      text: (data.width_pct || 100) + '%'
    });
    width.addEventListener('input', function () {
      out.textContent = width.value + '%';
      set({
        width_pct: parseInt(width.value, 10)
      });
    });
    children.push(field('Width', (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-row'
    }, [width, out])));
    return children;
  }
  var soon = null;
  function refreshSoon() {
    clearTimeout(soon);
    soon = setTimeout(refresh, 0);
  }
  function refresh() {
    var block = store.selectedId ? store.block(store.selectedId) : null;
    var open = !!block && WITH_PANEL.indexOf(block.type) !== -1;
    panel.hidden = !open;
    root.classList.toggle('has-inspector', open);
    while (panel.firstChild) {
      panel.removeChild(panel.firstChild);
    }
    if (!open) {
      return;
    }
    panel.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-inspector__head'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('strong', {
      text: _blocks__WEBPACK_IMPORTED_MODULE_1__.TYPE_LABELS[block.type] || block.type
    }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-iconbtn de-only-narrow',
      'aria-label': 'Close settings',
      onclick: function onclick() {
        return ctx.canvas.deselect();
      }
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)('x')])]));
    body(block).forEach(function (node) {
      return panel.appendChild(node);
    });
  }
  store.subscribe(function (topic) {
    if (topic === 'selection') {
      refresh();
    }
  });
  return {
    refresh: refresh
  };
}

/***/ },

/***/ "./resources/js/documents/editor/line-modal.js"
/*!*****************************************************!*\
  !*** ./resources/js/documents/editor/line-modal.js ***!
  \*****************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   openCustomLineModal: () => (/* binding */ openCustomLineModal)
/* harmony export */ });
/* harmony import */ var _dom__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./dom */ "./resources/js/documents/editor/dom.js");
/* harmony import */ var _modal__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./modal */ "./resources/js/documents/editor/modal.js");
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./api */ "./resources/js/documents/editor/api.js");
/* harmony import */ var _money__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./money */ "./resources/js/documents/editor/money.js");
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
// Contract 17B — the small "Custom line item" form: a one-off line (name,
// optional description, quantity, price) that is NOT added to the catalog.
// Posts to editor.lines.custom; the server re-applies the stored payment plan.





function openCustomLineModal(ctx) {
  var store = ctx.store,
    api = ctx.api;
  var modal = (0,_modal__WEBPACK_IMPORTED_MODULE_1__.openModal)(ctx.modalRoot, {
    title: 'Custom line item',
    size: 'sm',
    className: 'de-customline'
  });
  var name = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
    type: 'text',
    "class": 'de-input',
    maxlength: '200',
    'data-role': 'line-name',
    'aria-label': 'Name',
    placeholder: 'e.g. Travel and setup'
  });
  var description = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('textarea', {
    "class": 'de-input',
    rows: '2',
    maxlength: '2000',
    'data-role': 'line-description',
    'aria-label': 'Description',
    placeholder: 'Optional'
  });
  var quantity = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
    type: 'number',
    "class": 'de-input de-input--qty',
    min: '1',
    step: '1',
    value: '1',
    'data-role': 'line-quantity',
    'aria-label': 'Quantity'
  });
  var price = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
    type: 'text',
    inputmode: 'decimal',
    "class": 'de-input',
    placeholder: '0.00',
    'data-role': 'line-price',
    'aria-label': 'Price'
  });
  var error = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-wizard__error',
    role: 'alert',
    'data-role': 'line-error',
    hidden: true
  });
  var add = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
    type: 'button',
    "class": 'de-btn de-btn--primary',
    'data-role': 'line-submit',
    text: 'Add line'
  });
  modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
    "class": 'de-field'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
    "class": 'de-field__label',
    text: 'Name'
  }), name]));
  modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
    "class": 'de-field'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
    "class": 'de-field__label',
    text: 'Description (optional)'
  }), description]));
  modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-row de-row--fields'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
    "class": 'de-field'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
    "class": 'de-field__label',
    text: 'Quantity'
  }), quantity]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
    "class": 'de-field de-field--grow'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
    "class": 'de-field__label',
    text: 'Unit price'
  }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-money'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
    "class": 'de-money__cur',
    title: 'Your business currency',
    text: store.currency
  }), price])])]));
  modal.footer.appendChild(error);
  modal.footer.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
    type: 'button',
    "class": 'de-btn',
    onclick: function onclick() {
      return modal.close();
    },
    text: 'Cancel'
  }));
  modal.footer.appendChild(add);
  var show = function show(message) {
    error.textContent = message;
    error.hidden = message === '';
  };
  add.addEventListener('click', /*#__PURE__*/_asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
    var minor, count, result;
    return _regenerator().w(function (_context) {
      while (1) switch (_context.n) {
        case 0:
          show('');
          minor = (0,_money__WEBPACK_IMPORTED_MODULE_3__.toMinor)(price.value, store.exponent);
          count = parseInt(quantity.value, 10);
          if (!(name.value.trim() === '')) {
            _context.n = 1;
            break;
          }
          return _context.a(2, show('Give the line a name.'));
        case 1:
          if (!(!Number.isFinite(count) || count < 1)) {
            _context.n = 2;
            break;
          }
          return _context.a(2, show('Quantity must be at least 1.'));
        case 2:
          if (!(minor === null || minor <= 0)) {
            _context.n = 3;
            break;
          }
          return _context.a(2, show('Enter a price greater than zero, for example 250.00.'));
        case 3:
          add.disabled = true;
          _context.n = 4;
          return api.mutate('POST', store.urls.lines_custom, {
            name: name.value.trim(),
            description: description.value.trim() || null,
            quantity: count,
            price: (0,_money__WEBPACK_IMPORTED_MODULE_3__.toDecimalString)(minor, store.exponent)
          });
        case 4:
          result = _context.v;
          add.disabled = false;
          if (result.ok) {
            _context.n = 5;
            break;
          }
          if (result.status === 409) {
            modal.close();
          } else {
            show((0,_api__WEBPACK_IMPORTED_MODULE_2__.errorMessage)(result, 'The line could not be added.'));
          }
          return _context.a(2);
        case 5:
          store.adoptCommerce(result.json);
          modal.close();
          if (ctx.notify) ctx.notify('Line added.', 'success');
        case 6:
          return _context.a(2);
      }
    }, _callee);
  })));
  return modal;
}

/***/ },

/***/ "./resources/js/documents/editor/modal.js"
/*!************************************************!*\
  !*** ./resources/js/documents/editor/modal.js ***!
  \************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   openModal: () => (/* binding */ openModal)
/* harmony export */ });
/* harmony import */ var _dom__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./dom */ "./resources/js/documents/editor/dom.js");
// Contract 17B — a small, dependency-free modal used by the product wizard,
// the custom-line form, the send dialog and the preview. It traps Tab focus,
// closes on Escape / backdrop and restores focus. Normal blocks never use it.


var counter = 0;

/**
 * @param {HTMLElement} root  the editor's `.de-modal-root`
 * @param {{title: string, size?: 'sm'|'md'|'lg'|'xl', className?: string, onClose?: Function}} options
 * @returns {{el: HTMLElement, body: HTMLElement, footer: HTMLElement, setTitle: Function, close: Function}}
 */
function openModal(root, options) {
  var previous = document.activeElement;
  var id = 'de-modal-' + ++counter;
  var title = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('h2', {
    "class": 'de-modal__title',
    id: id + '-title',
    text: options.title || ''
  });
  var body = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-modal__body'
  });
  var footer = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-modal__footer'
  });
  var closeButton = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
    type: 'button',
    "class": 'de-iconbtn',
    'aria-label': 'Close',
    'data-role': 'modal-close'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)('x')]);
  var dialog = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-modal__dialog de-modal__dialog--' + (options.size || 'md') + (options.className ? ' ' + options.className : ''),
    role: 'dialog',
    'aria-modal': 'true',
    'aria-labelledby': id + '-title',
    tabindex: '-1'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-modal__head'
  }, [title, closeButton]), body, footer]);
  var backdrop = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-modal',
    'data-role': 'modal'
  }, [dialog]);
  var closed = false;
  var close = function close() {
    if (closed) {
      return;
    }
    closed = true;
    document.removeEventListener('keydown', onKey, true);
    backdrop.remove();
    if (previous && typeof previous.focus === 'function' && document.contains(previous)) {
      previous.focus();
    }
    if (typeof options.onClose === 'function') {
      options.onClose();
    }
  };
  var focusable = function focusable() {
    return Array.prototype.slice.call(dialog.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')).filter(function (node) {
      return node.offsetParent !== null;
    });
  };
  var onKey = function onKey(event) {
    if (event.key === 'Escape') {
      event.stopPropagation();
      close();
      return;
    }
    if (event.key === 'Tab') {
      var nodes = focusable();
      if (nodes.length === 0) {
        return;
      }
      var first = nodes[0];
      var last = nodes[nodes.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  };
  closeButton.addEventListener('click', close);
  backdrop.addEventListener('mousedown', function (event) {
    if (event.target === backdrop) {
      close();
    }
  });
  document.addEventListener('keydown', onKey, true);
  root.appendChild(backdrop);
  setTimeout(function () {
    var first = focusable().find(function (node) {
      return node !== closeButton;
    });
    (first || dialog).focus();
  }, 0);
  return {
    el: dialog,
    body: body,
    footer: footer,
    close: close,
    setTitle: function setTitle(text) {
      title.textContent = text;
    }
  };
}

/***/ },

/***/ "./resources/js/documents/editor/money.js"
/*!************************************************!*\
  !*** ./resources/js/documents/editor/money.js ***!
  \************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   currencyExponent: () => (/* binding */ currencyExponent),
/* harmony export */   depositState: () => (/* binding */ depositState),
/* harmony export */   formatDate: () => (/* binding */ formatDate),
/* harmony export */   formatMinor: () => (/* binding */ formatMinor),
/* harmony export */   toDecimalString: () => (/* binding */ toDecimalString),
/* harmony export */   toMinor: () => (/* binding */ toMinor)
/* harmony export */ });
function _slicedToArray(r, e) { return _arrayWithHoles(r) || _iterableToArrayLimit(r, e) || _unsupportedIterableToArray(r, e) || _nonIterableRest(); }
function _nonIterableRest() { throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
function _iterableToArrayLimit(r, l) { var t = null == r ? null : "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (null != t) { var e, n, i, u, a = [], f = !0, o = !1; try { if (i = (t = t.call(r)).next, 0 === l) { if (Object(t) !== t) return; f = !1; } else for (; !(f = (e = i.call(t)).done) && (a.push(e.value), a.length !== l); f = !0); } catch (r) { o = !0, n = r; } finally { try { if (!f && null != t["return"] && (u = t["return"](), Object(u) !== u)) return; } finally { if (o) throw n; } } return a; } }
function _arrayWithHoles(r) { if (Array.isArray(r)) return r; }
// Contract 17B — money for the product wizard. Pure functions.
//
// The API takes and returns decimal strings in the document currency
// ("250.00"); the wizard works in whole smallest-currency-unit integers internally ONLY to do
// exact arithmetic, and never shows them: everything displayed goes through
// Intl.NumberFormat with the document's currency.

/** Digits after the decimal point for a currency (JPY 0, USD 2, KWD 3). */
function currencyExponent(currency, locale) {
  try {
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: currency
    }).resolvedOptions().maximumFractionDigits;
  } catch (e) {
    return 2;
  }
}

/** "1250.5" -> 125050 (for exponent 2). null when it is not a plain amount. */
function toMinor(input, exponent) {
  var text = String(input === undefined || input === null ? '' : input).trim().replace(/,/g, '');
  if (text === '' || !/^\d+(\.\d+)?$/.test(text)) {
    return null;
  }
  var _text$split = text.split('.'),
    _text$split2 = _slicedToArray(_text$split, 2),
    whole = _text$split2[0],
    _text$split2$ = _text$split2[1],
    fraction = _text$split2$ === void 0 ? '' : _text$split2$;
  if (fraction.length > exponent) {
    // 250.005 is not an amount this currency can hold.
    if (/[1-9]/.test(fraction.slice(exponent))) {
      return null;
    }
  }
  var padded = (fraction + '0'.repeat(exponent)).slice(0, exponent);
  var minor = Number(whole) * Math.pow(10, exponent) + (padded === '' ? 0 : Number(padded));
  return Number.isSafeInteger(minor) ? minor : null;
}

/** 25000 -> "250.00" — what the API receives. */
function toDecimalString(minor, exponent) {
  var negative = minor < 0;
  var abs = Math.abs(Math.round(minor));
  var scale = Math.pow(10, exponent);
  var whole = Math.floor(abs / scale);
  var fraction = String(abs % scale).padStart(exponent, '0');
  return (negative ? '-' : '') + whole + (exponent > 0 ? '.' + fraction : '');
}
function formatMinor(minor, currency, locale) {
  if (minor === null || minor === undefined) {
    return '';
  }
  var exponent = currencyExponent(currency, locale);
  try {
    // currencyDisplay 'code' matches the server's own wording ("USD 3,000.00"), so the wizard and the canvas read the same.
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: currency,
      currencyDisplay: 'code'
    }).format(minor / Math.pow(10, exponent)).replace(/ /g, ' ');
  } catch (e) {
    return (minor / Math.pow(10, exponent)).toFixed(exponent) + ' ' + currency;
  }
}

/**
 * The deposit/balance rules, live. Returns the remaining balance and the
 * message to show (null when the split is valid).
 */
function depositState(totalMinor, depositInput, exponent) {
  var text = String(depositInput === undefined || depositInput === null ? '' : depositInput).trim();
  if (text === '') {
    return {
      depositMinor: null,
      balanceMinor: null,
      error: 'Enter the deposit amount.'
    };
  }
  var depositMinor = toMinor(text, exponent);
  if (depositMinor === null) {
    return {
      depositMinor: null,
      balanceMinor: null,
      error: 'Enter the deposit as an amount, for example 250.00.'
    };
  }
  if (depositMinor <= 0) {
    return {
      depositMinor: depositMinor,
      balanceMinor: totalMinor - depositMinor,
      error: 'The deposit must be more than zero.'
    };
  }
  var balanceMinor = totalMinor - depositMinor;
  if (balanceMinor <= 0) {
    return {
      depositMinor: depositMinor,
      balanceMinor: balanceMinor,
      error: 'The deposit must be less than the total, so there is a balance left to pay.'
    };
  }
  return {
    depositMinor: depositMinor,
    balanceMinor: balanceMinor,
    error: null
  };
}

/** "2027-03-12" -> "12 Mar 2027" (calendar date, no timezone shift). */
function formatDate(iso, locale) {
  var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
  if (!match) {
    return '';
  }
  var date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
  try {
    return new Intl.DateTimeFormat(locale, {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      timeZone: 'UTC'
    }).format(date);
  } catch (e) {
    return iso;
  }
}

/***/ },

/***/ "./resources/js/documents/editor/product-block.js"
/*!********************************************************!*\
  !*** ./resources/js/documents/editor/product-block.js ***!
  \********************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   renderPaymentTerms: () => (/* binding */ renderPaymentTerms),
/* harmony export */   renderProductBlock: () => (/* binding */ renderProductBlock)
/* harmony export */ });
/* harmony import */ var _dom__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./dom */ "./resources/js/documents/editor/dom.js");
// Contract 17B — how the single product block and the payment-terms block draw
// themselves on the canvas. The markup and class names mirror
// resources/views/documents/blocks/types/{product_list,payment_terms}.blade.php
// (the one server renderer) so the canvas looks exactly like Preview and the
// recipient's page; the only additions are the small editing controls, which
// only exist while the document is editable.
//
// Lines, totals and the schedule are the server's: they arrive in
// store.commerce after every line / plan call. Nothing is calculated here.


function iconButton(name, label, onclick, extra) {
  return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
    type: 'button',
    "class": 'de-mini-btn' + (extra ? ' ' + extra : ''),
    title: label,
    'aria-label': label,
    onclick: onclick
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)(name)]);
}

/**
 * @param {object} block  a product_list block
 * @param {object} ctx    { store, ops }
 */
function renderProductBlock(block, ctx) {
  var store = ctx.store,
    ops = ctx.ops;
  var commerce = store.commerce;
  var editable = store.editable;

  // A template holds the product AREA only: a generic placeholder, no wizard, no lines (17B §6).
  if (store.isTemplate) {
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-product',
      'data-role': 'product-block'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'doc-placeholder',
      'data-role': 'product-placeholder'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      text: "Product / pricing block \u2014 you choose the package when you use this template."
    })])]);
  }
  var data = block.data || {};
  var lines = commerce.lines || [];
  var wrap = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-product',
    'data-role': 'product-block'
  });
  if (commerce.plan_invalid) {
    wrap.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-alert de-alert--warn',
      'data-role': 'plan-invalid',
      role: 'alert'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('strong', {
      text: 'The payment terms need attention. '
    }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      text: commerce.plan_error || 'The payment terms no longer fit the total.'
    }), editable ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-link',
      onclick: function onclick() {
        return ops.editTerms();
      },
      text: 'Update payment terms'
    }) : null]));
  }
  if (lines.length === 0) {
    wrap.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'doc-placeholder',
      'data-role': 'product-placeholder'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      text: 'Pricing: the products you add appear here, with totals and payment terms.'
    }), editable ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-product__empty-actions'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-btn de-btn--primary',
      onclick: function onclick() {
        return ops.addProduct();
      },
      'data-role': 'product-add-first'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)('plus'), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      text: 'Add product'
    })]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-btn',
      onclick: function onclick() {
        return ops.customLine();
      }
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      text: 'Custom line item'
    })])]) : null]));
    return wrap;
  }
  var showQty = data.show_quantity !== false;
  var showDesc = data.show_description !== false;
  var span = 3;
  var subtotal = commerce.totals.subtotal_minor;
  var total = commerce.totals.total_minor;
  var head = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('tr', null, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('th', {
    text: 'Item'
  }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('th', {
    "class": 'num' + (showQty ? '' : ' de-dim'),
    text: 'Qty'
  }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('th', {
    "class": 'num',
    text: 'Unit price'
  }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('th', {
    "class": 'num',
    text: 'Total'
  }), editable ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('th', {
    "class": 'de-col-actions',
    'aria-label': 'Line actions'
  }) : null]);
  var rows = lines.map(function (line, index) {
    var qty = editable ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
      type: 'number',
      min: '1',
      step: '1',
      "class": 'de-qty',
      value: String(line.quantity),
      'aria-label': 'Quantity for ' + line.name,
      onchange: function onchange(event) {
        var value = parseInt(event.target.value, 10);
        if (!Number.isFinite(value) || value < 1) {
          event.target.value = String(line.quantity);
          return;
        }
        if (value !== line.quantity) {
          ops.setQuantity(line.uid, value);
        }
      }
    }) : document.createTextNode(String(line.quantity));
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('tr', {
      'data-role': 'line',
      'data-line-uid': line.uid
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', null, [line.name, showDesc && line.description ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'doc-muted',
      text: line.description
    }) : null]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
      "class": 'num' + (showQty ? '' : ' de-dim')
    }, [qty]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
      "class": 'num',
      text: line.unit_price_formatted
    }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
      "class": 'num',
      'data-role': 'line-total',
      text: line.line_total_formatted
    }), editable ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
      "class": 'de-col-actions'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-line-actions'
    }, [index > 0 ? iconButton('chevron-up', 'Move line up', function () {
      return ops.moveLine(line.uid, -1);
    }) : null, index < lines.length - 1 ? iconButton('chevron-down', 'Move line down', function () {
      return ops.moveLine(line.uid, 1);
    }) : null, iconButton('pencil', 'Change product', function () {
      return ops.changeLine(line);
    }), iconButton('trash-2', 'Remove line', function () {
      return ops.removeLine(line);
    }, 'de-mini-btn--danger')])]) : null]);
  });
  var foot = [];
  if (subtotal !== undefined && subtotal !== total) {
    foot.push((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('tr', null, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
      colspan: span,
      text: 'Subtotal'
    }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
      "class": 'num',
      text: commerce.totals.subtotal_formatted
    }), editable ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td') : null]));
  }
  foot.push((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('tr', {
    "class": 'doc-total'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
    colspan: span,
    text: 'Total'
  }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
    "class": 'num',
    'data-role': 'total',
    text: commerce.totals.total_formatted
  }), editable ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td') : null]));

  // A payment-terms block, when present, owns the deposit/balance rows (same rule as the server renderer).
  if ((commerce.schedule || []).length === 2 && !store.hasType('payment_terms')) {
    commerce.schedule.forEach(function (item) {
      foot.push((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('tr', {
        'data-role': 'schedule-row',
        'data-kind': item.kind
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
        colspan: span
      }, [item.kind === 'deposit' ? 'Deposit ' : 'Balance ', (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        "class": 'doc-muted',
        text: '— ' + item.due_label
      })]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
        "class": 'num',
        text: item.amount_formatted
      }), editable ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td') : null]));
    });
  }
  wrap.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('table', {
    "class": 'doc-table',
    'data-role': 'lines'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('thead', null, [head]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('tbody', null, rows), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('tfoot', null, foot)]));
  if (editable) {
    wrap.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-product__actions'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-link',
      onclick: function onclick() {
        return ops.addProduct();
      },
      'data-role': 'product-add-another'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)('plus'), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      text: 'Add another product'
    })]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-link',
      onclick: function onclick() {
        return ops.createProduct();
      },
      'data-role': 'product-create'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      text: 'Create custom product'
    })]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-link',
      onclick: function onclick() {
        return ops.customLine();
      },
      'data-role': 'product-custom-line'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      text: 'Custom line item'
    })]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-link',
      onclick: function onclick() {
        return ops.editTerms();
      },
      'data-role': 'product-edit-terms'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)('wallet'), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      text: (commerce.schedule || []).length > 0 ? 'Change payment terms' : 'Set payment terms'
    })])]));
  }
  return wrap;
}

/** The canonical schedule, as the server draws it. */
function renderPaymentTerms(block, ctx) {
  var store = ctx.store,
    ops = ctx.ops;
  if (store.isTemplate) {
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'doc-placeholder',
      'data-role': 'payment-placeholder'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      text: "Payment terms \u2014 the deposit, balance and due dates are set when you use this template."
    })]);
  }
  var schedule = store.commerce.schedule || [];
  if (schedule.length === 0) {
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'doc-placeholder',
      'data-role': 'payment-placeholder'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      text: 'Payment terms: the deposit, balance and due dates you set appear here.'
    }), store.editable ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-product__empty-actions'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-btn',
      onclick: function onclick() {
        return ops.editTerms();
      },
      text: 'Set payment terms'
    })]) : null]);
  }
  return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', null, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('table', {
    "class": 'doc-table',
    'data-role': 'schedule'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('tbody', null, schedule.map(function (item) {
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('tr', {
      'data-role': 'schedule-item',
      'data-kind': item.kind
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
      text: item.kind.charAt(0).toUpperCase() + item.kind.slice(1)
    }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
      text: item.due_label
    }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('td', {
      "class": 'num',
      text: item.amount_formatted
    })]);
  }))]), store.editable ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-product__actions'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
    type: 'button',
    "class": 'de-link',
    onclick: function onclick() {
      return ops.editTerms();
    },
    text: 'Change payment terms'
  })]) : null]);
}

/***/ },

/***/ "./resources/js/documents/editor/product-wizard.js"
/*!*********************************************************!*\
  !*** ./resources/js/documents/editor/product-wizard.js ***!
  \*********************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   openProductWizard: () => (/* binding */ openProductWizard)
/* harmony export */ });
/* harmony import */ var _dom__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./dom */ "./resources/js/documents/editor/dom.js");
/* harmony import */ var _modal__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./modal */ "./resources/js/documents/editor/modal.js");
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./api */ "./resources/js/documents/editor/api.js");
/* harmony import */ var _money__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./money */ "./resources/js/documents/editor/money.js");
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
// Contract 17B — the "Add product" wizard.
//
//   Step 1  choose (or create) a product / package from the Business catalog
//   Step 2  how should this be paid?  Full payment | Deposit + balance
//   Step 3  (deposit only) deposit amount, live remaining balance, balance due
//
// Modes: `first` / `add` / `replace` / `terms`. `add` and `replace` stop after
// step 1 when the document already has payment terms (the server re-applies
// the stored plan after a line change); `terms` reopens steps 2-3 only.
//
// Money: shown through Intl.NumberFormat in the document currency, typed as a
// plain decimal, sent to the API as a decimal string ("250.00"). Integer minor
// units exist only inside money.js to do exact arithmetic and are never shown.
// The server is the authority on the schedule; this only collects intent.





function openProductWizard(ctx, options) {
  var store = ctx.store,
    api = ctx.api;
  var existingPlan = store.commerce.plan;
  var hasTerms = !!existingPlan || (store.commerce.schedule || []).length > 0;
  var state = {
    mode: options.mode || 'first',
    step: options.mode === 'terms' ? 2 : 1,
    item: null,
    quantity: '1',
    priceInput: '',
    search: '',
    results: [],
    loading: false,
    creating: !!options.startCreate,
    createError: '',
    replaceLine: options.line || null,
    itemAdded: false,
    busy: false,
    error: '',
    dates: null,
    plan: {
      structure: existingPlan && existingPlan.structure === 'deposit' ? 'deposit' : 'full',
      full_due: existingPlan && existingPlan.full_due || 'on_signing',
      full_due_date: existingPlan && existingPlan.full_due_date || '',
      balance_due: existingPlan && existingPlan.balance_due || 'after_deposit',
      balance_due_date: existingPlan && existingPlan.balance_due_date || '',
      deposit: existingPlan && existingPlan.deposit_input || ''
    }
  };
  var needsTerms = state.mode === 'first' || state.mode === 'terms' || (state.mode === 'add' || state.mode === 'replace') && !hasTerms;
  var modal = (0,_modal__WEBPACK_IMPORTED_MODULE_1__.openModal)(ctx.modalRoot, {
    title: titleFor(),
    size: 'md',
    className: 'de-wizard'
  });
  var stepLabel = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-wizard__step',
    'data-role': 'wizard-step'
  });
  modal.el.querySelector('.de-modal__head').insertBefore(stepLabel, modal.el.querySelector('.de-modal__title').nextSibling);
  function titleFor() {
    return {
      first: 'Add a product',
      add: 'Add another product',
      replace: 'Change product',
      terms: 'Payment terms'
    }[state.mode];
  }

  // ---- derived values ------------------------------------------------------

  var exponent = store.exponent;
  var qty = function qty() {
    var n = parseInt(state.quantity, 10);
    return Number.isFinite(n) && n >= 1 && String(n) === String(state.quantity).trim() ? n : null;
  };
  function unitMinor() {
    if (!state.item) return null;
    if (state.item.quote_only) return (0,_money__WEBPACK_IMPORTED_MODULE_3__.toMinor)(state.priceInput, exponent);
    return state.item.price_minor;
  }

  /** The document total the payment terms will apply to. */
  function totalMinor() {
    var current = store.commerce.totals.total_minor || 0;
    if (state.mode === 'terms' || state.itemAdded) return current;
    var unit = unitMinor();
    var count = qty();
    if (unit === null || count === null) return null;
    var removed = state.mode === 'replace' && state.replaceLine ? state.replaceLine.line_total_minor : 0;
    return current - removed + unit * count;
  }
  function totalSteps() {
    if (!needsTerms) return 1;
    var base = state.mode === 'terms' ? 1 : 2;
    return state.plan.structure === 'deposit' ? base + 1 : base;
  }
  function stepNumber() {
    return state.mode === 'terms' ? state.step - 1 : state.step;
  }

  // ---- shared pieces -------------------------------------------------------

  function radio(name, value, label, checked, _onchange, hint) {
    var input = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
      type: 'radio',
      name: name,
      value: value,
      checked: checked,
      onchange: function onchange() {
        return _onchange(value);
      }
    });
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
      "class": 'de-radio' + (checked ? ' is-checked' : '')
    }, [input, (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', null, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('strong', {
      text: label
    }), hint ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
      text: hint
    }) : null])]);
  }
  function footer(buttons) {
    while (modal.footer.firstChild) modal.footer.removeChild(modal.footer.firstChild);
    modal.footer.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-wizard__error',
      'data-role': 'wizard-error',
      role: 'alert',
      text: state.error,
      hidden: state.error === ''
    }));
    buttons.forEach(function (b) {
      return modal.footer.appendChild(b);
    });
  }
  function button(text, kind, onclick, disabled) {
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-btn' + (kind ? ' de-btn--' + kind : ''),
      disabled: !!disabled,
      onclick: onclick,
      text: text
    });
  }
  function setError(message) {
    state.error = message || '';
    var node = modal.footer.querySelector('[data-role="wizard-error"]');
    if (node) {
      node.textContent = state.error;
      node.hidden = state.error === '';
    }
  }
  function draw() {
    stepLabel.textContent = needsTerms || state.step === 1 ? 'Step ' + stepNumber() + ' of ' + totalSteps() : '';
    while (modal.body.firstChild) modal.body.removeChild(modal.body.firstChild);
    modal.setTitle(state.step === 1 ? titleFor() : state.step === 2 ? 'How should this be paid?' : 'Deposit and balance');
    if (state.step === 1) drawChoose();else if (state.step === 2) drawPayment();else drawDeposit();
  }

  // ---- step 1: choose ------------------------------------------------------
  function loadResults() {
    return _loadResults.apply(this, arguments);
  }
  function _loadResults() {
    _loadResults = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee2() {
      var token, result;
      return _regenerator().w(function (_context2) {
        while (1) switch (_context2.n) {
          case 0:
            token = ++loadResults.token;
            state.loading = true;
            paintResults();
            _context2.n = 1;
            return api.get(store.urls.catalog_search + '?q=' + encodeURIComponent(state.search));
          case 1:
            result = _context2.v;
            if (!(token !== loadResults.token)) {
              _context2.n = 2;
              break;
            }
            return _context2.a(2);
          case 2:
            state.loading = false;
            state.results = result.ok ? result.json.items || [] : [];
            if (!result.ok) {
              setError('Your products could not be loaded.');
            }
            paintResults();
          case 3:
            return _context2.a(2);
        }
      }, _callee2);
    }));
    return _loadResults.apply(this, arguments);
  }
  loadResults.token = 0;
  var resultsEl = null;
  var continueBtn = null;
  var quoteField = null;
  function paintResults() {
    if (!resultsEl) return;
    while (resultsEl.firstChild) resultsEl.removeChild(resultsEl.firstChild);
    if (state.loading) {
      resultsEl.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
        "class": 'de-muted',
        text: 'Loading...'
      }));
      return;
    }
    if (state.results.length === 0) {
      resultsEl.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
        "class": 'de-muted',
        'data-role': 'wizard-no-results',
        text: state.search ? 'Nothing matches that search.' : 'You have no products or packages yet. Create one below.'
      }));
      return;
    }
    state.results.forEach(function (item) {
      var selected = state.item && state.item.uid === item.uid;
      resultsEl.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
        type: 'button',
        "class": 'de-pick' + (selected ? ' is-active' : ''),
        'data-item-uid': item.uid,
        'aria-pressed': selected ? 'true' : 'false',
        disabled: item.addable === false,
        onclick: function onclick() {
          return choose(item);
        }
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        "class": 'de-pick__main'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('strong', {
        text: item.name
      }), item.description ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
        text: item.description.length > 90 ? item.description.slice(0, 90) + '…' : item.description
      }) : null]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        "class": 'de-pick__price',
        text: item.addable === false ? 'Other currency' : item.price_formatted || 'Quote only'
      })]));
    });
  }
  function choose(item) {
    state.item = item;
    setError('');
    paintResults();
    paintQuote();
    paintContinue();
  }
  function paintQuote() {
    if (!quoteField) return;
    while (quoteField.firstChild) quoteField.removeChild(quoteField.firstChild);
    quoteField.hidden = !(state.item && state.item.quote_only);
    if (quoteField.hidden) return;
    var input = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
      type: 'text',
      inputmode: 'decimal',
      "class": 'de-input',
      value: state.priceInput,
      placeholder: '0.00',
      'data-role': 'quote-price',
      'aria-label': 'Price'
    });
    input.addEventListener('input', function () {
      state.priceInput = input.value;
      paintContinue();
    });
    quoteField.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
      "class": 'de-field'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      "class": 'de-field__label',
      text: 'Price (' + store.currency + ')'
    }), input, (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
      "class": 'de-field__hint',
      text: 'This item has no fixed price, so enter the price for this document.'
    })]));
  }
  function canContinue() {
    if (!state.item) return false;
    if (qty() === null) return false;
    if (state.item.quote_only) {
      var minor = (0,_money__WEBPACK_IMPORTED_MODULE_3__.toMinor)(state.priceInput, exponent);
      if (minor === null || minor <= 0) return false;
    }
    return true;
  }
  function paintContinue() {
    if (!continueBtn) return;
    continueBtn.disabled = !canContinue() || state.busy;
  }
  function drawChoose() {
    var search = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
      type: 'search',
      "class": 'de-input',
      placeholder: 'Search your products and packages',
      value: state.search,
      'aria-label': 'Search products',
      'data-role': 'wizard-search'
    });
    var run = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.debounce)(function () {
      state.search = search.value.trim();
      loadResults();
    }, 250);
    search.addEventListener('input', run);
    resultsEl = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-picklist',
      'data-role': 'wizard-results'
    });
    quoteField = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      'data-role': 'wizard-quote'
    });
    var qtyInput = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
      type: 'number',
      "class": 'de-input de-input--qty',
      min: '1',
      step: '1',
      value: state.quantity,
      'aria-label': 'Quantity',
      'data-role': 'wizard-quantity'
    });
    qtyInput.addEventListener('input', function () {
      state.quantity = qtyInput.value;
      paintContinue();
    });
    modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-wizard__search'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)('search'), search]));
    modal.body.appendChild(resultsEl);
    modal.body.appendChild(drawCreate());
    modal.body.appendChild(quoteField);
    modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
      "class": 'de-field de-field--inline'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      "class": 'de-field__label',
      text: 'Quantity'
    }), qtyInput]));
    continueBtn = button(needsTerms ? 'Continue' : state.mode === 'replace' ? 'Replace product' : 'Add product', 'primary', onChosen, true);
    continueBtn.setAttribute('data-role', 'wizard-continue');
    footer([button('Cancel', '', function () {
      return modal.close();
    }), continueBtn]);
    paintResults();
    paintQuote();
    paintContinue();
    if (state.results.length === 0) loadResults();
  }
  function drawCreate() {
    var holder = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-create',
      'data-role': 'wizard-create'
    });
    var _paint = function paint() {
      while (holder.firstChild) holder.removeChild(holder.firstChild);
      if (!state.creating) {
        holder.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
          type: 'button',
          "class": 'de-link',
          'data-role': 'wizard-create-open',
          onclick: function onclick() {
            state.creating = true;
            _paint();
          }
        }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.icon)('plus'), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
          text: 'Create custom package/product'
        })]));
        return;
      }
      var name = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
        type: 'text',
        "class": 'de-input',
        maxlength: '200',
        placeholder: 'e.g. Wedding photography package',
        'data-role': 'create-name',
        'aria-label': 'Name'
      });
      var description = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('textarea', {
        "class": 'de-input',
        rows: '2',
        maxlength: '2000',
        placeholder: 'Optional',
        'data-role': 'create-description',
        'aria-label': 'Description'
      });
      var price = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
        type: 'text',
        inputmode: 'decimal',
        "class": 'de-input',
        placeholder: '0.00',
        'data-role': 'create-price',
        'aria-label': 'Price'
      });
      var error = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
        "class": 'de-field__error',
        'data-role': 'create-error',
        role: 'alert',
        hidden: true
      });
      var submit = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
        type: 'button',
        "class": 'de-btn de-btn--primary de-btn--sm',
        'data-role': 'create-submit',
        text: 'Create and select'
      });
      submit.addEventListener('click', /*#__PURE__*/_asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
        var minor, result, item;
        return _regenerator().w(function (_context) {
          while (1) switch (_context.n) {
            case 0:
              error.hidden = true;
              minor = (0,_money__WEBPACK_IMPORTED_MODULE_3__.toMinor)(price.value, exponent);
              if (!(name.value.trim() === '')) {
                _context.n = 1;
                break;
              }
              error.textContent = 'Give it a name.';
              error.hidden = false;
              return _context.a(2);
            case 1:
              if (!(minor === null || minor <= 0)) {
                _context.n = 2;
                break;
              }
              error.textContent = 'Enter a price greater than zero, for example 250.00.';
              error.hidden = false;
              return _context.a(2);
            case 2:
              submit.disabled = true;
              _context.n = 3;
              return api.post(store.urls.catalog_store, {
                type: 'product',
                name: name.value.trim(),
                description: description.value.trim() || null,
                price: (0,_money__WEBPACK_IMPORTED_MODULE_3__.toDecimalString)(minor, exponent)
              });
            case 3:
              result = _context.v;
              submit.disabled = false;
              if (result.ok) {
                _context.n = 4;
                break;
              }
              error.textContent = result.status === 403 || result.status === 404 ? 'You do not have permission to create products. Choose an existing one instead.' : (0,_api__WEBPACK_IMPORTED_MODULE_2__.errorMessage)(result, 'The product could not be created.');
              error.hidden = false;
              return _context.a(2);
            case 4:
              item = result.json.item;
              state.results = [item].concat(state.results.filter(function (r) {
                return r.uid !== item.uid;
              }));
              state.creating = false;
              _paint();
              choose(item);
            case 5:
              return _context.a(2);
          }
        }, _callee);
      })));
      holder.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
        "class": 'de-create__form'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
        "class": 'de-create__title'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('strong', {
        text: 'New package/product'
      }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
        type: 'button',
        "class": 'de-link',
        onclick: function onclick() {
          state.creating = false;
          _paint();
        },
        text: 'Cancel'
      })]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
        "class": 'de-field'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        "class": 'de-field__label',
        text: 'Name'
      }), name]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
        "class": 'de-field'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        "class": 'de-field__label',
        text: 'Description (optional)'
      }), description]), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
        "class": 'de-field'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        "class": 'de-field__label',
        text: 'Price'
      }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
        "class": 'de-money'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        "class": 'de-money__cur',
        'data-role': 'create-currency',
        title: 'Your business currency',
        text: store.currency
      }), price])]), error, submit]));
      setTimeout(function () {
        return name.focus();
      }, 0);
    };
    _paint();
    return holder;
  }
  function onChosen() {
    if (!canContinue()) return;
    setError('');
    if (needsTerms) {
      state.step = 2;
      draw();
    } else {
      finish();
    }
  }

  // ---- dates -----------------------------------------------------------------
  function loadDates() {
    return _loadDates.apply(this, arguments);
  }
  function _loadDates() {
    _loadDates = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee3() {
      var result, first;
      return _regenerator().w(function (_context3) {
        while (1) switch (_context3.n) {
          case 0:
            if (!(state.dates !== null)) {
              _context3.n = 1;
              break;
            }
            return _context3.a(2);
          case 1:
            state.dates = [];
            _context3.n = 2;
            return api.get(store.urls.contact_dates);
          case 2:
            result = _context3.v;
            if (result.ok) {
              _context3.n = 3;
              break;
            }
            return _context3.a(2);
          case 3:
            state.dates = result.json.dates || [];
            first = state.dates[0];
            if (first) {
              if (!state.plan.full_due_date) state.plan.full_due_date = first.date;
              if (!state.plan.balance_due_date) state.plan.balance_due_date = first.date;
            }
            if (state.step === 2 || state.step === 3) draw();
          case 4:
            return _context3.a(2);
        }
      }, _callee3);
    }));
    return _loadDates.apply(this, arguments);
  }
  function dateLabel(candidate) {
    var when = (0,_money__WEBPACK_IMPORTED_MODULE_3__.formatDate)(candidate.date, store.locale);
    return candidate.source === 'appointment' ? 'From appointment ' + when : 'From ' + candidate.label + ' ' + when;
  }

  /** Date input + source label + quick picks. `key` is the plan field it fills. */
  function datePicker(key, dueKey) {
    var input = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
      type: 'date',
      "class": 'de-input de-input--date',
      value: state.plan[key],
      'data-role': 'date-' + key,
      'aria-label': 'Date'
    });
    input.addEventListener('input', function () {
      state.plan[key] = input.value;
      if (input.value && state.plan[dueKey] !== 'date') {
        state.plan[dueKey] = 'date'; // the person chose a date: that is their choice of timing
        draw();
        return;
      }
      validate();
    });
    var dates = state.dates || [];
    var current = dates.find(function (d) {
      return d.date === state.plan[key];
    });
    var picks = dates.filter(function (d) {
      return d.date !== state.plan[key];
    });
    return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-datepick'
    }, [input, current ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
      "class": 'de-datepick__source',
      'data-role': 'date-source',
      text: dateLabel(current)
    }) : null, picks.length > 0 ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-datepick__quick'
    }, picks.map(function (d) {
      return (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
        type: 'button',
        "class": 'de-chipbtn',
        'data-role': 'date-quick',
        title: 'Use this date',
        onclick: function onclick() {
          state.plan[key] = d.date;
          state.plan[dueKey] = 'date';
          draw();
        },
        text: dateLabel(d)
      });
    })) : null]);
  }

  // ---- step 2: how paid ------------------------------------------------------

  var doneBtn = null;
  function dueFull() {
    var wrap = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-due',
      'data-role': 'full-due'
    }, [radio('full_due', 'on_signing', 'Immediately after signing', state.plan.full_due === 'on_signing', function (v) {
      state.plan.full_due = v;
      draw();
    }), radio('full_due', 'date', 'On a date', state.plan.full_due === 'date', function (v) {
      state.plan.full_due = v;
      draw();
    })]);
    wrap.appendChild(datePicker('full_due_date', 'full_due'));
    return wrap;
  }
  function drawPayment() {
    loadDates();
    var total = totalMinor();
    modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-radios'
    }, [radio('structure', 'full', 'Full payment', state.plan.structure === 'full', function (v) {
      state.plan.structure = v;
      draw();
    }, 'The whole amount is paid in one go.'), radio('structure', 'deposit', 'Deposit + remaining balance', state.plan.structure === 'deposit', function (v) {
      state.plan.structure = v;
      draw();
    }, 'Collect a deposit first, then the rest.')]));
    if (state.plan.structure === 'full') {
      modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
        "class": 'de-summary',
        'data-role': 'wizard-total'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        text: 'Total'
      }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('strong', {
        text: total === null ? '' : store.money(total)
      })]));
      modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
        "class": 'de-field__label',
        text: 'When is it due?'
      }));
      modal.body.appendChild(dueFull());
    }
    var back = state.mode === 'terms' ? button('Cancel', '', function () {
      return modal.close();
    }) : button('Back', '', function () {
      state.step = 1;
      setError('');
      draw();
    });
    if (state.plan.structure === 'deposit') {
      doneBtn = button('Next', 'primary', function () {
        state.step = 3;
        setError('');
        draw();
      });
    } else {
      doneBtn = button('Done', 'primary', finish);
    }
    doneBtn.setAttribute('data-role', 'wizard-done');
    footer([back, doneBtn]);
    validate();
  }

  // ---- step 3: deposit -------------------------------------------------------

  var balanceEl = null;
  var depositErr = null;
  function drawDeposit() {
    loadDates();
    var total = totalMinor();
    var deposit = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
      type: 'text',
      inputmode: 'decimal',
      "class": 'de-input',
      value: state.plan.deposit,
      placeholder: '0.00',
      'data-role': 'deposit-input',
      'aria-label': 'Deposit amount'
    });
    deposit.addEventListener('input', function () {
      state.plan.deposit = deposit.value;
      validate();
    });
    balanceEl = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('strong', {
      'data-role': 'balance-amount'
    });
    depositErr = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-field__error',
      'data-role': 'deposit-error',
      role: 'alert',
      hidden: true
    });
    modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-summary',
      'data-role': 'wizard-total'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      text: 'Product total'
    }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('strong', {
      text: total === null ? '' : store.money(total)
    })]));
    modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
      "class": 'de-field'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      "class": 'de-field__label',
      text: 'Deposit amount'
    }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-money'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      "class": 'de-money__cur',
      title: 'Your business currency',
      text: store.currency
    }), deposit]), depositErr]));
    modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-summary',
      'data-role': 'wizard-balance'
    }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
      text: 'Remaining balance'
    }), balanceEl]));
    modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-field__label',
      text: 'When is the balance due?'
    }));
    modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
      "class": 'de-due',
      'data-role': 'balance-due'
    }, [radio('balance_due', 'after_deposit', 'Immediately after deposit', state.plan.balance_due === 'after_deposit', function (v) {
      state.plan.balance_due = v;
      draw();
    }), radio('balance_due', 'date', 'On a date', state.plan.balance_due === 'date', function (v) {
      state.plan.balance_due = v;
      draw();
    }), datePicker('balance_due_date', 'balance_due')]));
    doneBtn = button('Done', 'primary', finish);
    doneBtn.setAttribute('data-role', 'wizard-done');
    footer([button('Back', '', function () {
      state.step = 2;
      setError('');
      draw();
    }), doneBtn]);
    validate();
    setTimeout(function () {
      return deposit.focus();
    }, 0);
  }
  function validate() {
    var message = null;
    if (state.step === 3) {
      var total = totalMinor() || 0;
      var result = (0,_money__WEBPACK_IMPORTED_MODULE_3__.depositState)(total, state.plan.deposit, exponent);
      if (balanceEl) balanceEl.textContent = result.balanceMinor !== null && result.error === null ? store.money(result.balanceMinor) : '—';
      if (depositErr) {
        var show = result.error !== null && String(state.plan.deposit).trim() !== '';
        depositErr.textContent = show ? result.error : '';
        depositErr.hidden = !show;
      }
      message = result.error;
      if (message === null && state.plan.balance_due === 'date' && !validDate(state.plan.balance_due_date)) message = 'Choose the date the balance is due.';
    } else if (state.step === 2 && state.plan.structure === 'full') {
      if (state.plan.full_due === 'date' && !validDate(state.plan.full_due_date)) message = 'Choose the date the payment is due.';
    }
    if (doneBtn && (state.plan.structure !== 'deposit' || state.step === 3)) {
      doneBtn.disabled = message !== null || state.busy;
    }
    if (doneBtn && state.step === 2 && state.plan.structure === 'deposit') {
      doneBtn.disabled = false;
    }
    return message === null;
  }
  function validDate(value) {
    return /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));
  }

  // ---- finish ----------------------------------------------------------------

  function planBody() {
    var plan = state.plan;
    if (plan.structure === 'deposit') {
      var result = (0,_money__WEBPACK_IMPORTED_MODULE_3__.depositState)(totalMinor() || 0, plan.deposit, exponent);
      var _body = {
        structure: 'deposit',
        deposit: (0,_money__WEBPACK_IMPORTED_MODULE_3__.toDecimalString)(result.depositMinor, exponent),
        full_due: 'on_signing',
        balance_due: plan.balance_due
      };
      if (plan.balance_due === 'date') _body.balance_due_date = plan.balance_due_date;
      return _body;
    }
    var body = {
      structure: 'full',
      full_due: plan.full_due
    };
    if (plan.full_due === 'date') body.full_due_date = plan.full_due_date;
    return body;
  }
  function finish() {
    return _finish.apply(this, arguments);
  }
  function _finish() {
    _finish = _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee4() {
      var body, added, removed, saved;
      return _regenerator().w(function (_context4) {
        while (1) switch (_context4.p = _context4.n) {
          case 0:
            if (!state.busy) {
              _context4.n = 1;
              break;
            }
            return _context4.a(2);
          case 1:
            if (!(needsTerms && !validate() && !(state.step === 2 && state.plan.structure === 'deposit'))) {
              _context4.n = 2;
              break;
            }
            return _context4.a(2);
          case 2:
            state.busy = true;
            setError('');
            if (doneBtn) doneBtn.disabled = true;
            if (continueBtn) continueBtn.disabled = true;
            _context4.p = 3;
            if (!(state.item && !state.itemAdded)) {
              _context4.n = 7;
              break;
            }
            body = {
              catalog_item_uid: state.item.uid,
              quantity: qty()
            };
            if (state.item.quote_only) {
              body.price = (0,_money__WEBPACK_IMPORTED_MODULE_3__.toDecimalString)((0,_money__WEBPACK_IMPORTED_MODULE_3__.toMinor)(state.priceInput, exponent), exponent);
            }
            _context4.n = 4;
            return api.mutate('POST', store.urls.lines_catalog, body);
          case 4:
            added = _context4.v;
            if (added.ok) {
              _context4.n = 5;
              break;
            }
            if (added.status !== 409) setError((0,_api__WEBPACK_IMPORTED_MODULE_2__.errorMessage)(added, 'The product could not be added.'));else modal.close();
            return _context4.a(2);
          case 5:
            state.itemAdded = true;
            store.adoptCommerce(added.json);
            if (!(state.mode === 'replace' && state.replaceLine)) {
              _context4.n = 7;
              break;
            }
            _context4.n = 6;
            return api.mutate('DELETE', store.urls.line_template.replace('__LINE__', state.replaceLine.uid), {});
          case 6:
            removed = _context4.v;
            if (removed.ok) store.adoptCommerce(removed.json);else if (removed.status !== 409) setError((0,_api__WEBPACK_IMPORTED_MODULE_2__.errorMessage)(removed, 'The old line could not be removed.'));
          case 7:
            if (!needsTerms) {
              _context4.n = 10;
              break;
            }
            _context4.n = 8;
            return api.mutate('PUT', store.urls.plan, planBody());
          case 8:
            saved = _context4.v;
            if (saved.ok) {
              _context4.n = 9;
              break;
            }
            if (saved.status !== 409) setError((0,_api__WEBPACK_IMPORTED_MODULE_2__.errorMessage)(saved, 'The payment terms could not be saved.'));else modal.close();
            return _context4.a(2);
          case 9:
            store.adoptCommerce(saved.json);
          case 10:
            modal.close();
            if (ctx.notify) ctx.notify(state.mode === 'terms' ? 'Payment terms updated.' : 'Product added.', 'success');
          case 11:
            _context4.p = 11;
            state.busy = false;
            if (doneBtn) doneBtn.disabled = false;
            paintContinue();
            return _context4.f(11);
          case 12:
            return _context4.a(2);
        }
      }, _callee4, null, [[3,, 11, 12]]);
    }));
    return _finish.apply(this, arguments);
  }
  draw();
  return modal;
}

/***/ },

/***/ "./resources/js/documents/editor/save-template.js"
/*!********************************************************!*\
  !*** ./resources/js/documents/editor/save-template.js ***!
  \********************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   openSaveTemplateDialog: () => (/* binding */ openSaveTemplateDialog)
/* harmony export */ });
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./api */ "./resources/js/documents/editor/api.js");
/* harmony import */ var _dom__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./dom */ "./resources/js/documents/editor/dom.js");
/* harmony import */ var _modal__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./modal */ "./resources/js/documents/editor/modal.js");
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
// Contract 17B §6 — the "Save as template" dialog (document mode only).
//
// A template saves the LAYOUT: text, headings, your images, merge fields, where
// the signature goes and a generic product area. It never saves the product,
// the contact, prices, payment terms or anything about sending / signing. The
// server builds the template from the document's saved blocks only
// (DocumentTemplateService::saveFromDocument); this just collects a name, a
// type and an optional description.




function openSaveTemplateDialog(ctx) {
  var store = ctx.store,
    api = ctx.api,
    autosave = ctx.autosave,
    modalRoot = ctx.modalRoot,
    notify = ctx.notify;
  var modal = (0,_modal__WEBPACK_IMPORTED_MODULE_2__.openModal)(modalRoot, {
    title: 'Save as template',
    size: 'sm',
    className: 'de-save-template'
  });
  var name = (0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('input', {
    "class": 'de-input',
    type: 'text',
    maxlength: '191',
    required: true,
    value: store.title,
    'data-role': 'template-name',
    'aria-label': 'Template name'
  });
  var type = (0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('select', {
    "class": 'de-input',
    'data-role': 'template-type-select',
    'aria-label': 'Template type'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('option', {
    value: 'proposal',
    text: 'Proposal'
  }), (0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('option', {
    value: 'contract',
    text: 'Contract'
  })]);
  var description = (0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('textarea', {
    "class": 'de-input',
    rows: '2',
    maxlength: '1000',
    'data-role': 'template-description',
    'aria-label': 'Description (optional)',
    placeholder: 'Optional: when to use this template'
  });
  var error = (0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('p', {
    "class": 'de-field__error',
    role: 'alert',
    hidden: true,
    'data-role': 'template-error'
  });
  modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('p', {
    "class": 'de-muted',
    'data-role': 'template-explainer',
    text: 'This saves the layout, not the product or contact. When you use the template you choose the contact and add the product again.'
  }));
  modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('label', {
    "class": 'de-field'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('span', {
    "class": 'de-field__label',
    text: 'Template name'
  }), name]));
  modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('label', {
    "class": 'de-field'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('span', {
    "class": 'de-field__label',
    text: 'Type'
  }), type]));
  modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('label', {
    "class": 'de-field'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('span', {
    "class": 'de-field__label',
    text: 'Description (optional)'
  }), description]));
  modal.body.appendChild(error);
  var save = (0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('button', {
    type: 'button',
    "class": 'de-btn de-btn--primary',
    'data-role': 'template-save',
    text: 'Save template'
  });
  modal.footer.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_1__.el)('button', {
    type: 'button',
    "class": 'de-btn',
    text: 'Cancel',
    onclick: function onclick() {
      return modal.close();
    }
  }));
  modal.footer.appendChild(save);
  var show = function show(text) {
    error.textContent = text || '';
    error.hidden = !text;
  };
  var busy = false;
  save.addEventListener('click', /*#__PURE__*/_asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
    var saved, result;
    return _regenerator().w(function (_context) {
      while (1) switch (_context.p = _context.n) {
        case 0:
          if (!busy) {
            _context.n = 1;
            break;
          }
          return _context.a(2);
        case 1:
          if (!(name.value.trim() === '')) {
            _context.n = 2;
            break;
          }
          show('Give the template a name.');
          name.focus();
          return _context.a(2);
        case 2:
          busy = true;
          save.disabled = true;
          show('');
          _context.p = 3;
          _context.n = 4;
          return autosave.flush();
        case 4:
          saved = _context.v;
          if (!(!saved && store.editable)) {
            _context.n = 5;
            break;
          }
          show(store.save.state === 'conflict' ? 'This document was changed in another tab. Reload before saving a template.' : 'Your latest changes could not be saved, so the template was not created.');
          return _context.a(2);
        case 5:
          _context.n = 6;
          return api.post(store.urls.save_template, {
            name: name.value.trim(),
            template_type: type.value,
            description: description.value.trim() === '' ? null : description.value.trim()
          });
        case 6:
          result = _context.v;
          if (result.ok) {
            _context.n = 7;
            break;
          }
          show((0,_api__WEBPACK_IMPORTED_MODULE_0__.errorMessage)(result, 'The template could not be saved.'));
          return _context.a(2);
        case 7:
          modal.close();
          notify('Template saved.', 'success', {
            href: result.json.library_url || store.urls.template_library,
            text: 'Open template library'
          });
        case 8:
          _context.p = 8;
          busy = false;
          save.disabled = false;
          return _context.f(8);
        case 9:
          return _context.a(2);
      }
    }, _callee, null, [[3,, 8, 9]]);
  })));
  return modal;
}

/***/ },

/***/ "./resources/js/documents/editor/send-dialog.js"
/*!******************************************************!*\
  !*** ./resources/js/documents/editor/send-dialog.js ***!
  \******************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   checklist: () => (/* binding */ checklist),
/* harmony export */   openSendDialog: () => (/* binding */ openSendDialog)
/* harmony export */ });
/* harmony import */ var _dom__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./dom */ "./resources/js/documents/editor/dom.js");
/* harmony import */ var _modal__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./modal */ "./resources/js/documents/editor/modal.js");
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./api */ "./resources/js/documents/editor/api.js");
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
function _slicedToArray(r, e) { return _arrayWithHoles(r) || _iterableToArrayLimit(r, e) || _unsupportedIterableToArray(r, e) || _nonIterableRest(); }
function _nonIterableRest() { throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
function _iterableToArrayLimit(r, l) { var t = null == r ? null : "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (null != t) { var e, n, i, u, a = [], f = !0, o = !1; try { if (i = (t = t.call(r)).next, 0 === l) { if (Object(t) !== t) return; f = !1; } else for (; !(f = (e = i.call(t)).done) && (a.push(e.value), a.length !== l); f = !0); } catch (r) { o = !0, n = r; } finally { try { if (!f && null != t["return"] && (u = t["return"](), Object(u) !== u)) return; } finally { if (o) throw n; } } return a; } }
function _arrayWithHoles(r) { if (Array.isArray(r)) return r; }
// Contract 17B §7 — the Send dialog. One Draft -> Sent transition delivered by
// Email and / or SMS (at least one). It first shows a friendly checklist of
// what a sendable proposal needs, prefilled recipient details (typed only when
// the document has none), an optional text-message wording, then reports each
// channel's outcome. Any pending autosave is flushed before sending.




var SMS_REASONS = {
  contact_missing: 'The contact for this document could not be found.',
  contact_unsubscribed: 'This contact has not agreed to receive text messages.',
  phone_invalid: 'The phone number on file cannot be used for text messages.',
  no_business_sending_path: 'Text messaging is not set up for this business yet.',
  sender_rejected: 'The text messaging sender was rejected.',
  send_failed: 'Text messages cannot be sent right now.'
};
function checklist(store) {
  var lines = (store.commerce.lines || []).length;
  var schedule = (store.commerce.schedule || []).length;
  var items = [];
  if (store.requiresSignature) {
    items.push({
      id: 'signature',
      ok: store.hasType('signature'),
      text: 'A signature block',
      fix: 'Add a Signature block from the left.'
    });
  }
  items.push({
    id: 'lines',
    ok: lines > 0,
    text: 'At least one product',
    fix: 'Use Add product to choose what you are selling.'
  });
  items.push({
    id: 'schedule',
    ok: schedule > 0 && !store.commerce.plan_invalid,
    text: 'Payment terms',
    fix: store.commerce.plan_invalid ? 'Update the payment terms so they fit the total.' : 'Choose how this should be paid.'
  });
  return items;
}
function openSendDialog(ctx) {
  var store = ctx.store,
    api = ctx.api,
    autosave = ctx.autosave;
  var delivery = store.delivery || {};
  var modal = (0,_modal__WEBPACK_IMPORTED_MODULE_1__.openModal)(ctx.modalRoot, {
    title: 'Send for signature',
    size: 'md',
    className: 'de-send'
  });
  var smsReason = delivery.sms_unavailable_reason && delivery.sms_unavailable_reason !== 'phone_missing' ? delivery.sms_unavailable_reason : null;
  var smsMax = delivery.sms_max_message || 320;
  var state = {
    email: true,
    sms: false,
    busy: false,
    sent: false
  };
  var emailBox = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
    type: 'checkbox',
    checked: true,
    'data-role': 'send-email-channel'
  });
  var smsBox = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
    type: 'checkbox',
    disabled: smsReason !== null,
    'data-role': 'send-sms-channel'
  });
  var emailInput = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
    type: 'email',
    "class": 'de-input',
    value: delivery.email || '',
    readonly: !!delivery.email,
    placeholder: 'name@example.com',
    'data-role': 'send-email',
    'aria-label': 'Recipient email'
  });
  var phoneInput = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('input', {
    type: 'tel',
    "class": 'de-input',
    value: delivery.phone || '',
    readonly: !!delivery.phone,
    placeholder: '+1 555 010 0100',
    'data-role': 'send-phone',
    'aria-label': 'Recipient phone'
  });
  var message = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('textarea', {
    "class": 'de-input',
    rows: '3',
    maxlength: String(smsMax),
    'data-role': 'send-message',
    'aria-label': 'Text message'
  });
  message.value = delivery.sms_default_message || '';
  var counter = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
    "class": 'de-field__hint'
  });
  var checklistEl = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('ul', {
    "class": 'de-check-list',
    'data-role': 'send-checklist'
  });
  var error = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-wizard__error',
    role: 'alert',
    'data-role': 'send-error',
    hidden: true
  });
  var sendBtn = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
    type: 'button',
    "class": 'de-btn de-btn--primary',
    'data-role': 'send-submit',
    text: 'Send'
  });
  var emailRow = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-send__detail'
  });
  var smsRow = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-send__detail',
    hidden: true
  });
  emailRow.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
    "class": 'de-field'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
    "class": 'de-field__label',
    text: 'Email address'
  }), emailInput, delivery.email ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
    "class": 'de-field__hint',
    text: 'From this contact.'
  }) : (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
    "class": 'de-field__hint',
    text: 'This contact has no email yet. Enter one to send to.'
  })]));
  smsRow.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
    "class": 'de-field'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
    "class": 'de-field__label',
    text: 'Phone number'
  }), phoneInput, delivery.phone ? (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
    "class": 'de-field__hint',
    text: 'From this contact.'
  }) : (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
    "class": 'de-field__hint',
    text: 'This contact has no phone number yet. Enter one to send to.'
  })]));
  smsRow.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
    "class": 'de-field'
  }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
    "class": 'de-field__label',
    text: 'Message'
  }), message, counter, (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
    "class": 'de-field__hint',
    text: 'A link to the document is added to the end automatically.'
  })]));
  modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-field__label',
    text: 'Before you send'
  }));
  modal.body.appendChild(checklistEl);
  modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('div', {
    "class": 'de-field__label',
    text: 'Send by'
  }));
  modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
    "class": 'de-check de-check--box'
  }, [emailBox, (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
    text: 'Email'
  })]));
  modal.body.appendChild(emailRow);
  modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('label', {
    "class": 'de-check de-check--box' + (smsReason ? ' is-disabled' : '')
  }, [smsBox, (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
    text: 'Text message'
  })]));
  if (smsReason) {
    modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
      "class": 'de-field__hint',
      'data-role': 'sms-unavailable',
      text: SMS_REASONS[smsReason] || SMS_REASONS.send_failed
    }));
  }
  modal.body.appendChild(smsRow);
  modal.footer.appendChild(error);
  modal.footer.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
    type: 'button',
    "class": 'de-btn',
    onclick: function onclick() {
      return modal.close();
    },
    text: 'Cancel'
  }));
  modal.footer.appendChild(sendBtn);
  var emailLooksValid = function emailLooksValid(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
  };
  function problems() {
    var found = checklist(store).filter(function (item) {
      return !item.ok;
    });
    if (!emailBox.checked && !smsBox.checked) {
      found.push({
        id: 'channel',
        fix: 'Choose email, text message or both.'
      });
    }
    if (emailBox.checked && !emailLooksValid(emailInput.value)) {
      found.push({
        id: 'email',
        fix: 'Enter a valid email address.'
      });
    }
    if (smsBox.checked && phoneInput.value.trim().length < 5) {
      found.push({
        id: 'phone',
        fix: 'Enter a phone number for the text message.'
      });
    }
    return found;
  }
  function paint() {
    while (checklistEl.firstChild) checklistEl.removeChild(checklistEl.firstChild);
    checklist(store).forEach(function (item) {
      checklistEl.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('li', {
        "class": item.ok ? 'is-ok' : 'is-missing',
        'data-check': item.id,
        'data-ok': item.ok ? '1' : '0'
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        "class": 'de-check-list__mark',
        'aria-hidden': 'true',
        text: item.ok ? '✓' : '!'
      }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', null, [item.text, item.ok ? null : (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('small', {
        text: ' ' + item.fix
      })])]));
    });
    smsRow.hidden = !smsBox.checked;
    counter.textContent = message.value.length + ' / ' + smsMax;
    sendBtn.disabled = state.busy || problems().length > 0;
  }
  [emailBox, smsBox, emailInput, phoneInput, message].forEach(function (node) {
    return node.addEventListener('input', paint);
  });
  [emailBox, smsBox].forEach(function (node) {
    return node.addEventListener('change', paint);
  });
  store.subscribe(function () {
    if (!state.sent) paint();
  });
  var show = function show(text) {
    error.textContent = text || '';
    error.hidden = !text;
  };
  function showResult(json) {
    while (modal.body.firstChild) modal.body.removeChild(modal.body.firstChild);
    while (modal.footer.firstChild) modal.footer.removeChild(modal.footer.firstChild);
    modal.setTitle('Sent');
    var results = json.delivery || {};
    var list = (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('ul', {
      "class": 'de-result',
      'data-role': 'send-result'
    });
    [['email', 'Email'], ['sms', 'Text message']].forEach(function (_ref) {
      var _ref2 = _slicedToArray(_ref, 2),
        key = _ref2[0],
        label = _ref2[1];
      var result = results[key];
      if (!result) return;
      var ok = result.status === 'queued';
      list.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('li', {
        "class": ok ? 'is-ok' : 'is-failed',
        'data-channel': key,
        'data-status': result.status
      }, [(0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('strong', {
        text: label
      }), (0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('span', {
        text: ok ? ' is on its way.' : ' could not be sent. ' + (result.message || 'Try again from the document page.')
      })]));
    });
    modal.body.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('p', {
      text: 'The document is now with your client and can no longer be edited here.'
    }));
    modal.body.appendChild(list);
    modal.footer.appendChild((0,_dom__WEBPACK_IMPORTED_MODULE_0__.el)('button', {
      type: 'button',
      "class": 'de-btn de-btn--primary',
      'data-role': 'send-done',
      text: 'Done',
      onclick: function onclick() {
        return window.location.reload();
      }
    }));
  }
  sendBtn.addEventListener('click', /*#__PURE__*/_asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
    var saved, channels, body, result;
    return _regenerator().w(function (_context) {
      while (1) switch (_context.p = _context.n) {
        case 0:
          if (!(problems().length > 0 || state.busy)) {
            _context.n = 1;
            break;
          }
          return _context.a(2);
        case 1:
          state.busy = true;
          show('');
          paint();
          _context.p = 2;
          _context.n = 3;
          return autosave.flush();
        case 3:
          saved = _context.v;
          if (saved) {
            _context.n = 4;
            break;
          }
          show(store.save.state === 'conflict' ? 'This document was changed in another tab. Reload before sending.' : 'Your latest changes could not be saved, so the document was not sent.');
          return _context.a(2);
        case 4:
          channels = [];
          if (emailBox.checked) channels.push('email');
          if (smsBox.checked) channels.push('sms');
          body = {
            channels: channels
          };
          if (emailBox.checked && !delivery.email) body.recipient_email = emailInput.value.trim();
          if (smsBox.checked && !delivery.phone) body.recipient_phone = phoneInput.value.trim();
          if (smsBox.checked && message.value.trim() !== '' && message.value !== (delivery.sms_default_message || '')) body.message = message.value.trim();
          _context.n = 5;
          return api.mutate('POST', store.urls.send, body);
        case 5:
          result = _context.v;
          if (!result.ok) {
            _context.n = 6;
            break;
          }
          state.sent = true;
          store.editable = false;
          store.dirty = false;
          showResult(result.json);
          return _context.a(2);
        case 6:
          if (!(result.status === 409)) {
            _context.n = 7;
            break;
          }
          modal.close();
          return _context.a(2);
        case 7:
          show((0,_api__WEBPACK_IMPORTED_MODULE_2__.errorMessage)(result, 'The document could not be sent.'));
        case 8:
          _context.p = 8;
          state.busy = false;
          if (!state.sent) paint();
          return _context.f(8);
        case 9:
          return _context.a(2);
      }
    }, _callee, null, [[2,, 8, 9]]);
  })));
  paint();
  return modal;
}

/***/ },

/***/ "./resources/js/documents/editor/serializer.js"
/*!*****************************************************!*\
  !*** ./resources/js/documents/editor/serializer.js ***!
  \*****************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   MAX_RUN_TEXT: () => (/* binding */ MAX_RUN_TEXT),
/* harmony export */   domToRuns: () => (/* binding */ domToRuns),
/* harmony export */   isSafeHref: () => (/* binding */ isSafeHref),
/* harmony export */   normalizeHref: () => (/* binding */ normalizeHref),
/* harmony export */   runsToDom: () => (/* binding */ runsToDom),
/* harmony export */   runsToText: () => (/* binding */ runsToText)
/* harmony export */ });
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function _slicedToArray(r, e) { return _arrayWithHoles(r) || _iterableToArrayLimit(r, e) || _unsupportedIterableToArray(r, e) || _nonIterableRest(); }
function _nonIterableRest() { throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }
function _iterableToArrayLimit(r, l) { var t = null == r ? null : "undefined" != typeof Symbol && r[Symbol.iterator] || r["@@iterator"]; if (null != t) { var e, n, i, u, a = [], f = !0, o = !1; try { if (i = (t = t.call(r)).next, 0 === l) { if (Object(t) !== t) return; f = !1; } else for (; !(f = (e = i.call(t)).done) && (a.push(e.value), a.length !== l); f = !0); } catch (r) { o = !0, n = r; } finally { try { if (!f && null != t["return"] && (u = t["return"](), Object(u) !== u)) return; } finally { if (o) throw n; } } return a; } }
function _arrayWithHoles(r) { if (Array.isArray(r)) return r; }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
// Contract 17B — the DOM <-> runs seam for inline text.
//
// `domToRuns` turns what a contenteditable holds into EXACTLY the run format
// BlockSchema accepts ({t, b?, i?, u?, href?} or {merge, ...}); `runsToDom`
// does the reverse. Both are pure over a tiny duck-typed node interface
// (nodeType, nodeName, nodeValue, childNodes, getAttribute) so the Node test
// can drive `domToRuns` without a browser.

var MAX_RUN_TEXT = 2000;
var TEXT = 3;
var ELEMENT = 1;

/** Mirror of BlockSchema::isSafeHref — http(s), mailto, tel only. */
function isSafeHref(href) {
  if (typeof href !== 'string') {
    return false;
  }
  var value = href.trim();
  if (value === '' || value.length > 2000 || /[\u0000- \u007f\\]/.test(value)) {
    return false;
  }
  var match = /^(https?):\/\/([^/?#]+)/i.exec(value);
  if (match) {
    try {
      var url = new URL(value);
      return (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname !== '';
    } catch (e) {
      return false;
    }
  }
  match = /^mailto:([^?]+)(\?.*)?$/i.exec(value);
  if (match) {
    var address = match[1];
    try {
      address = decodeURIComponent(address);
    } catch (e) {
      return false;
    }
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address);
  }
  return /^tel:\+?[0-9().-]{3,32}$/i.test(value);
}

/**
 * What a person typed into the link box -> a safe href, or null.
 * "a@b.co" -> mailto:, "+1 555 0100" -> tel:, "example.com" -> https://.
 */
function normalizeHref(input) {
  var raw = String(input || '').trim();
  if (raw === '') {
    return null;
  }
  var candidate = raw;
  if (!/^[a-z][a-z0-9+.-]*:/i.test(raw)) {
    if (/^[^\s@/]+@[^\s@/]+\.[^\s@/]+$/.test(raw)) {
      candidate = 'mailto:' + raw;
    } else if (/^\+?[0-9][0-9 ().-]{2,30}$/.test(raw)) {
      candidate = 'tel:' + raw.replace(/[ ]/g, '');
    } else {
      candidate = 'https://' + raw;
    }
  }
  return isSafeHref(candidate) ? candidate : null;
}
function sameFormat(a, b) {
  return !!a.b === !!b.b && !!a.i === !!b.i && !!a.u === !!b.u && (a.href || '') === (b.href || '');
}
function makeRun(text, fmt) {
  var run = {
    t: text
  };
  if (fmt.b) run.b = true;
  if (fmt.i) run.i = true;
  if (fmt.u) run.u = true;
  if (fmt.href) run.href = fmt.href;
  return run;
}

/**
 * @param {object} root a node whose children are the editable content
 * @returns {Array<object>} runs in BlockSchema's format
 */
function domToRuns(root) {
  var runs = [];
  var push = function push(text, fmt) {
    if (text === '') {
      return;
    }
    var last = runs[runs.length - 1];
    if (last && last.t !== undefined && last.merge === undefined && sameFormat(last, fmt)) {
      last.t += text;
    } else {
      runs.push(makeRun(text, fmt));
    }
  };
  var endsWithBreak = function endsWithBreak() {
    var last = runs[runs.length - 1];
    return !last || last.t !== undefined && last.t.endsWith('\n');
  };
  var _walk = function walk(node, fmt, isBlockChild) {
    var children = node.childNodes ? Array.from(node.childNodes) : [];
    children.forEach(function (child) {
      if (child.nodeType === TEXT) {
        // Zero-width spaces are caret anchors; non-breaking spaces are plain spaces.
        var text = String(child.nodeValue || '').replace(/​/g, '').replace(/ /g, ' ').replace(/\r/g, '');
        push(text, fmt);
        return;
      }
      if (child.nodeType !== ELEMENT) {
        return;
      }
      var name = String(child.nodeName || '').toUpperCase();
      var token = child.getAttribute ? child.getAttribute('data-token') : null;
      if (token) {
        var run = {
          merge: token
        };
        if (fmt.b) run.b = true;
        if (fmt.i) run.i = true;
        if (fmt.u) run.u = true;
        if (fmt.href) run.href = fmt.href;
        runs.push(run);
        return;
      }
      if (name === 'BR') {
        push('\n', fmt);
        return;
      }
      var next = {
        b: fmt.b,
        i: fmt.i,
        u: fmt.u,
        href: fmt.href
      };
      if (name === 'B' || name === 'STRONG') next.b = true;
      if (name === 'I' || name === 'EM') next.i = true;
      if (name === 'U') next.u = true;
      if (name === 'A') {
        var href = child.getAttribute ? child.getAttribute('href') : null;
        if (href && isSafeHref(href)) {
          next.href = href.trim();
        }
      }

      // Browsers wrap an Enter-created line in <div>/<p>: that is a line break.
      if (name === 'DIV' || name === 'P') {
        if (!endsWithBreak()) {
          push('\n', fmt);
        }
        _walk(child, next, true);
        return;
      }
      _walk(child, next, false);
    });
  };
  _walk(root, {
    b: false,
    i: false,
    u: false,
    href: ''
  }, false);

  // A contenteditable keeps one trailing <br>; it is not content.
  var last = runs[runs.length - 1];
  if (last && last.t !== undefined && last.merge === undefined && last.t.endsWith('\n')) {
    last.t = last.t.slice(0, -1);
    if (last.t === '') {
      runs.pop();
    }
  }

  // Split anything longer than a run may be.
  var out = [];
  runs.forEach(function (run) {
    if (run.t !== undefined && run.t.length > MAX_RUN_TEXT) {
      for (var i = 0; i < run.t.length; i += MAX_RUN_TEXT) {
        out.push(_objectSpread(_objectSpread({}, run), {}, {
          t: run.t.slice(i, i + MAX_RUN_TEXT)
        }));
      }
    } else {
      out.push(run);
    }
  });
  return out;
}

/** Plain text of some runs (merge runs read as their label). */
function runsToText(runs, labelFor) {
  return (runs || []).map(function (run) {
    return run.merge ? labelFor ? labelFor(run.merge) : run.merge : run.t || '';
  }).join('');
}

/**
 * Build the editable DOM for some runs into `container`.
 * `chipText(token)` -> the text a merge chip shows; `chipTitle(token)` its tooltip.
 */
function runsToDom(doc, container, runs, chipText, chipTitle) {
  while (container.firstChild) {
    container.removeChild(container.firstChild);
  }
  (runs || []).forEach(function (run) {
    var node;
    if (run.merge) {
      node = doc.createElement('span');
      node.className = 'doc-merge';
      node.setAttribute('data-token', run.merge);
      node.setAttribute('contenteditable', 'false');
      node.title = chipTitle ? chipTitle(run.merge) : run.merge;
      node.textContent = chipText ? chipText(run.merge) : run.merge;
    } else {
      node = doc.createDocumentFragment();
      String(run.t || '').split('\n').forEach(function (piece, index) {
        if (index > 0) {
          node.appendChild(doc.createElement('br'));
        }
        if (piece !== '') {
          node.appendChild(doc.createTextNode(piece));
        }
      });
    }
    [['b', 'b'], ['i', 'i'], ['u', 'u']].forEach(function (_ref) {
      var _ref2 = _slicedToArray(_ref, 2),
        flag = _ref2[0],
        tag = _ref2[1];
      if (run[flag] === true) {
        var wrap = doc.createElement(tag);
        wrap.appendChild(node);
        node = wrap;
      }
    });
    if (run.href && isSafeHref(run.href)) {
      var anchor = doc.createElement('a');
      anchor.setAttribute('href', run.href);
      anchor.setAttribute('rel', 'noopener noreferrer nofollow');
      anchor.appendChild(node);
      node = anchor;
    }
    container.appendChild(node);
  });
}

/***/ },

/***/ "./resources/js/documents/editor/state.js"
/*!************************************************!*\
  !*** ./resources/js/documents/editor/state.js ***!
  \************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createStore: () => (/* binding */ createStore)
/* harmony export */ });
/* harmony import */ var _money_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./money.js */ "./resources/js/documents/editor/money.js");
// Contract 17B — the editor's single store. Plain object + subscribers; no
// framework. The lock version lives here and is the ONLY source every request
// reads (api.js), so two operations in one tab can never send a stale one.


function createStore(boot) {
  var listeners = [];
  var document_ = boot.document || {};
  var currency = boot.currency_code || document_.currency_code || 'USD';
  var locale = typeof navigator !== 'undefined' && navigator.language || 'en';

  // Contract 17B §6 — one editor, three modes: a document, a Business template, (next stage) a platform template.
  var mode = boot.mode || 'document';
  var template = boot.template || null;
  var store = {
    boot: boot,
    mode: mode,
    isTemplate: mode !== 'document',
    templateType: template ? template.type : null,
    mergeSamples: boot.merge_samples || {},
    urls: boot.urls || {},
    toolbox: boot.toolbox || {},
    images: boot.images || [],
    contact: boot.contact || {
      name: '',
      email: ''
    },
    business: boot.business || {
      name: ''
    },
    delivery: boot.delivery || {},
    docUid: document_.uid,
    status: document_.status,
    requiresSignature: !!document_.requires_signature,
    editable: !!boot.editable,
    title: document_.title || '',
    blocks: Array.isArray(boot.blocks) ? boot.blocks.map(function (b) {
      return JSON.parse(JSON.stringify(b));
    }) : [],
    lock: boot.lock_version === undefined ? null : boot.lock_version,
    commerce: {
      lines: boot.lines || [],
      totals: boot.totals || {},
      schedule: boot.schedule || [],
      plan: boot.plan || null,
      plan_invalid: !!boot.plan_invalid,
      plan_error: boot.plan_error || null
    },
    currency: currency,
    locale: locale,
    exponent: (0,_money_js__WEBPACK_IMPORTED_MODULE_0__.currencyExponent)(currency, locale),
    selectedId: null,
    // saved | dirty | saving | error | conflict
    save: {
      state: 'saved',
      message: ''
    },
    dirty: false,
    money: function money(minor) {
      return (0,_money_js__WEBPACK_IMPORTED_MODULE_0__.formatMinor)(minor, currency, locale);
    },
    subscribe: function subscribe(fn) {
      listeners.push(fn);
    },
    emit: function emit(topic) {
      listeners.forEach(function (fn) {
        return fn(topic, store);
      });
    },
    setSave: function setSave(state, message) {
      store.save = {
        state: state,
        message: message || ''
      };
      store.emit('save');
    },
    /** Adopt the commerce payload every line / plan endpoint answers with. */adoptCommerce: function adoptCommerce(payload) {
      if (!payload) {
        return;
      }
      ['lines', 'totals', 'schedule', 'plan', 'plan_invalid', 'plan_error'].forEach(function (key) {
        if (payload[key] !== undefined) {
          store.commerce[key] = payload[key];
        }
      });
      if (payload.lock_version !== undefined && payload.lock_version !== null) {
        store.lock = payload.lock_version;
      }
      store.emit('commerce');
    },
    block: function block(id) {
      return store.blocks.find(function (b) {
        return b.id === id;
      }) || null;
    },
    indexOf: function indexOf(id) {
      return store.blocks.findIndex(function (b) {
        return b.id === id;
      });
    },
    hasType: function hasType(type) {
      return store.blocks.some(function (b) {
        return b.type === type;
      });
    },
    /** Merge-chip text: the live preview value when we know it, else the field's label. */mergeLabel: function mergeLabel(token) {
      var found = (store.toolbox.merge_fields || []).find(function (f) {
        return f.token === token;
      });
      return found ? found.label : token;
    },
    mergePreview: function mergePreview(token) {
      // A template has no Contact: chips resolve to sample data.
      if (store.isTemplate) {
        return store.mergeSamples[token] || '';
      }
      var name = (store.contact.name || '').trim();
      switch (token) {
        case 'contact.full_name':
          return name;
        case 'contact.first_name':
          return name.split(/\s+/)[0] || '';
        case 'contact.email':
          return store.contact.email || '';
        case 'business.name':
          return store.business.name || '';
        case 'document.title':
          return store.title || '';
        default:
          return '';
      }
    }
  };
  return store;
}

/***/ },

/***/ "./resources/js/documents/editor/toolbox.js"
/*!**************************************************!*\
  !*** ./resources/js/documents/editor/toolbox.js ***!
  \**************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   createToolbox: () => (/* binding */ createToolbox)
/* harmony export */ });
/* harmony import */ var _canvas__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./canvas */ "./resources/js/documents/editor/canvas.js");
// Contract 17B — the left block toolbox. The buttons are server-rendered (so
// they exist without the script); this wires them: click adds after the
// selected block (or at the end), drag drops where the indicator shows,
// Enter/Space work because they are real buttons. On narrow screens the
// toolbox is a drawer opened from the header.


function createToolbox(ctx) {
  var store = ctx.store,
    actions = ctx.actions,
    root = ctx.root;
  var box = root.querySelector('[data-role="toolbox"]');
  var scrim = root.querySelector('[data-role="toolbox-scrim"]');
  var toggle = root.querySelector('[data-role="toolbox-toggle"]');
  var close = root.querySelector('[data-role="toolbox-close"]');
  if (!box) {
    return {
      refresh: function refresh() {}
    };
  }
  var tools = Array.prototype.slice.call(box.querySelectorAll('[data-tool]'));
  function setDrawer(open) {
    box.classList.toggle('is-open', open);
    if (scrim) {
      scrim.hidden = !open;
    }
    if (toggle) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
  }
  tools.forEach(function (button) {
    var id = button.getAttribute('data-tool');
    button.addEventListener('click', function () {
      if (!store.editable) {
        return;
      }
      actions.addTool(id, null);
      setDrawer(false);
    });
    button.addEventListener('dragstart', function (event) {
      if (!store.editable || button.disabled) {
        event.preventDefault();
        return;
      }
      ctx.drag = {
        kind: 'tool',
        id: id
      };
      event.dataTransfer.effectAllowed = 'copy';
      event.dataTransfer.setData(_canvas__WEBPACK_IMPORTED_MODULE_0__.DRAG_TYPES.TOOL, id);
      event.dataTransfer.setData('text/plain', id);
    });
    button.addEventListener('dragend', function () {
      ctx.drag = null;
      ctx.canvas.hideIndicator();
    });
  });
  if (toggle) {
    toggle.addEventListener('click', function () {
      return setDrawer(!box.classList.contains('is-open'));
    });
  }
  if (close) {
    close.addEventListener('click', function () {
      return setDrawer(false);
    });
  }
  if (scrim) {
    scrim.addEventListener('click', function () {
      return setDrawer(false);
    });
  }

  /** The signature can be placed once; everything else stays available. */
  function refresh() {
    tools.forEach(function (button) {
      var id = button.getAttribute('data-tool');
      var taken = id === 'signature' && store.hasType('signature');
      var full = store.blocks.length >= (store.toolbox.limits ? store.toolbox.limits.max_blocks : 200) && button.getAttribute('data-block-type') !== 'product_list';
      button.disabled = !store.editable || taken || full;
      button.title = taken ? 'A document has one signature' : button.querySelector('span').textContent;
      button.classList.toggle('is-used', taken);
    });
  }
  refresh();
  return {
    refresh: refresh,
    setDrawer: setDrawer
  };
}

/***/ },

/***/ "./resources/scss/base/pages/documents-editor.scss"
/*!*********************************************************!*\
  !*** ./resources/scss/base/pages/documents-editor.scss ***!
  \*********************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


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
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = __webpack_modules__;
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/chunk loaded */
/******/ 	(() => {
/******/ 		var deferred = [];
/******/ 		__webpack_require__.O = (result, chunkIds, fn, priority) => {
/******/ 			if(chunkIds) {
/******/ 				priority = priority || 0;
/******/ 				for(var i = deferred.length; i > 0 && deferred[i - 1][2] > priority; i--) deferred[i] = deferred[i - 1];
/******/ 				deferred[i] = [chunkIds, fn, priority];
/******/ 				return;
/******/ 			}
/******/ 			var notFulfilled = Infinity;
/******/ 			for (var i = 0; i < deferred.length; i++) {
/******/ 				var [chunkIds, fn, priority] = deferred[i];
/******/ 				var fulfilled = true;
/******/ 				for (var j = 0; j < chunkIds.length; j++) {
/******/ 					if ((priority & 1 === 0 || notFulfilled >= priority) && Object.keys(__webpack_require__.O).every((key) => (__webpack_require__.O[key](chunkIds[j])))) {
/******/ 						chunkIds.splice(j--, 1);
/******/ 					} else {
/******/ 						fulfilled = false;
/******/ 						if(priority < notFulfilled) notFulfilled = priority;
/******/ 					}
/******/ 				}
/******/ 				if(fulfilled) {
/******/ 					deferred.splice(i--, 1)
/******/ 					var r = fn();
/******/ 					if (r !== undefined) result = r;
/******/ 				}
/******/ 			}
/******/ 			return result;
/******/ 		};
/******/ 	})();
/******/ 	
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
/******/ 	/* webpack/runtime/jsonp chunk loading */
/******/ 	(() => {
/******/ 		// no baseURI
/******/ 		
/******/ 		// object to store loaded and loading chunks
/******/ 		// undefined = chunk not loaded, null = chunk preloaded/prefetched
/******/ 		// [resolve, reject, Promise] = chunk loading, 0 = chunk loaded
/******/ 		var installedChunks = {
/******/ 			"/js/documents/editor": 0,
/******/ 			"css/base/pages/documents-editor": 0
/******/ 		};
/******/ 		
/******/ 		// no chunk on demand loading
/******/ 		
/******/ 		// no prefetching
/******/ 		
/******/ 		// no preloaded
/******/ 		
/******/ 		// no HMR
/******/ 		
/******/ 		// no HMR manifest
/******/ 		
/******/ 		__webpack_require__.O.j = (chunkId) => (installedChunks[chunkId] === 0);
/******/ 		
/******/ 		// install a JSONP callback for chunk loading
/******/ 		var webpackJsonpCallback = (parentChunkLoadingFunction, data) => {
/******/ 			var [chunkIds, moreModules, runtime] = data;
/******/ 			// add "moreModules" to the modules object,
/******/ 			// then flag all "chunkIds" as loaded and fire callback
/******/ 			var moduleId, chunkId, i = 0;
/******/ 			if(chunkIds.some((id) => (installedChunks[id] !== 0))) {
/******/ 				for(moduleId in moreModules) {
/******/ 					if(__webpack_require__.o(moreModules, moduleId)) {
/******/ 						__webpack_require__.m[moduleId] = moreModules[moduleId];
/******/ 					}
/******/ 				}
/******/ 				if(runtime) var result = runtime(__webpack_require__);
/******/ 			}
/******/ 			if(parentChunkLoadingFunction) parentChunkLoadingFunction(data);
/******/ 			for(;i < chunkIds.length; i++) {
/******/ 				chunkId = chunkIds[i];
/******/ 				if(__webpack_require__.o(installedChunks, chunkId) && installedChunks[chunkId]) {
/******/ 					installedChunks[chunkId][0]();
/******/ 				}
/******/ 				installedChunks[chunkId] = 0;
/******/ 			}
/******/ 			return __webpack_require__.O(result);
/******/ 		}
/******/ 		
/******/ 		var chunkLoadingGlobal = self["webpackChunkos_ai"] = self["webpackChunkos_ai"] || [];
/******/ 		chunkLoadingGlobal.forEach(webpackJsonpCallback.bind(null, 0));
/******/ 		chunkLoadingGlobal.push = webpackJsonpCallback.bind(null, chunkLoadingGlobal.push.bind(chunkLoadingGlobal));
/******/ 	})();
/******/ 	
/************************************************************************/
/******/ 	
/******/ 	// startup
/******/ 	// Load entry module and return exports
/******/ 	// This entry module depends on other loaded chunks and execution need to be delayed
/******/ 	__webpack_require__.O(undefined, ["css/base/pages/documents-editor"], () => (__webpack_require__("./resources/js/documents/editor/index.js")))
/******/ 	var __webpack_exports__ = __webpack_require__.O(undefined, ["css/base/pages/documents-editor"], () => (__webpack_require__("./resources/scss/base/pages/documents-editor.scss")))
/******/ 	__webpack_exports__ = __webpack_require__.O(__webpack_exports__);
/******/ 	
/******/ })()
;