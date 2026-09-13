/*=========================================================================================
    File Name: async-region.js
    Description: Customer interactions that change what a screen SHOWS, without reloading
    the screen. One global namespace, window.AsyncRegion, and nothing on by default.

    THE CONTRACT IS EXPLICIT AND OPT-IN. Only markup that asks for it is touched:

        <div data-async-region="business-performance"> … </div>
        <form method="get" data-async-form="business-performance"> … </form>
        <a href="?page=2" data-async-link="contacts-list">Next</a>

    A marked GET form, on submit — or a marked link, on an ordinary click — requests
    exactly the URL the browser would have navigated to, takes the region with the same
    name out of that response, and swaps it in place. Nothing else on the page is
    replaced, no link or form is intercepted unless it is marked, a POST form is never
    handled here, and a modified click (new tab, new window) is left to the browser.

    WHY THE SAME URL, AND NOT A SPECIAL ENDPOINT. The server's answer to that GET is
    already the page for that state. Fetching it means the no-JavaScript fallback, a
    refresh, a bookmark and a shared link all produce exactly what the in-place update
    produced — by construction, not by keeping two renderings in step.

    WHAT A SWAP GUARANTEES
      - one request per region at a time; repeated submits while loading are ignored;
      - a visible, non-blocking loading state (aria-busy, dimmed content, submit disabled);
      - the scroll position is kept, and keyboard focus returns to the same control;
      - the address bar shows the new state (pushState), and Back/Forward restore it;
      - a failure leaves the current content in place and says so, inline and as a toast;
      - anything unexpected — a redirect (an expired session, say) or a response that no
        longer contains the region — falls back to an ordinary navigation, so the user
        always ends up where the server meant them to be.

    After a swap the region element fires `async-region:updated` (bubbling), so page
    scripts can re-attach what they own inside it, such as charts.
==========================================================================================*/
(function (window, document) {
  "use strict";

  var REGION_ATTR = "data-async-region";
  var FORM_ATTR = "data-async-form";
  var LINK_ATTR = "data-async-link";
  var LOADING_CLASS = "async-region--loading";
  var ERROR_ROLE = "async-region-error";
  // Localized by the layout that loads this file; English only as a last resort.
  var ERROR_MESSAGE =
    (document.currentScript && document.currentScript.getAttribute("data-error-message")) || "Something went wrong. Please try again";
  var HISTORY_MARKER = "asyncRegion";

  var supported =
    typeof window.fetch === "function" &&
    typeof window.DOMParser === "function" &&
    typeof window.URLSearchParams === "function" &&
    !!(window.history && window.history.pushState);

  /** name → { controller: AbortController|null } */
  var inFlight = {};
  var listeners = {};

  function regionElement(name) {
    return document.querySelector("[" + REGION_ATTR + '="' + cssEscape(name) + '"]');
  }

  function cssEscape(value) {
    return window.CSS && typeof window.CSS.escape === "function" ? window.CSS.escape(value) : String(value).replace(/["\\]/g, "\\$&");
  }

  function regionNamesOnPage() {
    return Array.prototype.map.call(document.querySelectorAll("[" + REGION_ATTR + "]"), function (el) {
      return el.getAttribute(REGION_ATTR);
    });
  }

  /** The URL a native GET submission of this form would navigate to. */
  function submissionUrl(form, submitter) {
    var url = new URL(form.getAttribute("action") || window.location.href, window.location.href);
    var params = new URLSearchParams();
    var data = new FormData(form);

    data.forEach(function (value, key) {
      if (typeof value === "string") {
        params.append(key, value);
      }
    });

    if (submitter && submitter.name) {
      params.append(submitter.name, submitter.value || "");
    }

    url.search = params.toString();
    url.hash = "";

    return url.toString();
  }

  function setLoading(names, loading) {
    names.forEach(function (name) {
      var region = regionElement(name);

      if (region) {
        region.classList.toggle(LOADING_CLASS, loading);
        region.setAttribute("aria-busy", loading ? "true" : "false");

        // Light and self-contained: the content stays readable and in place,
        // just visibly "working", without depending on a stylesheet build.
        region.style.transition = "opacity 0.15s ease";
        region.style.opacity = loading ? "0.6" : "";
        region.style.cursor = loading ? "progress" : "";
      }

      Array.prototype.forEach.call(document.querySelectorAll("[" + FORM_ATTR + '="' + cssEscape(name) + '"]'), function (form) {
        form.setAttribute("aria-busy", loading ? "true" : "false");

        Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])'), function (button) {
          if (loading) {
            if (!button.disabled) {
              button.disabled = true;
              button.setAttribute("data-async-disabled", "true");
            }
          } else if (button.getAttribute("data-async-disabled") === "true") {
            button.disabled = false;
            button.removeAttribute("data-async-disabled");
          }
        });
      });
    });
  }

  function clearError(region) {
    var existing = region && region.querySelector('[data-role="' + ERROR_ROLE + '"]');

    if (existing) {
      existing.parentNode.removeChild(existing);
    }
  }

  function showError(names) {
    names.forEach(function (name) {
      var region = regionElement(name);

      if (!region) {
        return;
      }

      clearError(region);

      var alert = document.createElement("div");
      alert.className = "alert alert-danger mb-1";
      alert.setAttribute("role", "alert");
      alert.setAttribute("data-role", ERROR_ROLE);
      alert.textContent = ERROR_MESSAGE;
      region.insertBefore(alert, region.firstChild);
    });

    // The customer shell answers toastr calls with the Business OS toast.
    if (window.toastr && typeof window.toastr.error === "function") {
      window.toastr.error(ERROR_MESSAGE);
    }
  }

  var FOCUSABLE = 'a[href], button, input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])';

  /**
   * How to find, in the NEW region, the control that had focus in the old one:
   * by id, name or data-role when it has one, otherwise by its position among
   * the region's focusable controls (an unlabelled Apply button, say). Keyboard
   * users must not be dropped back to the top of the document by an update.
   */
  function focusKey(element, region) {
    if (!element || !region || !region.contains(element) || element === region) {
      return null;
    }

    if (element.id) {
      return { selector: "#" + cssEscape(element.id) };
    }

    if (element.name) {
      return { selector: '[name="' + cssEscape(element.name) + '"]' };
    }

    if (element.getAttribute("data-role")) {
      return { selector: '[data-role="' + cssEscape(element.getAttribute("data-role")) + '"]' };
    }

    var index = Array.prototype.indexOf.call(region.querySelectorAll(FOCUSABLE), element);

    return index === -1 ? null : { index: index };
  }

  function focusTarget(key, region) {
    if (!key) {
      return null;
    }

    return key.selector ? region.querySelector(key.selector) : region.querySelectorAll(FOCUSABLE)[key.index] || null;
  }

  function initTooltips(root) {
    if (!window.bootstrap || typeof window.bootstrap.Tooltip !== "function") {
      return;
    }

    Array.prototype.forEach.call(root.querySelectorAll('[data-bs-toggle="tooltip"]'), function (el) {
      window.bootstrap.Tooltip.getOrCreateInstance(el);
    });
  }

  function disposeTooltips(root) {
    if (!window.bootstrap || typeof window.bootstrap.Tooltip !== "function") {
      return;
    }

    Array.prototype.forEach.call(root.querySelectorAll('[data-bs-toggle="tooltip"]'), function (el) {
      var instance = window.bootstrap.Tooltip.getInstance(el);

      if (instance) {
        instance.dispose();
      }
    });
  }

  function hasFocus(element) {
    return !!element && element !== document.body && element !== document.documentElement;
  }

  /** Taken when a request starts, before the loading state disables anything. */
  function captureFocus(region) {
    var active = document.activeElement;

    return hasFocus(active) ? { element: active, key: focusKey(active, region) } : null;
  }

  /**
   * Focus after the swap. Focus still inside the region moves to the same control
   * in the new region. Focus the loading state took away (a disabled Apply button
   * drops focus to <body>) goes back to that control — in the new region when it
   * was inside the old one, or the control itself when it lives outside. Focus the
   * user has since moved elsewhere on the page is left exactly where they put it.
   */
  function restoreFocus(active, current, replacement, captured) {
    var target = null;

    if (hasFocus(active)) {
      target = focusTarget(focusKey(active, current), replacement);
    } else if (captured && captured.key) {
      target = focusTarget(captured.key, replacement);
    } else if (captured && document.documentElement.contains(captured.element)) {
      target = captured.element;
    }

    if (target && typeof target.focus === "function") {
      target.focus({ preventScroll: true });
    }
  }

  function restoreScroll(x, y) {
    if (window.scrollX === x && window.scrollY === y) {
      return;
    }

    // Instant, not the page's smooth scrolling: the reader should not see a jump
    // being animated back.
    try {
      window.scrollTo({ left: x, top: y, behavior: "instant" });
    } catch (e) {
      window.scrollTo(x, y);
    }
  }

  function swap(name, incoming, capturedFocus) {
    var current = regionElement(name);

    if (!current || !incoming) {
      return false;
    }

    var scrollX = window.scrollX;
    var scrollY = window.scrollY;
    var active = document.activeElement;
    var replacement = document.importNode(incoming, true);

    disposeTooltips(current);
    current.parentNode.replaceChild(replacement, current);

    // A taller or shorter region must not move the reader's place on the page.
    restoreScroll(scrollX, scrollY);
    restoreFocus(active, current, replacement, capturedFocus);
    initTooltips(replacement);

    var event;

    try {
      event = new CustomEvent("async-region:updated", { bubbles: true, detail: { name: name } });
    } catch (e) {
      event = document.createEvent("CustomEvent");
      event.initCustomEvent("async-region:updated", true, false, { name: name });
    }

    replacement.dispatchEvent(event);
    (listeners[name] || []).forEach(function (listener) {
      listener(replacement);
    });

    return true;
  }

  /**
   * Back/Forward only: a marked form that lives OUTSIDE its region (a search box
   * above a list) was not swapped, so it would still show the newer query. Copy
   * its field values from the server's page for that URL. Never done after a
   * submit, so what someone typed while results loaded is left alone.
   */
  function syncForms(name, doc) {
    var selector = "form[" + FORM_ATTR + '="' + cssEscape(name) + '"]';
    var region = regionElement(name);
    var incomingForms = doc.querySelectorAll(selector);

    Array.prototype.forEach.call(document.querySelectorAll(selector), function (form, formIndex) {
      var source = incomingForms[formIndex];

      if (!source || (region && region.contains(form))) {
        return;
      }

      Array.prototype.forEach.call(form.elements, function (field) {
        if (!field.name || /^(submit|button|reset|file|hidden)$/.test(field.type)) {
          return;
        }

        var twins = source.querySelectorAll('[name="' + cssEscape(field.name) + '"]');
        var position = Array.prototype.indexOf.call(form.querySelectorAll('[name="' + cssEscape(field.name) + '"]'), field);
        var twin = twins[position];

        if (!twin) {
          return;
        }

        if (field.type === "checkbox" || field.type === "radio") {
          field.checked = twin.hasAttribute("checked");
        } else if (field.tagName === "SELECT") {
          var selected = twin.querySelector("option[selected]");
          field.value = selected ? selected.value : twin.options.length ? twin.options[0].value : "";
        } else if (field.tagName === "TEXTAREA") {
          field.value = twin.textContent;
        } else {
          field.value = twin.getAttribute("value") || "";
        }
      });
    });
  }

  /**
   * Load `url` and swap each named region from its response.
   *
   * @param {string[]} names
   * @param {string} url
   * @param {{history: "push"|"replace"|"none"}} options
   */
  function load(names, url, options) {
    var historyMode = (options && options.history) || "push";

    names.forEach(function (name) {
      if (inFlight[name] && inFlight[name].controller) {
        inFlight[name].controller.abort();
      }
    });

    var controller = typeof window.AbortController === "function" ? new window.AbortController() : null;
    var focus = {};

    names.forEach(function (name) {
      inFlight[name] = { controller: controller };
      clearError(regionElement(name));
      focus[name] = captureFocus(regionElement(name));
    });

    setLoading(names, true);

    function finish() {
      names.forEach(function (name) {
        if (inFlight[name] && inFlight[name].controller === controller) {
          delete inFlight[name];
        }
      });

      setLoading(names, false);
    }

    return window
      .fetch(url, {
        credentials: "same-origin",
        headers: { Accept: "text/html", "X-Async-Region": names.join(",") },
        signal: controller ? controller.signal : undefined,
      })
      .then(function (response) {
        if (response.redirected) {
          // The server sent this request somewhere else — typically to sign in
          // again. Go there properly instead of pasting a login form into a card.
          window.location.assign(response.url);
          return null;
        }

        if (!response.ok) {
          throw new Error("HTTP " + response.status);
        }

        return response.text();
      })
      .then(function (html) {
        if (html === null) {
          return;
        }

        var doc = new window.DOMParser().parseFromString(html, "text/html");
        var incoming = names.map(function (name) {
          return doc.querySelector("[" + REGION_ATTR + '="' + cssEscape(name) + '"]');
        });

        if (incoming.some(function (el) { return el === null; })) {
          // The server's page for this URL no longer has this region (a different
          // screen state). Show that page, exactly as a normal navigation would.
          window.location.assign(url);
          return;
        }

        finish();

        names.forEach(function (name, index) {
          swap(name, incoming[index], focus[name]);

          if (historyMode === "none") {
            syncForms(name, doc);
          }
        });

        if (historyMode === "push" && url !== window.location.href) {
          window.history.pushState(historyState(), "", url);
        } else if (historyMode === "replace") {
          window.history.replaceState(historyState(), "", url);
        }
      })
      .catch(function (error) {
        if (error && error.name === "AbortError") {
          return;
        }

        finish();
        showError(names);
      });
  }

  function historyState() {
    var state = {};
    state[HISTORY_MARKER] = true;
    return state;
  }

  function onSubmit(event) {
    var form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.hasAttribute(FORM_ATTR)) {
      return;
    }

    if ((form.getAttribute("method") || "get").toLowerCase() !== "get") {
      return;
    }

    var name = form.getAttribute(FORM_ATTR);

    if (!regionElement(name)) {
      // Nothing to update in place: let the browser navigate.
      return;
    }

    event.preventDefault();

    if (inFlight[name]) {
      // Apply was pressed again while the first one is still loading.
      return;
    }

    load([name], submissionUrl(form, event.submitter), { history: "push" });
  }

  function onClick(event) {
    var link = event.target instanceof Element ? event.target.closest("a[" + LINK_ATTR + "]") : null;

    if (!link || event.defaultPrevented) {
      return;
    }

    // A new tab, a new window, a download, a middle click: the browser's job.
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    if ((link.getAttribute("target") && link.getAttribute("target") !== "_self") || link.hasAttribute("download")) {
      return;
    }

    var url = new URL(link.href, window.location.href);

    if (url.origin !== window.location.origin) {
      return;
    }

    var name = link.getAttribute(LINK_ATTR);

    if (!regionElement(name)) {
      return;
    }

    event.preventDefault();

    if (inFlight[name]) {
      return;
    }

    url.hash = "";
    load([name], url.toString(), { history: "push" });
  }

  function onPopState(event) {
    if (!event.state || !event.state[HISTORY_MARKER]) {
      return;
    }

    var names = regionNamesOnPage();

    if (names.length > 0) {
      load(names, window.location.href, { history: "none" });
    }
  }

  if (supported) {
    document.addEventListener("submit", onSubmit);
    document.addEventListener("click", onClick);
    window.addEventListener("popstate", onPopState);

    // Mark the entry the page was loaded on, so Back to it restores its state too.
    if (regionNamesOnPage().length > 0 && window.history.state === null) {
      window.history.replaceState(historyState(), "", window.location.href);
    }
  }

  window.AsyncRegion = {
    supported: supported,

    /** Update named regions from a URL, e.g. after a control outside a form. */
    load: function (names, url, options) {
      return supported ? load([].concat(names), url, options) : (window.location.assign(url), Promise.resolve());
    },

    /** Run `listener(regionElement)` every time the named region is swapped. */
    onUpdate: function (name, listener) {
      (listeners[name] = listeners[name] || []).push(listener);
    },
  };
})(window, document);
