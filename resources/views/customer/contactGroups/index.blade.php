@extends('layouts/contentLayoutMaster')

{{--
    Contacts → Groups: the secondary tab next to All contacts, laid out per the
    approved "ContactGroups" design. Server-rendered from ContactsController@index;
    every control posts to an existing Contacts route (active, copy, update,
    destroy, export, contact.create) through CrmRouting.
--}}

@section('title', __('locale.contacts.contact_groups'))

@section('page-style')
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/crm/mgx-contacts.css') }}?v=1">
    <link rel="stylesheet" href="{{ asset('css/crm/mgx-groups.css') }}?v=1">
@endsection

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $businessUid = request()->route('businessUid');
        $businessScoped = $workspaceUid !== null && $businessUid !== null;
        $listUrl = \App\Library\CrmRouting::route('contacts.index');
        $canCreateGroup = auth()->user()->can('create_contact_group');
        $canUpdateGroup = auth()->user()->can('update_contact_group');
        $canDeleteGroup = auth()->user()->can('delete_contact_group');
        $canAddContacts = auth()->user()->can('create_contact');
        $canExportContacts = auth()->user()->can('view_contact');
        $newGroupUrl = \App\Library\CrmRouting::route('contacts.create');
        $groupsTotal = $groupsTotal ?? $groups->count();
        $search = $search ?? '';
    @endphp

    <section id="contacts-groups" class="mgx-contacts mgx-groups"
             data-active-url="{{ \App\Library\CrmRouting::route('contacts.active', '__UID__') }}"
             data-copy-url="{{ \App\Library\CrmRouting::route('contacts.copy', '__UID__') }}"
             data-update-url="{{ \App\Library\CrmRouting::route('contacts.update', '__UID__') }}"
             data-destroy-url="{{ \App\Library\CrmRouting::route('contacts.destroy', '__UID__') }}"
             data-export-url="{{ \App\Library\CrmRouting::route('contact.export', '__UID__') }}">

        <div class="mgx-head">
            <div>
                <h1 class="mgx-title">Contacts</h1>
                <p class="mgx-sub">Groups keep your contacts organised for messages and automations.</p>
            </div>
            @can('view_contact_group')
                <a class="mgx-btn is-secondary" data-role="groups-export" href="{{ \App\Library\CrmRouting::route('contacts.export') }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>{{ __('locale.buttons.export') }}</a>
            @endcan
            @can('create_contact_group')
                <a class="mgx-btn is-primary" data-role="groups-new" href="{{ $newGroupUrl }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>New group</a>
            @endcan
        </div>

        @if ($businessScoped)
            <nav class="mgx-tabs" aria-label="Contacts" data-role="contacts-tabs">
                <a class="mgx-tab" href="{{ route('customer.workspaces.businesses.people.index', [$workspaceUid, $businessUid]) }}">All contacts</a>
                <a class="mgx-tab is-on" aria-current="page" href="{{ $listUrl }}">Groups <span class="mgx-count" data-role="groups-count">{{ number_format($groupsTotal) }}</span></a>
            </nav>
        @endif

        <div class="mgx-card mgx-gcard">
            <form method="GET" action="{{ $listUrl }}" role="search" class="mgx-bar" data-role="groups-search">
                <label class="mgx-search" for="groups-search">
                    <svg class="mgx-ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
                    <span class="visually-hidden">Search groups</span>
                    <input type="search" id="groups-search" name="q" value="{{ $search }}" placeholder="Search groups" maxlength="100" autocomplete="off">
                </label>
                <button type="submit" class="visually-hidden">Search</button>
            </form>

            <div class="mgx-grow is-head" role="row"><span>Group</span><span>Contacts</span><span>Last import</span><span>Created</span><span>Active</span><span></span></div>

            @forelse ($groups as $group)
                @php
                    $contactCount = (int) $group->totalSubscribers($group->cache);
                    $lastImport = $lastImports[$group->id] ?? null;
                    $showUrl = \App\Library\CrmRouting::route('contacts.show', $group->uid);
                    $hasMenu = $canAddContacts || $canUpdateGroup || $canCreateGroup || $canExportContacts || $canDeleteGroup;
                @endphp
                <div class="mgx-grow" data-role="group-row" data-uid="{{ $group->uid }}" data-name="{{ $group->name }}">
                    <div class="mgx-who">
                        <span class="mgx-gico" aria-hidden="true"><svg class="mgx-ic" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5M16 5a3.5 3.5 0 0 1 0 6.5M18 14.8c1.8.8 3 2.6 3 5.2"/></svg></span>
                        <div>
                            <a class="mgx-name" href="{{ $showUrl }}">{{ $group->name }}</a>
                            @unless ($group->status)<small>Inactive</small>@endunless
                        </div>
                    </div>
                    <a class="mgx-gcount" data-label="Contacts" href="{{ $showUrl }}">{{ number_format($contactCount) }} {{ $contactCount === 1 ? 'contact' : 'contacts' }}</a>
                    <span class="mgx-gmu" data-label="Last import">{{ $lastImport !== null ? \Illuminate\Support\Carbon::parse($lastImport)->diffForHumans() : '—' }}</span>
                    <span class="mgx-gmu" data-label="Created">{{ $group->created_at?->diffForHumans() }}</span>
                    <span data-label="Active">
                        <button type="button" class="mgx-sw @if ($group->status) is-on @endif" role="switch" aria-checked="{{ $group->status ? 'true' : 'false' }}" aria-label="Active: {{ $group->name }}" data-action="toggle" @unless ($canUpdateGroup) disabled @endunless></button>
                    </span>
                    <div class="mgx-gmenu">
                        @if ($hasMenu)
                            <button type="button" class="mgx-dots" aria-label="Group options: {{ $group->name }}" aria-haspopup="menu" aria-expanded="false" data-action="menu"><svg class="mgx-ic" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></svg></button>
                            <div class="mgx-menu" role="menu" hidden>
                                @if ($canAddContacts)
                                    <a class="mgx-mi" role="menuitem" data-action="add" href="{{ \App\Library\CrmRouting::route('contact.create', $group->uid) }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Add contacts</a>
                                @endif
                                @if ($canUpdateGroup)
                                    <button type="button" class="mgx-mi" role="menuitem" data-action="rename"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4"/></svg>Rename</button>
                                @endif
                                @if ($canCreateGroup)
                                    <button type="button" class="mgx-mi" role="menuitem" data-action="duplicate"><svg class="mgx-ic" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>Duplicate</button>
                                @endif
                                @if ($canExportContacts)
                                    <button type="button" class="mgx-mi" role="menuitem" data-action="export" data-fields="{{ json_encode($fieldTags[$group->id] ?? []) }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>Export contacts</button>
                                @endif
                                @if ($canDeleteGroup)
                                    <div class="mgx-msep" role="separator"></div>
                                    <button type="button" class="mgx-mi is-danger" role="menuitem" data-action="delete"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>Delete group</button>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="mgx-empty" data-role="groups-empty">
                    @if ($search !== '')
                        <b>No groups match “{{ $search }}”.</b>Try another name, or <a href="{{ $listUrl }}">show all groups</a>.
                    @else
                        <b>No groups yet.</b>Create a group to keep contacts organised for messages and automations.
                    @endif
                </div>
            @endforelse

            @can('create_contact_group')
                <a class="mgx-gnew" data-role="groups-create-another" href="{{ $newGroupUrl }}">
                    <span class="mgx-gplus"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span>
                    Create another group<span class="mgx-ghint">— e.g. Past clients, Vendors, Wedding leads</span>
                </a>
            @endcan
        </div>

        {{-- One dialog serves rename, duplicate and delete; the button it submits is the real action. --}}
        <div class="mgx-modal" id="groups-dialog" hidden>
            <form class="mgx-dialog mgx-gdialog" method="POST" action="" role="dialog" aria-modal="true" aria-labelledby="groups-dialog-title" data-role="groups-dialog-form">
                <div class="mgx-dh">
                    <div><h2 id="groups-dialog-title"></h2><span id="groups-dialog-sub"></span></div>
                    <button type="button" class="mgx-x" aria-label="Close" data-dialog-close><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                </div>
                <div class="mgx-db">
                    <div class="mgx-fl" id="groups-dialog-field">
                        <label for="groups-dialog-name">Group name</label>
                        <input class="mgx-in" id="groups-dialog-name" name="name" maxlength="191" autocomplete="off">
                    </div>
                    <p class="mgx-err" id="groups-dialog-error" role="alert"></p>
                </div>
                <div class="mgx-df">
                    <span class="mgx-df-spacer"></span>
                    <button type="button" class="mgx-btn is-secondary" data-dialog-close>Cancel</button>
                    <button type="submit" class="mgx-btn is-primary" id="groups-dialog-submit">Save</button>
                </div>
            </form>
        </div>
    </section>
@endsection

@section('page-script')
    <script>
        (function () {
            'use strict';

            var root = document.getElementById('contacts-groups');
            if (!root) { return; }

            var csrf = @json(csrf_token());
            var urls = {
                active: root.dataset.activeUrl,
                copy: root.dataset.copyUrl,
                update: root.dataset.updateUrl,
                destroy: root.dataset.destroyUrl,
                exportGroup: root.dataset.exportUrl
            };
            var text = {
                renameTitle: 'Rename group',
                renameSub: 'Choose a new name for this group.',
                duplicateTitle: 'Duplicate group',
                duplicateSub: 'What would you like to name the copy?',
                deleteTitle: 'Delete this group?',
                deleteSub: 'This cannot be undone.',
                save: 'Save',
                duplicate: 'Duplicate',
                remove: 'Delete group',
                nameRequired: 'Enter a group name.',
                failed: @json(__('locale.exceptions.something_went_wrong'))
            };

            function url(template, uid) { return template.replace('__UID__', encodeURIComponent(uid)); }

            function notify(kind, message) {
                if (window.toastr && typeof window.toastr[kind] === 'function') {
                    window.toastr[kind](message, '', { closeButton: true, positionClass: 'toast-top-right', progressBar: true, newestOnTop: true });
                }
            }

            function remember(kind, message) {
                try { sessionStorage.setItem('mgx-groups-notice', JSON.stringify({ kind: kind, message: message })); } catch (e) { /* storage unavailable */ }
            }

            try {
                var pending = sessionStorage.getItem('mgx-groups-notice');
                if (pending) {
                    sessionStorage.removeItem('mgx-groups-notice');
                    var note = JSON.parse(pending);
                    setTimeout(function () { notify(note.kind, note.message); }, 400);
                }
            } catch (e) { /* storage unavailable */ }

            function post(target, fields) {
                var body = new FormData();
                body.append('_token', csrf);
                Object.keys(fields || {}).forEach(function (key) { body.append(key, fields[key]); });
                return fetch(target, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (data) {
                        data.httpStatus = response.status;
                        return data;
                    });
                });
            }

            function firstError(data) {
                if (data && data.errors) {
                    var keys = Object.keys(data.errors);
                    if (keys.length) { return data.errors[keys[0]][0]; }
                }
                return (data && data.message) || text.failed;
            }

            /* ---- row menu ---- */
            var openMenu = null;

            function closeMenu(restoreFocus) {
                if (!openMenu) { return; }
                var trigger = openMenu.parentNode.querySelector('[data-action="menu"]');
                openMenu.hidden = true;
                openMenu.classList.remove('is-up');
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.classList.remove('is-open');
                    if (restoreFocus) { trigger.focus(); }
                }
                openMenu = null;
            }

            function showMenu(trigger) {
                var menu = trigger.parentNode.querySelector('.mgx-menu');
                if (!menu) { return; }
                closeMenu(false);
                menu.hidden = false;
                // Open upward when there is no room below, so the menu is never cut off.
                var rect = trigger.getBoundingClientRect();
                var room = window.innerHeight - rect.bottom;
                menu.classList.toggle('is-up', room < menu.offsetHeight + 16 && rect.top > menu.offsetHeight + 16);
                trigger.setAttribute('aria-expanded', 'true');
                trigger.classList.add('is-open');
                openMenu = menu;
                var first = menu.querySelector('.mgx-mi');
                if (first) { first.focus(); }
            }

            document.addEventListener('click', function (event) {
                if (openMenu && !openMenu.parentNode.contains(event.target)) { closeMenu(false); }
            });

            document.addEventListener('keydown', function (event) {
                if (!openMenu) { return; }
                if (event.key === 'Escape') { event.preventDefault(); closeMenu(true); return; }
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    var items = Array.prototype.slice.call(openMenu.querySelectorAll('.mgx-mi'));
                    var at = items.indexOf(document.activeElement);
                    var next = event.key === 'ArrowDown' ? (at + 1) % items.length : (at - 1 + items.length) % items.length;
                    items[next].focus();
                }
            });

            /* ---- dialog (rename / duplicate / delete) ---- */
            var modal = document.getElementById('groups-dialog');
            var form = modal.querySelector('form');
            var nameInput = document.getElementById('groups-dialog-name');
            var nameField = document.getElementById('groups-dialog-field');
            var errorBox = document.getElementById('groups-dialog-error');
            var submitBtn = document.getElementById('groups-dialog-submit');
            var dialogMode = null;
            var dialogRow = null;
            var lastTrigger = null;

            function openDialog(mode, row) {
                dialogMode = mode;
                dialogRow = row;
                var name = row.dataset.name;
                errorBox.classList.remove('is-on');
                errorBox.textContent = '';
                nameInput.classList.remove('is-bad');
                var asking = mode !== 'delete';
                nameField.hidden = !asking;
                nameInput.disabled = !asking;
                submitBtn.classList.toggle('is-danger', mode === 'delete');
                submitBtn.classList.toggle('is-primary', mode !== 'delete');
                document.getElementById('groups-dialog-title').textContent = mode === 'rename' ? text.renameTitle : (mode === 'duplicate' ? text.duplicateTitle : text.deleteTitle);
                document.getElementById('groups-dialog-sub').textContent = mode === 'rename' ? text.renameSub : (mode === 'duplicate' ? text.duplicateSub : '“' + name + '” ' + text.deleteSub);
                submitBtn.textContent = mode === 'rename' ? text.save : (mode === 'duplicate' ? text.duplicate : text.remove);
                nameInput.value = mode === 'duplicate' ? 'Copy of ' + name : name;
                lastTrigger = document.activeElement;
                modal.hidden = false;
                (asking ? nameInput : submitBtn).focus();
                if (asking) { nameInput.select(); }
            }

            function closeDialog() {
                modal.hidden = true;
                if (lastTrigger && typeof lastTrigger.focus === 'function') { lastTrigger.focus(); }
            }

            function showDialogError(message) {
                errorBox.textContent = message;
                errorBox.classList.add('is-on');
                nameInput.classList.add('is-bad');
            }

            modal.addEventListener('click', function (event) {
                if (event.target === modal || event.target.closest('[data-dialog-close]')) { closeDialog(); }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.hidden) { closeDialog(); }
            });

            function hiddenField(name, value) {
                var input = form.querySelector('input[name="' + name + '"]');
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    form.appendChild(input);
                }
                input.value = value;
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!dialogRow) { return; }
                var uid = dialogRow.dataset.uid;
                var name = nameInput.value.trim();
                if (dialogMode !== 'delete' && name === '') { showDialogError(text.nameRequired); return; }

                if (dialogMode === 'rename') {
                    // The existing update route; "return" brings the person back to this list.
                    form.action = url(urls.update, uid);
                    hiddenField('_method', 'PATCH');
                    hiddenField('_token', csrf);
                    hiddenField('return', 'index');
                    form.submit();
                    return;
                }

                submitBtn.disabled = true;
                var request = dialogMode === 'duplicate'
                    ? post(url(urls.copy, uid), { group_name: name })
                    : post(url(urls.destroy, uid), { _method: 'DELETE' });

                request.then(function (data) {
                    submitBtn.disabled = false;
                    if (data.status === 'success') {
                        remember('success', data.message);
                        window.location.reload();
                    } else {
                        showDialogError(firstError(data));
                    }
                }).catch(function () {
                    submitBtn.disabled = false;
                    showDialogError(text.failed);
                });
            });

            /* ---- row actions ---- */
            root.addEventListener('click', function (event) {
                var control = event.target.closest('[data-action]');
                if (!control || !root.contains(control)) { return; }
                var row = control.closest('[data-role="group-row"]');
                if (!row) { return; }
                var action = control.dataset.action;

                if (action === 'menu') {
                    event.preventDefault();
                    if (control.getAttribute('aria-expanded') === 'true') { closeMenu(false); } else { showMenu(control); }
                    return;
                }
                if (action === 'add') { return; }

                if (action === 'toggle') {
                    var wasOn = control.classList.contains('is-on');
                    control.disabled = true;
                    post(url(urls.active, row.dataset.uid)).then(function (data) {
                        control.disabled = false;
                        if (data.status === 'success') {
                            control.classList.toggle('is-on', !wasOn);
                            control.setAttribute('aria-checked', wasOn ? 'false' : 'true');
                            var sub = row.querySelector('.mgx-who small');
                            if (wasOn && !sub) {
                                sub = document.createElement('small');
                                sub.textContent = 'Inactive';
                                row.querySelector('.mgx-who > div').appendChild(sub);
                            } else if (!wasOn && sub) {
                                sub.remove();
                            }
                            notify('success', data.message);
                        } else {
                            notify('error', firstError(data));
                        }
                    }).catch(function () { control.disabled = false; notify('error', text.failed); });
                    return;
                }

                closeMenu(false);
                event.preventDefault();

                if (action === 'rename' || action === 'duplicate' || action === 'delete') {
                    openDialog(action, row);
                    return;
                }

                if (action === 'export') {
                    var fields = [];
                    try { fields = JSON.parse(control.dataset.fields || '[]'); } catch (e) { fields = []; }
                    if (!fields.length) { notify('warning', text.failed); return; }
                    // The existing per-group export route takes the field tags as a POST form.
                    var exporter = document.createElement('form');
                    exporter.method = 'POST';
                    exporter.action = url(urls.exportGroup, row.dataset.uid);
                    exporter.style.display = 'none';
                    var add = function (name, value) {
                        var input = document.createElement('input');
                        input.type = 'hidden'; input.name = name; input.value = value;
                        exporter.appendChild(input);
                    };
                    add('_token', csrf);
                    add('include_phone', 'true');
                    fields.forEach(function (tag) { add('contact_fields[]', tag); });
                    document.body.appendChild(exporter);
                    exporter.submit();
                    exporter.remove();
                }
            });

            /* ---- search: submit shortly after typing stops ---- */
            var search = document.getElementById('groups-search');
            var searchTimer = null;
            if (search) {
                search.addEventListener('input', function () {
                    clearTimeout(searchTimer);
                    searchTimer = setTimeout(function () { search.form.submit(); }, 450);
                });
                if (search.value) {
                    var end = search.value.length;
                    search.focus();
                    search.setSelectionRange(end, end);
                }
            }
        })();
    </script>
@endsection
