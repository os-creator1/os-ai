/*
 * CRM board move coordination — no DOM, no jQuery, nothing global but the export.
 *
 * WHY A SEPARATE MODULE. The board shows the user's drops at once and saves them in the
 * background, so what is on screen and what the server has diverge for a moment. Who is
 * right when requests overlap, fail or answer late is a state-machine question, and it
 * is kept here so it can be run under Node (tests/Js/crm-board-moves.test.js) without
 * a browser. public/js/crm/board.js is the DOM half and only calls this.
 *
 * THE MODEL, PER OPPORTUNITY (cards never share state):
 *   confirmed  the stage the SERVER last told us the card is in
 *   desired    the stage the card is SHOWN in (what the user last dropped it on)
 *   gen        a counter that grows by one on every drop of this card
 *   inflight   the single request on the wire for this card, tagged with its gen
 *
 * RULES
 *   1. A drop changes `desired` and bumps `gen` immediately. It never waits.
 *   2. One request per card at a time, sent from `confirmed` to the CURRENT `desired`.
 *      Drops made while one is in flight are coalesced: A->B then B->C becomes one
 *      B->C request once A->B answers. Because the server only ever sees one request
 *      per card, and each names the stage it expects the card to be in, requests
 *      cannot overtake each other and a late response can never be a surprise.
 *   3. A response for a request whose gen is no longer the card's gen is STALE: it
 *      updates `confirmed` (that is real server truth) and is otherwise ignored, so it
 *      can neither move the card nor redraw anything.
 *   4. A failed request rolls the card back to `confirmed` only if no newer drop
 *      exists. If one does, the failure is dropped silently and the newer drop is
 *      sent; an old failure never undoes a newer move.
 *   5. A 409 conflict carries the stage the server has; that becomes `confirmed`.
 *   6. Transient failures (network, 502/503/504) are retried, same request. The server
 *      answers a repeat of a request it already applied as a success, so a retry whose
 *      first attempt did commit is harmless.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.CrmBoardMoves = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var RETRY_DELAYS = [400, 1200];

  /** "1,234" / "1,234.50" — the same spelling as CrmMoney::format() on the server. */
  function formatMoney(minor, currency) {
    var whole = Math.floor(minor / 100);
    var cents = minor % 100;
    var text = String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    if (cents !== 0) {
      text += '.' + (cents < 10 ? '0' : '') + cents;
    }

    return currency ? currency + ' ' + text : text;
  }

  /** The column header total, same spelling as CrmBoard::totalLabel(). */
  function totalLabel(count, valueMinor, currency) {
    return count + (valueMinor > 0 ? ' · ' + formatMoney(valueMinor, currency) : '');
  }

  function isTransient(failure) {
    return !!failure && (failure.network === true || failure.status === 502 || failure.status === 503 || failure.status === 504);
  }

  /**
   * @param {{
   *   send: function({uid: string, from: string, to: string, gen: number}): Promise<object>,
   *   on?: {
   *     saving?: function(string, boolean),
   *     confirmed?: function(string, object, {latest: boolean}),
   *     revert?: function(string, string, {conflict: boolean, message: ?string}),
   *     idle?: function()
   *   },
   *   wait?: function(number): Promise<void>
   * }} options  `send` resolves with the parsed JSON body of a 2xx, and rejects with
   *             {status, body} for an HTTP error or {network: true} when nothing came back.
   */
  function createMoveCoordinator(options) {
    var on = options.on || {};
    var wait = options.wait || function (ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); };
    var cards = {};

    function emit(name, a, b, c) {
      if (typeof on[name] === 'function') {
        on[name](a, b, c);
      }
    }

    function unsettled(card) {
      return card.inflight !== null || card.desired !== card.confirmed;
    }

    function pending() {
      return Object.keys(cards).filter(function (uid) { return unsettled(cards[uid]); }).length;
    }

    function settleCheck() {
      if (pending() === 0) {
        emit('idle');
      }
    }

    function pump(uid) {
      var card = cards[uid];

      if (card.inflight !== null) {
        return;
      }

      if (card.desired === card.confirmed) {
        settleCheck();
        return;
      }

      var request = { uid: uid, from: card.confirmed, to: card.desired, gen: card.gen };
      card.inflight = request;
      emit('saving', uid, true);
      attempt(request, 0);
    }

    function attempt(request, retried) {
      var sent;

      try {
        sent = Promise.resolve(options.send(request));
      } catch (error) {
        sent = Promise.reject({ network: true });
      }

      sent.then(
        function (body) { succeeded(request, body); },
        function (failure) {
          if (isTransient(failure) && retried < RETRY_DELAYS.length) {
            wait(RETRY_DELAYS[retried]).then(function () { attempt(request, retried + 1); });
            return;
          }

          failed(request, failure || {});
        }
      );
    }

    function succeeded(request, body) {
      var card = cards[request.uid];
      var latest = request.gen === card.gen;

      card.inflight = null;
      card.confirmed = (body && body.stage_uid) || request.to;
      emit('saving', request.uid, false);
      emit('confirmed', request.uid, body, { latest: latest });
      pump(request.uid);
    }

    function failed(request, failure) {
      var card = cards[request.uid];
      var body = failure.body || {};
      var conflict = failure.status === 409 && !!body.stage_uid;
      var latest = request.gen === card.gen;

      card.inflight = null;
      emit('saving', request.uid, false);

      if (conflict) {
        card.confirmed = body.stage_uid;
      }

      if (!latest) {
        // A newer drop exists: it decides what is shown. Never undo it for an old failure.
        pump(request.uid);
        return;
      }

      var message = body.message || null;
      var showing = card.desired;

      card.desired = card.confirmed;

      if (showing !== card.desired) {
        emit('revert', request.uid, card.confirmed, { conflict: conflict, message: message });
      }

      settleCheck();
    }

    return {
      /** Start tracking a card at the stage it is rendered in. */
      register: function (uid, stageUid) {
        cards[uid] = cards[uid] || { confirmed: stageUid, desired: stageUid, gen: 0, inflight: null };
      },

      /**
       * After the board's HTML was replaced (a filter change): a card with a move still
       * unsettled keeps its desired stage, which the caller must put back; any other card
       * simply adopts the stage the new HTML shows it in.
       *
       * @return {?string} the stage the card should be shown in when it is unsettled
       */
      sync: function (uid, renderedStageUid) {
        var card = cards[uid];

        if (!card) {
          this.register(uid, renderedStageUid);
          return null;
        }

        if (unsettled(card)) {
          return card.desired;
        }

        card.confirmed = card.desired = renderedStageUid;

        return null;
      },

      /** The user dropped the card on `to`. Returns null when it is already shown there. */
      move: function (uid, to) {
        var card = cards[uid];

        if (!card || card.desired === to) {
          return null;
        }

        var from = card.desired;

        card.desired = to;
        card.gen += 1;
        pump(uid);

        return { uid: uid, from: from, to: to, gen: card.gen };
      },

      shown: function (uid) { return cards[uid] ? cards[uid].desired : null; },
      confirmedStage: function (uid) { return cards[uid] ? cards[uid].confirmed : null; },
      isUnsettled: function (uid) { return !!cards[uid] && unsettled(cards[uid]); },
      pending: pending,
      uids: function () { return Object.keys(cards); },
    };
  }

  /** No two cards may share an opportunity uid. @return {string[]} the duplicated uids */
  function duplicateUids(uids) {
    var seen = {};
    var duplicates = [];

    uids.forEach(function (uid) {
      if (seen[uid] === true && duplicates.indexOf(uid) === -1) {
        duplicates.push(uid);
      }

      seen[uid] = true;
    });

    return duplicates;
  }

  return {
    createMoveCoordinator: createMoveCoordinator,
    formatMoney: formatMoney,
    totalLabel: totalLabel,
    duplicateUids: duplicateUids,
  };
});
