{{--
    The ONE customer context switcher (Lane E).

    The whole current-context block is the control: the frame label, the
    current name and a subtle chevron. Clicking it opens a menu of the
    destinations the actor can legitimately reach — the Businesses inside the
    accounts they can see, and those accounts' own frames.

    $contextSwitcher is an App\Library\Navigation\ContextSwitcherView supplied
    by CustomerShellComposer, which also owns every rule about what may appear:
    this file decides nothing. Physical Locations are not a shell context and
    never appear here; there is no "create account" control.

    Every choice is a CSRF-protected POST that the server re-resolves and
    re-authorizes (SwitchBusinessAction / SwitchAccountAction). While viewing as
    a client the block is the viewed client's identity instead, because the
    banner carries the Exit control and switching context is prohibited.

    variant: "sidebar" (default) renders the block at the top of the vertical
    menu; "navbar" renders the compact control the horizontal layout uses, where
    there is no sidebar to host a block.
--}}
@props(['variant' => 'sidebar'])
@php
    $switcher = $contextSwitcher ?? null;
    $isSidebar = $variant !== 'navbar';
@endphp
@if($switcher instanceof \App\Library\Navigation\ContextSwitcherView)
    <div class="customer-context-switcher {{ $isSidebar ? 'px-1 pt-1 pb-50' : 'd-flex align-items-center ms-50' }}"
         data-role="context-switcher"
         data-variant="{{ $isSidebar ? 'sidebar' : 'navbar' }}">
        @if(! $switcher->interactive)
            <span class="customer-context-identity {{ $isSidebar ? 'd-block px-1' : '' }}" data-role="context-identity" aria-label="{{ $switcher->identityAriaLabel }}">
                @if($isSidebar)
                    <span class="d-block text-muted text-caption text-uppercase customer-context-frame">{{ $switcher->frameLabel }}</span>
                @endif
                <span class="d-block fw-bolder customer-context-current-name">{{ $switcher->currentName }}</span>
            </span>
        @else
            <div class="dropdown ds-menu {{ $isSidebar ? 'w-100' : '' }}">
                <button
                    class="btn btn-flat-secondary d-flex align-items-center gap-50 transition-fast {{ $isSidebar ? 'w-100 text-start justify-content-between' : 'btn-sm' }}"
                    type="button"
                    id="customer-context-switcher-toggle"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="true"
                    aria-haspopup="menu"
                    aria-expanded="false"
                    aria-label="{{ $switcher->toggleAriaLabel }}"
                >
                    <span class="d-block text-truncate">
                        @if($isSidebar)
                            <span class="d-block text-muted text-caption text-uppercase customer-context-frame">{{ $switcher->frameLabel }}</span>
                        @endif
                        <span class="d-block fw-bolder text-truncate customer-context-current-name">{{ $switcher->currentName }}</span>
                    </span>
                    <x-ds-icon name="chevron-down" size="16" aria-hidden="true" />
                </button>
                {{--
                    The menu scrolls rather than growing past the shell: the
                    vertical menu clips its own overflow, so an agency with many
                    client accounts would otherwise lose the rows at the bottom.
                --}}
                <ul class="dropdown-menu ds-menu-list transition-slow {{ $isSidebar ? 'w-100' : '' }}"
                    role="menu"
                    aria-labelledby="customer-context-switcher-toggle"
                    data-role="context-switcher-menu"
                    style="max-height: 60vh; overflow-y: auto;">

                    @if($switcher->showsFilter)
                        <li role="none" class="px-50 pb-50">
                            <label class="visually-hidden" for="customer-context-switcher-filter">{{ $switcher->filterLabel }}</label>
                            <input type="search"
                                   id="customer-context-switcher-filter"
                                   class="form-control form-control-sm"
                                   data-role="context-switcher-filter"
                                   placeholder="{{ $switcher->filterLabel }}"
                                   autocomplete="off">
                        </li>
                    @endif

                    @if($switcher->businesses !== [])
                        <li role="none"><h6 class="dropdown-header text-caption text-muted mb-0">{{ $switcher->businessesHeading }}</h6></li>

                        @foreach($switcher->businesses as $business)
                            <li role="none" class="customer-context-option" data-role="context-option-business" data-option-name="{{ \Illuminate\Support\Str::lower($business->name) }}">
                                <form method="POST" action="{{ $business->switchUrl }}">
                                    @csrf
                                    <input type="hidden" name="workspace" value="{{ $business->workspaceUid }}">
                                    <input type="hidden" name="business" value="{{ $business->businessUid }}">
                                    <button type="submit" role="menuitem" class="dropdown-item d-flex align-items-center justify-content-between gap-1" @if($business->isCurrent) aria-current="true" @endif>
                                        <span>
                                            {{ $business->name }}
                                            @if($business->subtitle !== null)
                                                <small class="d-block text-muted">{{ $business->subtitle }}</small>
                                            @endif
                                        </span>
                                        @if($business->isCurrent)
                                            <x-badge variant="accent">Current</x-badge>
                                        @endif
                                    </button>
                                </form>
                                @if($business->viewAsUrl !== null)
                                    <form method="POST" action="{{ $business->viewAsUrl }}">
                                        @csrf
                                        <input type="hidden" name="workspace" value="{{ $business->workspaceUid }}">
                                        <input type="hidden" name="business" value="{{ $business->businessUid }}">
                                        <button type="submit" role="menuitem" class="dropdown-item small ps-3">View {{ $business->name }} as a client</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    @endif

                    @if($switcher->accounts !== [])
                        @if($switcher->businesses !== [])
                            <li role="none"><hr class="dropdown-divider"></li>
                        @endif
                        <li role="none"><h6 class="dropdown-header text-caption text-muted mb-0">{{ $switcher->accountsHeading }}</h6></li>

                        @foreach($switcher->accounts as $account)
                            <li role="none" class="customer-context-option" data-role="context-option-account">
                                <form method="POST" action="{{ $account->switchUrl }}">
                                    @csrf
                                    <input type="hidden" name="workspace" value="{{ $account->workspaceUid }}">
                                    <button type="submit" role="menuitem" class="dropdown-item d-flex align-items-center justify-content-between gap-1" @if($account->isCurrent) aria-current="true" @endif>
                                        <span>{{ $account->name }}</span>
                                        @if($account->isCurrent)
                                            <x-badge variant="accent">Current</x-badge>
                                        @endif
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    @endif

                    @if($switcher->links !== [])
                        <li role="none"><hr class="dropdown-divider"></li>
                        @foreach($switcher->links as $link)
                            <li role="none">
                                <a role="menuitem" class="dropdown-item" href="{{ $link->url }}">{{ $link->label }}</a>
                            </li>
                        @endforeach
                    @endif
                </ul>
            </div>

            @if($switcher->showsFilter)
                {{-- Only shipped for an account with enough Businesses to need it. --}}
                <script>
                    (function () {
                        var input = document.getElementById('customer-context-switcher-filter');

                        if (!input) {
                            return;
                        }

                        var menu = input.closest('[data-role="context-switcher-menu"]');

                        input.addEventListener('input', function () {
                            var needle = input.value.trim().toLowerCase();

                            menu.querySelectorAll('[data-role="context-option-business"]').forEach(function (option) {
                                var name = option.getAttribute('data-option-name') || '';
                                option.hidden = needle !== '' && name.indexOf(needle) === -1;
                            });
                        });

                        input.addEventListener('keydown', function (event) {
                            // Typing stays typing: the menu's own key handling
                            // must not swallow letters meant for the field.
                            if (event.key !== 'Escape') {
                                event.stopPropagation();

                                return;
                            }

                            // Escape closes the menu (Bootstrap's own handler)
                            // and returns focus to the control that opened it,
                            // so a keyboard user is never stranded.
                            var toggle = document.getElementById('customer-context-switcher-toggle');

                            if (toggle) {
                                window.setTimeout(function () {
                                    toggle.focus();
                                }, 0);
                            }
                        });
                    })();
                </script>
            @endif
        @endif
    </div>
@endif
