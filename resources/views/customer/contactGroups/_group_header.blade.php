{{--
    Group Details: the header and six-tab bar shared by every tab of a group
    (Contacts, Message, Fields, Keywords, Import history, Settings). The tab
    links are the page's existing Bootstrap tab toggles, so the panes and all
    of their behaviour are unchanged; only their presentation is new.
--}}
@php
    $gdTotal = (int) $contact->totalSubscribers($contact->cache);
    $gdLastImport = isset($import_jobs) ? $import_jobs->first()?->created_at : null;
    $gdCanMessage = auth()->user()->canany(['sms_quick_send', 'sms_campaign_builder']);
    $gdTabs = [
        ['contact', 'contact', 'Contacts', $gdCanContacts],
        ['message', 'message', 'Message', $gdCanManage],
        ['fields', 'fields', 'Fields', $gdCanManage],
        ['keywords', 'keywords', 'Keywords', $gdCanManage],
        ['import_history', 'import-history', 'Import history', $gdCanImport],
        ['settings', 'settings', 'Settings', $gdCanManage],
    ];
@endphp

<div class="mgx-gd-head" data-role="group-header">
    <a class="mgx-gd-back" href="{{ \App\Library\CrmRouting::route('contacts.index') }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>Groups</a>
    <div class="mgx-gd-title">
        <span class="mgx-gico" aria-hidden="true"><svg class="mgx-ic" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5M16 5a3.5 3.5 0 0 1 0 6.5M18 14.8c1.8.8 3 2.6 3 5.2"/></svg></span>
        <div class="mgx-gd-name">
            <h1 class="mgx-title">{{ $contact->name }}</h1>
            <span data-role="group-meta">{{ number_format($gdTotal) }} {{ $gdTotal === 1 ? 'contact' : 'contacts' }}@if ($gdLastImport !== null) · last import {{ $gdLastImport->diffForHumans() }}@endif @unless ($contact->status) · inactive @endunless</span>
        </div>
        @if ($gdCanMessage)
            <a class="mgx-btn is-secondary" data-role="group-message" href="{{ \App\Library\CrmRouting::route('outreach.index') }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M4 5h16v11H9l-5 4z"/></svg>Message group</a>
        @endif
        @can('create_contact')
            <a class="mgx-btn is-primary" data-role="group-add-contact" href="{{ \App\Library\CrmRouting::route('contact.create', $contact->uid) }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Add contact</a>
        @endcan
    </div>
</div>

<nav class="nav mgx-tabs mgx-gd-tabs" aria-label="Group" data-role="group-tabs" role="tablist">
    @foreach ($gdTabs as [$key, $pane, $label, $allowed])
        @if ($allowed)
            <a class="mgx-tab{{ $gdActive === $key ? ' active' : '' }}"
               id="{{ $pane }}-tab-justified"
               data-bs-toggle="tab" href="#{{ $pane }}" role="tab" aria-controls="{{ $pane }}"
               aria-selected="{{ $gdActive === $key ? 'true' : 'false' }}"
               @if ($gdActive === $key) aria-current="page" @endif>{{ $label }}@if ($key === 'contact')<span class="mgx-count" data-role="group-contacts-count">{{ number_format($gdTotal) }}</span>@endif</a>
        @endif
    @endforeach
</nav>
