{{--
    Conversations — the contact panel beside the timeline
    (ConversationContextReader). Only what is stored: identity and details from
    the one Contact this number resolves to, the messaging status, and any
    section a registered domain supplies. No placeholder for a domain that
    does not exist yet.
--}}
<div class="conversation-context-inner" data-role="contact-panel">
    <div class="conversation-context-person">
        <span class="avatar bg-light-primary conversation-context-avatar" aria-hidden="true">
            <span class="avatar-content">
                @if ($context->initials() !== '')
                    {{ $context->initials() }}
                @else
                    <x-ds-icon name="user" />
                @endif
            </span>
        </span>
        <h5 class="mb-25 mt-1" data-role="contact-panel-name">{{ $context->title() }}</h5>
        @if ($context->name !== null)
            <div class="text-caption text-numeric" data-role="contact-panel-phone">{{ $context->phone }}</div>
        @endif
        <div class="mt-75">
            <x-badge :variant="$context->consentVariant()" data-role="contact-panel-consent">{{ $context->consentLabel() }}</x-badge>
        </div>
    </div>

    <dl class="conversation-context-facts" data-role="contact-panel-identity">
        <dt>Phone</dt>
        <dd class="text-numeric">{{ $context->phone }}</dd>

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
            <dd>{{ $context->group }}</dd>
        @endif

        @if ($context->addedAt !== null)
            <dt>Added</dt>
            <dd>{{ $context->addedAt->format('M j, Y') }}</dd>
        @endif

        @if ($context->businessNumber !== null && $context->businessNumber !== '')
            <dt>Your number</dt>
            <dd class="text-numeric">{{ $context->businessNumber }}</dd>
        @endif
    </dl>

    @if ($context->details !== [])
        <h6 class="conversation-context-heading">Details</h6>
        <dl class="conversation-context-facts" data-role="contact-panel-details">
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
    @endforeach

    @if ($profileUrl !== null)
        <div class="mt-2">
            <x-button variant="outline" size="sm" :href="$profileUrl" data-role="contact-panel-profile">Open contact</x-button>
        </div>
    @elseif (! $context->hasContact())
        <p class="text-caption mt-2 mb-0" data-role="contact-panel-unlinked">This number is not linked to a contact.</p>
    @endif
</div>
