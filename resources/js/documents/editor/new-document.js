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

import { debounce } from './dom';

function init(root) {
    if (!root) {
        return;
    }
    const modal = root.querySelector('[data-role="new-proposal-modal"]');
    const form = root.querySelector('[data-role="new-proposal-form"]');
    if (!modal || !form) {
        return;
    }

    const contactField = form.querySelector('[data-role="np-contact"]');
    const search = form.querySelector('[data-role="np-search"]');
    const results = form.querySelector('[data-role="np-results"]');
    const stepLabel = form.querySelector('[data-role="np-step"]');
    const sections = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
    const back = form.querySelector('[data-role="np-back"]');
    const next = form.querySelector('[data-role="np-next"]');
    const submit = form.querySelector('[data-role="np-submit"]');
    const chosen = form.querySelector('[data-role="np-chosen"]');
    const title = form.querySelector('[data-role="np-title"]');
    const opener = document.querySelectorAll('[data-role="new-proposal-open"]');
    const searchUrl = root.getAttribute('data-search-url');

    let step = 1;
    let token = 0;
    let previous = null;

    function setStep(value) {
        step = value;
        sections.forEach((section) => { section.hidden = Number(section.getAttribute('data-step')) !== step; });
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

    async function load(query) {
        const mine = ++token;
        results.textContent = 'Loading...';
        let json = { results: [] };
        try {
            const response = await fetch(searchUrl + '?q=' + encodeURIComponent(query), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            json = await response.json();
        } catch (e) {
            results.textContent = 'Contacts could not be loaded.';
            return;
        }
        if (mine !== token) {
            return;
        }
        results.textContent = '';
        if (!json.results || json.results.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'de-muted';
            empty.setAttribute('data-role', 'np-empty');
            empty.textContent = query ? 'No contact matches that search.' : 'You have no contacts yet. Add a contact first.';
            results.appendChild(empty);
            return;
        }
        json.results.forEach((contact) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'de-pick' + (contactField.value === contact.uid ? ' is-active' : '');
            button.setAttribute('data-contact-uid', contact.uid);
            const main = document.createElement('span');
            main.className = 'de-pick__main';
            const name = document.createElement('strong');
            name.textContent = contact.text;
            main.appendChild(name);
            button.appendChild(main);
            button.addEventListener('click', () => {
                contactField.value = contact.uid;
                chosen.textContent = contact.text;
                Array.prototype.forEach.call(results.querySelectorAll('.de-pick'), (node) => node.classList.toggle('is-active', node === button));
                next.disabled = false;
            });
            button.addEventListener('dblclick', () => setStep(2));
            results.appendChild(button);
        });
    }

    search.addEventListener('input', debounce(() => load(search.value.trim()), 250));
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (contactField.value) {
                setStep(2);
            }
        }
    });
    next.addEventListener('click', () => { if (contactField.value) setStep(2); });
    back.addEventListener('click', () => setStep(1));
    form.querySelectorAll('[data-role="np-cancel"]').forEach((node) => node.addEventListener('click', close));
    modal.addEventListener('mousedown', (event) => { if (event.target === modal) close(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) close(); });
    opener.forEach((node) => node.addEventListener('click', open));

    form.addEventListener('submit', (event) => {
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

window.DocumentNewProposal = { init };

export { init };
