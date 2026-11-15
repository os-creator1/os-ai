/*
 * MotionGrove — page side of the calling shell (see App\Http\Middleware\SoftphoneFrame).
 *
 * Runs in a page that is shown inside the shell's frame. The softphone and the call live in the shell (the parent
 * window), so this page: reports its address/title to the shell, hands "Call" clicks to the shell's softphone, and
 * exposes the same window.MGSoftphone API the page used to get from its own copy. No call state is kept here.
 */
(function () {
    'use strict';
    var shell;
    try { shell = window.parent !== window ? window.parent : null; } catch (e) { shell = null; }
    if (!shell || !shell.MGSoftphone) { return; }

    window.MGSoftphone = {
        callContact: function (contact) { return shell.MGSoftphone.callContact(contact); },
    };
    window.MGSoftphoneInShell = true;

    function report() {
        try { shell.postMessage({ type: 'mg:nav', href: window.location.href, title: document.title }, window.location.origin); } catch (e) { /* shell gone */ }
    }
    report();
    window.addEventListener('pageshow', report);
    window.addEventListener('hashchange', report);

    // The contact profile's own Call button carries the contact it is for.
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-role="contact-call"][data-call-uid]') : null;
        if (!button) { return; }
        event.preventDefault();
        shell.MGSoftphone.callContact({
            uid: button.getAttribute('data-call-uid'),
            label: button.getAttribute('data-call-label') || '',
            phone: button.getAttribute('data-call-phone') || '',
            initials: button.getAttribute('data-call-initials') || '#',
        });
    });
})();
