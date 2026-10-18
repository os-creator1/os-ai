// Contract 17B — the DOM <-> runs seam for inline text.
//
// `domToRuns` turns what a contenteditable holds into EXACTLY the run format
// BlockSchema accepts ({t, b?, i?, u?, href?} or {merge, ...}); `runsToDom`
// does the reverse. Both are pure over a tiny duck-typed node interface
// (nodeType, nodeName, nodeValue, childNodes, getAttribute) so the Node test
// can drive `domToRuns` without a browser.

export const MAX_RUN_TEXT = 2000;

const TEXT = 3;
const ELEMENT = 1;

/** Mirror of BlockSchema::isSafeHref — http(s), mailto, tel only. */
export function isSafeHref(href) {
    if (typeof href !== 'string') {
        return false;
    }
    const value = href.trim();

    if (value === '' || value.length > 2000 || /[\u0000- \u007f\\]/.test(value)) {
        return false;
    }

    let match = /^(https?):\/\/([^/?#]+)/i.exec(value);
    if (match) {
        try {
            const url = new URL(value);
            return (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname !== '';
        } catch (e) {
            return false;
        }
    }

    match = /^mailto:([^?]+)(\?.*)?$/i.exec(value);
    if (match) {
        let address = match[1];
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
export function normalizeHref(input) {
    const raw = String(input || '').trim();
    if (raw === '') {
        return null;
    }

    let candidate = raw;
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
    const run = { t: text };
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
export function domToRuns(root) {
    const runs = [];

    const push = (text, fmt) => {
        if (text === '') {
            return;
        }
        const last = runs[runs.length - 1];
        if (last && last.t !== undefined && last.merge === undefined && sameFormat(last, fmt)) {
            last.t += text;
        } else {
            runs.push(makeRun(text, fmt));
        }
    };

    const endsWithBreak = () => {
        const last = runs[runs.length - 1];
        return !last || (last.t !== undefined && last.t.endsWith('\n'));
    };

    const walk = (node, fmt, isBlockChild) => {
        const children = node.childNodes ? Array.from(node.childNodes) : [];

        children.forEach((child) => {
            if (child.nodeType === TEXT) {
                // Zero-width spaces are caret anchors; non-breaking spaces are plain spaces.
                const text = String(child.nodeValue || '').replace(/​/g, '').replace(/ /g, ' ').replace(/\r/g, '');
                push(text, fmt);
                return;
            }
            if (child.nodeType !== ELEMENT) {
                return;
            }

            const name = String(child.nodeName || '').toUpperCase();
            const token = child.getAttribute ? child.getAttribute('data-token') : null;

            if (token) {
                const run = { merge: token };
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

            const next = { b: fmt.b, i: fmt.i, u: fmt.u, href: fmt.href };
            if (name === 'B' || name === 'STRONG') next.b = true;
            if (name === 'I' || name === 'EM') next.i = true;
            if (name === 'U') next.u = true;
            if (name === 'A') {
                const href = child.getAttribute ? child.getAttribute('href') : null;
                if (href && isSafeHref(href)) {
                    next.href = href.trim();
                }
            }

            // Browsers wrap an Enter-created line in <div>/<p>: that is a line break.
            if (name === 'DIV' || name === 'P') {
                if (!endsWithBreak()) {
                    push('\n', fmt);
                }
                walk(child, next, true);
                return;
            }

            walk(child, next, false);
        });
    };

    walk(root, { b: false, i: false, u: false, href: '' }, false);

    // A contenteditable keeps one trailing <br>; it is not content.
    const last = runs[runs.length - 1];
    if (last && last.t !== undefined && last.merge === undefined && last.t.endsWith('\n')) {
        last.t = last.t.slice(0, -1);
        if (last.t === '') {
            runs.pop();
        }
    }

    // Split anything longer than a run may be.
    const out = [];
    runs.forEach((run) => {
        if (run.t !== undefined && run.t.length > MAX_RUN_TEXT) {
            for (let i = 0; i < run.t.length; i += MAX_RUN_TEXT) {
                out.push({ ...run, t: run.t.slice(i, i + MAX_RUN_TEXT) });
            }
        } else {
            out.push(run);
        }
    });

    return out;
}

/** Plain text of some runs (merge runs read as their label). */
export function runsToText(runs, labelFor) {
    return (runs || []).map((run) => (run.merge ? (labelFor ? labelFor(run.merge) : run.merge) : run.t || '')).join('');
}

/**
 * Build the editable DOM for some runs into `container`.
 * `chipText(token)` -> the text a merge chip shows; `chipTitle(token)` its tooltip.
 */
export function runsToDom(doc, container, runs, chipText, chipTitle) {
    while (container.firstChild) {
        container.removeChild(container.firstChild);
    }

    (runs || []).forEach((run) => {
        let node;

        if (run.merge) {
            node = doc.createElement('span');
            node.className = 'doc-merge';
            node.setAttribute('data-token', run.merge);
            node.setAttribute('contenteditable', 'false');
            node.title = chipTitle ? chipTitle(run.merge) : run.merge;
            node.textContent = chipText ? chipText(run.merge) : run.merge;
        } else {
            node = doc.createDocumentFragment();
            String(run.t || '').split('\n').forEach((piece, index) => {
                if (index > 0) {
                    node.appendChild(doc.createElement('br'));
                }
                if (piece !== '') {
                    node.appendChild(doc.createTextNode(piece));
                }
            });
        }

        [['b', 'b'], ['i', 'i'], ['u', 'u']].forEach(([flag, tag]) => {
            if (run[flag] === true) {
                const wrap = doc.createElement(tag);
                wrap.appendChild(node);
                node = wrap;
            }
        });

        if (run.href && isSafeHref(run.href)) {
            const anchor = doc.createElement('a');
            anchor.setAttribute('href', run.href);
            anchor.setAttribute('rel', 'noopener noreferrer nofollow');
            anchor.appendChild(node);
            node = anchor;
        }

        container.appendChild(node);
    });
}
