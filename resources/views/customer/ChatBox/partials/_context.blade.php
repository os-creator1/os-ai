{{--
    Conversations — the contact panel beside the timeline
    (ConversationContextReader). Only what is stored: identity and details from
    the one Contact this number resolves to, the messaging status, and any
    section a registered domain supplies. No placeholder for a domain that
    does not exist yet.
--}}
<div class="conversation-context-inner mgx-ctx" data-role="contact-panel" data-header-initials="{{ $context->initials() }}" data-header-subtitle="{{ $context->name !== null ? \App\Library\Contacts\ContactPhone::pretty($context->phone) : '' }}{{ $context->group !== null ? ' · ' . $context->group : '' }}">
    <div class="conversation-context-person">
        <span class="mgx-ctx-av" aria-hidden="true">
            @if ($context->initials() !== '')
                {{ $context->initials() }}
            @else
                <x-ds-icon name="user" />
            @endif
        </span>
        <h5 class="mgx-ctx-name" data-role="contact-panel-name">
            @if ($profileUrl !== null)
                <a href="{{ $profileUrl }}" class="conversation-context-profile-link" data-role="contact-panel-name-link" title="View contact details">{{ $context->title() }}</a>
            @else
                {{ $context->title() }}
            @endif
        </h5>
        @if ($context->name !== null)
            <div class="visually-hidden text-numeric" data-role="contact-panel-phone">{{ $context->phone }}</div>
        @endif
        <span class="mgx-ctx-badge is-{{ $context->consentVariant() }}" data-role="contact-panel-consent">{{ $context->consentLabel() }}</span>
    </div>

    @if (! empty($call) || $profileUrl !== null || ($addContactUrl ?? null) !== null)
        <div class="mgx-ctx-actions conversation-context-actions">
        @if (! empty($call))
            {{-- Hidden until the page confirms the existing browser softphone is present; the server already verified this contact. --}}
            <button type="button" class="mgx-ctx-btn" data-role="conversation-call" data-call-uid="{{ $call['uid'] }}" data-call-conversation="{{ $call['conversation'] ?? '' }}" data-call-label="{{ $call['label'] }}" data-call-phone="{{ $call['phone'] }}" data-call-initials="{{ $call['initials'] }}" aria-label="Call {{ $call['label'] }}" hidden>
                <x-ds-icon name="phone" size="16" aria-hidden="true" />Call
            </button>
        @endif
            @if ($profileUrl !== null)
                <a class="mgx-ctx-btn is-brand" href="{{ $profileUrl }}" data-role="contact-panel-profile">Open contact</a>
            @elseif (($addContactUrl ?? null) !== null)
                <a class="mgx-ctx-btn is-brand" href="{{ $addContactUrl }}" data-role="contact-panel-add">Add to contacts</a>
            @endif
        </div>
    @endif

    <dl class="mgx-kv conversation-context-facts" data-role="contact-panel-identity">
        <dt>Phone</dt>
        <dd class="text-numeric">{{ \App\Library\Contacts\ContactPhone::pretty($context->phone) }}</dd>

        @if ($context->email !== null)
            <dt>Email</dt>
            <dd>{{ $context->email }}</dd>
        @endif

        @if ($context->company !== null)
            <dt>Company</dt>
            <dd>{{ $context->company }}</dd>
        @endif

        @if ($context->group !== null)
            <dt>Group</dt>
            <dd class="is-brand">{{ $context->group }}</dd>
        @endif

        @if ($context->addedAt !== null)
            <dt>Added</dt>
            <dd>{{ $context->addedAt->format('M j, Y') }}</dd>
        @endif

        @if ($context->businessNumber !== null && $context->businessNumber !== '')
            <dt>Texts from</dt>
            <dd class="text-numeric">{{ \App\Library\Contacts\ContactPhone::pretty($context->businessNumber) }}</dd>
        @endif
    </dl>

    @if ($context->details !== [])
        <h6 class="conversation-context-heading">Details</h6>
        <dl class="mgx-kv conversation-context-facts" data-role="contact-panel-details">
            @foreach ($context->details as $detail)
                <dt>{{ $detail['label'] }}</dt>
                <dd>{{ $detail['value'] }}</dd>
            @endforeach
        </dl>
    @endif

    @foreach ($context->sections as $section)
        <h6 class="conversation-context-heading">{{ $section['title'] }}</h6>
        <dl class="conversation-context-facts" data-role="contact-panel-section">
            @foreach ($section['rows'] as $row)
                <dt>{{ $row['label'] }}</dt>
                <dd>{{ $row['value'] }}</dd>
            @endforeach
        </dl>
        @foreach (($section['actions'] ?? []) as $action)
            <form method="post" action="{{ $action['url'] }}" class="mt-1" data-role="contact-panel-action">
                @csrf
                <x-button type="submit" variant="outline" size="sm">{{ $action['label'] }}</x-button>
            </form>
        @endforeach
    @endforeach

    @if ($profileUrl === null && ! $context->hasContact())
        <p class="text-caption mt-2 mb-0" data-role="contact-panel-unlinked">This number is not linked to a contact.</p>
    @endif
</div>
