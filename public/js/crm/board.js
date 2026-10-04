/*
 * CRM Opportunities board — drag and drop that is already finished when the mouse lets go.
 *
 * A drop MOVES the one existing card node into the target column, corrects the two column
 * headers from the card's own value, and then saves in the background through
 * CrmBoardMoves (board-moves.js owns ordering, staleness and failure). Nothing on this page
 * re-fetches or re-renders the board after a move: the only things a response may touch are
 * two header number texts, and only when nothing else is still being saved.
 *
 * The board region IS replaced when someone changes a filter (window.AsyncRegion); the
 * `async-region:updated` handler then puts any card whose move is still unsettled back where
 * the user dropped it.
 *
 * Markup contract (resources/views/customer/crm/board.blade.php):
 *   [data-role=crm-board][data-currency]                the region
 *   [data-role=crm-column][data-column][data-drop-stage][data-count][data-value-minor]
 *   [data-role=crm-cards]                               the column's card list
 *   [data-role=crm-card][data-uid][data-value-minor][data-move-url]   (draggable when movable)
 */
(function () {
  'use strict';

  var Moves = window.CrmBoardMoves;
  var root = document.getElementById('crm-opportunities');

  if (!Moves || !root) {
    return;
  }

  var FAILURE_MESSAGE = "Couldn't move opportunity. Try again.";
  var urls = {};
  var dragging = null;
  var hover = null;
  var indicator = document.createElement('div');

  indicator.className = 'crm-drop-indicator';
  indicator.setAttribute('aria-hidden', 'true');

  function region() { return root.querySelector('[data-role="crm-board"]'); }
  function allCards() { return Array.prototype.slice.call(root.querySelectorAll('[data-role="crm-card"]')); }
  function cardFor(uid) {
    var found = allCards().filter(function (card) { return card.getAttribute('data-uid') === uid; });
    return found.length ? found[0] : null;
  }
  function columnOf(card) { return card.closest('[data-role="crm-column"]'); }
  function columnFor(stageUid) {
    var found = Array.prototype.filter.call(root.querySelectorAll('[data-role="crm-column"]'), function (col) {
      return col.getAttribute('data-column') === stageUid;
    });
    return found.length ? found[0] : null;
  }
  function stageOf(card) { var col = columnOf(card); return col ? col.getAttribute('data-column') : null; }
  function valueOf(card) { return parseInt(card.getAttribute('data-value-minor') || '0', 10) || 0; }

  // ---- column header + list upkeep -------------------------------------------------------

  function renderColumn(column) {
    var currency = (region() && region().getAttribute('data-currency')) || '';
    var count = parseInt(column.getAttribute('data-count'), 10) || 0;
    var value = parseInt(column.getAttribute('data-value-minor'), 10) || 0;
    var total = column.querySelector('[data-role="crm-column-total"]');
    var list = column.querySelector('[data-role="crm-cards"]');
    var shown = list.querySelectorAll('[data-role="crm-card"]').length;
    var empty = list.querySelector('[data-role="crm-column-empty"]');
    var more = list.querySelector('[data-role="crm-column-more"]');

    if (total) { total.textContent = Moves.totalLabel(count, value, currency); }

    if (shown === 0 && count === 0) {
      if (!empty) {
        empty = document.createElement('p');
        empty.className = 'text-caption text-muted mb-0';
        empty.setAttribute('data-role', 'crm-column-empty');
        empty.textContent = 'No opportunities';
        list.appendChild(empty);
      }
    } else if (empty) {
      empty.remove();
    }

    if (count > shown && shown > 0) {
      if (!more) {
        more = document.createElement('p');
        more.className = 'text-caption mb-0';
        more.setAttribute('data-role', 'crm-column-more');
        list.appendChild(more);
      }
      more.textContent = '+ ' + (count - shown) + ' more — narrow the search to see them';
    } else if (more) {
      more.remove();
    }
  }

  function bump(column, countDelta, valueDelta) {
    column.setAttribute('data-count', Math.max(0, (parseInt(column.getAttribute('data-count'), 10) || 0) + countDelta));
    column.setAttribute('data-value-minor', Math.max(0, (parseInt(column.getAttribute('data-value-minor'), 10) || 0) + valueDelta));
  }

  /** Show `card` in `target` and correct both headers. The node is moved, never copied. */
  function place(card, target) {
    var source = columnOf(card);

    if (!target || source === target) { return; }

    var list = target.querySelector('[data-role="crm-cards"]');
    var empty = list.querySelector('[data-role="crm-column-empty"]');

    if (empty) { empty.remove(); }

    list.insertBefore(card, list.querySelector('[data-role="crm-card"]'));

    var age = card.querySelector('[data-role="crm-card-age"]');
    if (age) { age.textContent = 'In stage less than a minute'; }

    if (source) {
      bump(source, -1, -valueOf(card));
      renderColumn(source);
    }
    bump(target, 1, valueOf(card));
    renderColumn(target);
    enforceUniqueCards();
  }

  /** There is exactly one card per opportunity. If that is ever false, keep the shown one. */
  function enforceUniqueCards() {
    var cards = allCards();
    var duplicates = Moves.duplicateUids(cards.map(function (card) { return card.getAttribute('data-uid'); }));

    duplicates.forEach(function (uid) {
      var copies = cards.filter(function (card) { return card.getAttribute('data-uid') === uid; });
      var keep = copies.filter(function (card) { return stageOf(card) === coordinator.shown(uid); })[0] || copies[0];

      copies.forEach(function (card) { if (card !== keep) { card.remove(); } });
      if (window.console) { console.error('CRM board: duplicate card removed for opportunity ' + uid); }
    });

    return duplicates;
  }

  function announce(message) {
    var status = root.querySelector('[data-role="crm-board-status"]');

    if (status) { status.textContent = message; }
    if (window.toastr) { window.toastr.error(message); }
  }

  // ---- saving ----------------------------------------------------------------------------

  function send(request) {
    var card = cardFor(request.uid);
    var url = (card && card.getAttribute('data-move-url')) || urls[request.uid];

    // The filters travel on the query string so the answer's totals are counted the way
    // the board is currently filtered.
    return fetch(url + window.location.search, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': root.getAttribute('data-csrf'),
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ stage: request.to, from_stage: request.from, client_move: request.gen })
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (body) {
        if (!response.ok) { throw { status: response.status, body: body }; }
        return body;
      });
    }, function () {
      throw { network: true };
    });
  }

  var coordinator = Moves.createMoveCoordinator({
    send: send,
    on: {
      saving: function (uid, on) {
        var card = cardFor(uid);
        if (card) { card.classList.toggle('is-saving', on); }
      },
      confirmed: function (uid, body, info) {
        // Nothing to draw: the card is already where it belongs. Only when this is the
        // newest answer AND nothing else is still being saved are the server's own totals
        // allowed to correct the two header numbers.
        if (!info.latest || coordinator.pending() !== 0) { return; }

        [body.source_totals, body.target_totals].forEach(function (totals) {
          var column = totals && columnFor(totals.stage_uid);

          if (column) {
            column.setAttribute('data-count', totals.count);
            column.setAttribute('data-value-minor', totals.value_minor);
            renderColumn(column);
          }
        });
      },
      revert: function (uid, stageUid, info) {
        var card = cardFor(uid);
        var column = columnFor(stageUid);

        if (card && column) { place(card, column); }
        announce(info.conflict && info.message ? info.message : FAILURE_MESSAGE);
      }
    }
  });

  function registerCards() {
    allCards().forEach(function (card) {
      var uid = card.getAttribute('data-uid');

      if (card.getAttribute('data-move-url')) { urls[uid] = card.getAttribute('data-move-url'); }
      coordinator.register(uid, stageOf(card));
    });
  }

  /** After a filter swap replaced the board: unsettled cards go back where they were dropped. */
  function reconcile() {
    enforceUniqueCards();

    allCards().forEach(function (card) {
      var uid = card.getAttribute('data-uid');
      var desired = coordinator.sync(uid, stageOf(card));

      if (desired !== null && desired !== stageOf(card)) {
        var column = columnFor(desired);
        if (column) { place(card, column); }
      }
      if (coordinator.isUnsettled(uid)) { card.classList.add('is-saving'); }
    });
  }

  // ---- dragging --------------------------------------------------------------------------

  function clearHover() {
    if (hover) { hover.classList.remove('is-drop-target'); hover = null; }
    if (indicator.parentNode) { indicator.remove(); }
  }

  /**
   * What a drop does, in this order: the coordinator records the intent (and starts the
   * background save), and the card is shown in its new column straight away.
   */
  function dropOn(uid, column) {
    var card = cardFor(uid);

    if (!card || !coordinator.move(uid, column.getAttribute('data-drop-stage'))) { return false; }
    place(card, column);

    return true;
  }

  document.addEventListener('dragstart', function (event) {
    var card = event.target.closest && event.target.closest('[data-role="crm-card"][data-move-url]');
    if (!card || !root.contains(card)) { return; }

    dragging = card.getAttribute('data-uid');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', dragging);
    // The browser paints its drag image from the card as it is right now; dim the
    // original only after that snapshot has been taken.
    window.requestAnimationFrame(function () { card.classList.add('is-dragging'); });
  });

  document.addEventListener('dragend', function () {
    var card = dragging && cardFor(dragging);

    if (card) { card.classList.remove('is-dragging'); }
    clearHover();
    dragging = null;
  });

  document.addEventListener('dragover', function (event) {
    var column = dragging && event.target.closest && event.target.closest('[data-drop-stage]');
    var card = dragging && cardFor(dragging);

    if (!column || !card || !root.contains(column)) { return; }

    event.preventDefault();

    if (columnOf(card) === column) {
      event.dataTransfer.dropEffect = 'none';
      clearHover();
      return;
    }

    event.dataTransfer.dropEffect = 'move';

    if (hover !== column) {
      clearHover();
      hover = column;
      column.classList.add('is-drop-target');
      // A dropped card always lands at the top of the column (the board lists newest-in-stage
      // first), so that is where the marker shows.
      var list = column.querySelector('[data-role="crm-cards"]');
      list.insertBefore(indicator, list.firstChild);
    }
  });

  document.addEventListener('dragleave', function (event) {
    if (hover && !hover.contains(event.relatedTarget)) { clearHover(); }
  });

  document.addEventListener('drop', function (event) {
    var column = dragging && event.target.closest && event.target.closest('[data-drop-stage]');
    var card = dragging && cardFor(dragging);

    if (!column || !card || !root.contains(column)) { return; }
    event.preventDefault();

    var uid = dragging;
    clearHover();
    card.classList.remove('is-dragging');
    dragging = null;
    dropOn(uid, column);
  });

  document.addEventListener('async-region:updated', function (event) {
    if (event.detail && event.detail.name === 'crm-board') { reconcile(); }
  });

  registerCards();

  // A small, read-only handle for manual QA and for the browser smoke tests.
  window.CrmBoard = {
    pending: function () { return coordinator.pending(); },
    shown: function (uid) { return coordinator.shown(uid); },
    duplicates: function () { return Moves.duplicateUids(allCards().map(function (card) { return card.getAttribute('data-uid'); })); },
    // Drives exactly what a drop does (used where a pointer drag cannot be scripted).
    drop: function (uid, stageUid) {
      var column = columnFor(stageUid);

      return !!column && dropOn(uid, column);
    }
  };
})();
