{{--
    Customer Experience Slice 1B — the header context control (contract
    §9.2, Slice 1B brief §5). $customerContext is supplied by
    App\Library\Navigation\CustomerShellComposer.

    - One reachable Business: a compact identity, no pointless switcher.
    - Several: a keyboard-operable Bootstrap dropdown listing ONLY the
      Businesses the actor may enter; each choice is a CSRF-protected POST
      that is re-authorized server-side; the current one is marked with
      aria-current; Businesses are distinguished by their account name when
      the actor spans several accounts, never by an internal id.
    - Agency owner/admin additionally gets "View as client" per Business.
    - While viewing as a client the switcher is replaced by the identity of
      the viewed client (the banner carries the Exit control).
--}}
@if(isset($customerContext) && $customerContext instanceof \App\Library\Navigation\CustomerContext)
    @php
        $ctx = $customerContext;
        $noun = strtolower($ctx->businessNoun());
        $nounPlural = strtolower($ctx->businessesNoun());
        $accountsUrl = $ctx->selectedWorkspace !== null
            ? route('customer.workspaces.show', $ctx->selectedWorkspace->uid)
            : route('customer.workspaces.index');
    @endphp
    <div class="customer-context-switcher d-flex align-items-center ms-50" data-role="context-switcher">
        @if($ctx->isViewingAsClient())
            <span class="customer-context-identity fw-bolder" data-role="context-identity" aria-label="Current client account">
                {{ $ctx->headerLabel() }}
            </span>
        @elseif($ctx->showsSwitcher())
            <div class="dropdown ds-menu">
                <button
                    class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-50 transition-fast"
                    type="button"
                    id="customer-context-switcher-toggle"
                    data-bs-toggle="dropdown"
                    aria-haspopup="menu"
                    aria-expanded="false"
                    aria-label="{{ $ctx->selectedBusiness !== null ? 'Current ' . $noun . ': ' . $ctx->selectedBusiness->name . '. Switch ' . $noun : 'Choose a ' . $noun }}"
                >
                    <x-ds-icon name="briefcase" size="16" aria-hidden="true" />
                    <span class="customer-context-current-name">{{ $ctx->headerLabel() }}</span>
                    <x-ds-icon name="chevron-down" size="14" aria-hidden="true" />
                </button>
                <ul class="dropdown-menu ds-menu-list transition-slow" role="menu" aria-labelledby="customer-context-switcher-toggle">
                    @foreach($ctx->selectableBusinesses() as $business)
                        @php $isCurrent = $ctx->selectedBusiness !== null && $ctx->selectedBusiness->uid === $business->uid; @endphp
                        <li role="none" class="customer-context-option">
                            <form method="POST" action="{{ route('customer.context.business.switch') }}">
                                @csrf
                                <input type="hidden" name="workspace" value="{{ $business->workspaceUid }}">
                                <input type="hidden" name="business" value="{{ $business->uid }}">
                                <button type="submit" role="menuitem" class="dropdown-item d-flex align-items-center justify-content-between gap-1" @if($isCurrent) aria-current="true" @endif>
                                    <span>
                                        {{ $business->name }}
                                        @if($ctx->hasMultipleWorkspaces())
                                            <small class="d-block text-muted">{{ $business->workspaceName }}</small>
                                        @endif
                                    </span>
                                    @if($isCurrent)
                                        <x-badge variant="accent">Current</x-badge>
                                    @endif
                                </button>
                            </form>
                            @if($ctx->canViewAsClient())
                                <form method="POST" action="{{ route('customer.view-as.start') }}">
                                    @csrf
                                    <input type="hidden" name="workspace" value="{{ $business->workspaceUid }}">
                                    <input type="hidden" name="business" value="{{ $business->uid }}">
                                    <button type="submit" role="menuitem" class="dropdown-item small ps-3">View {{ $business->name }} as a client</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                    <li role="none"><hr class="dropdown-divider"></li>
                    <li role="none">
                        <a role="menuitem" class="dropdown-item" href="{{ $accountsUrl }}">All {{ $nounPlural }}</a>
                    </li>
                </ul>
            </div>
        @else
            <span class="customer-context-identity fw-bolder" data-role="context-identity" aria-label="Current {{ $noun }}">
                {{ $ctx->headerLabel() }}
            </span>
        @endif
    </div>
@endif
