@php use App\Library\Tool; @endphp
@if(isset($configData["mainLayoutType"]) && $configData["mainLayoutType"] == 'horizontal')
    <nav class="header-navbar navbar-expand-lg navbar navbar-fixed align-items-center navbar-shadow navbar-brand-center transition-base {{ $configData['navbarColor'] }}"
         data-nav="brand-center">
        <div class="navbar-header d-xl-block d-none">
            <ul class="nav navbar-nav">
                @if(Auth::user()->active_portal == 'customer' && Auth::user()->is_customer == 1 && Auth::user()->customer->activeSubscription())
                    <li class="nav-item"><a class="navbar-brand" href="{{route('user.home')}}">
                            <span class="brand-logo"><x-branding-logo variant="full" background="light" /></span>
                        </a>
                    </li>
                @else
                    <li class="nav-item"><a class="navbar-brand" href="{{\App\Helpers\Helper::adminHomeUrl()}}">
                            <span class="brand-logo"><x-branding-logo variant="full" background="light" /></span>
                        </a>
                    </li>
                @endif
            </ul>
        </div>
        @else
            <nav class="header-navbar navbar navbar-expand-lg align-items-center transition-base {{ $configData['navbarClass'] }} navbar-light navbar-shadow {{ $configData['navbarColor'] }} {{$configData['layoutWidth'] === 'boxed' && $configData['verticalMenuNavbarType'] === 'navbar-floating' ? 'container-xxl' : '' }}">
                @endif


                <div class="navbar-container d-flex content">
                    <div class="bookmark-wrapper d-flex align-items-center">
                        <ul class="nav navbar-nav d-xl-none">
                            <li class="nav-item"><a class="nav-link menu-toggle" href="javascript:void(0);" role="button" aria-label="Open navigation menu" aria-controls="main-menu-navigation"><x-ds-icon name="menu" class="ficon" aria-hidden="true" /></a></li>
                        </ul>
                        {{--
                            Lane E: the context switcher is the block at the top
                            of the vertical sidebar, so the navbar carries it
                            ONLY in the horizontal layout, which has no sidebar
                            to host it. One control per page, never two that
                            could disagree. Renders nothing for the admin portal.
                        --}}
                        @if(($configData['mainLayoutType'] ?? 'vertical') !== 'vertical')
                            <x-customer-context-switcher variant="navbar" />
                        @endif
                        @if(config('app.stage') == 'demo')
                            <ul class="nav navbar-nav bookmark-icons d-sm-flex d-none">

                                <li class="nav-item dropdown">
                                    @if(Auth::user()->active_portal == 'admin')
                                        <a class="nav-link dropdown-toggle btn btn-info btn-sm p-1"
                                           href="{{ route('user.switch_view', ['portal' => 'customer']) }}">
                                            <span class="text-uppercase">Campaign View</span>
                                        </a>

                                    @endif

                                    @if(Auth::user()->active_portal == 'customer')
                                        <a class="nav-link dropdown-toggle btn btn-info btn-sm p-1"
                                           href="{{ route('user.switch_view', ['portal' => 'admin']) }}">
                                            <span class="text-uppercase">Admin View</span>
                                        </a>
                                    @endif
                                </li>

                            </ul>

                        @endif

                    </div>


                    <ul class="nav navbar-nav align-items-center ms-auto mg-topbar" data-role="topbar-actions">

                        {{-- The application language is chosen at sign-up and changed under
                             Profile (users.locale); it is not a top-bar control. --}}

                        {{--Dark and light option. It will be theme manager option--}}
                        {{--                        <li class="nav-item d-none d-lg-block">--}}
                        {{--                            <a class="nav-link nav-link-style"><x-ds-icon name="{{ $configData['theme'] === 'dark' ? 'sun' : 'moon' }}" class="ficon" /></a>--}}
                        {{--                        </li>--}}

                        {{-- Blueprint §7/§24 — Global Search, inside the current selected
                             Business only. Searches Contacts, Opportunities, Conversations
                             and Payments & Contracts documents; each result has already been
                             authorized server-side (GlobalSearchController) before it ever
                             reaches this markup. --}}
                        @if($customerContext?->isBusinessFrame() && $customerContext->selectedWorkspace && $customerContext->selectedBusiness)
                            <li class="nav-item dropdown me-25" data-role="global-search">
                                <div class="position-relative">
                                    <x-search-field id="global-search-input" label="Search" placeholder="Search this business" class="me-1" style="width: min(220px, 34vw);" />
                                    <div class="dropdown-menu dropdown-menu-media" data-role="global-search-results" style="display:none; width: 320px;">
                                        <div class="scrollable-container media-list" data-role="global-search-results-list"></div>
                                        <div class="p-2 text-muted small" data-role="global-search-empty" style="display:none;">No results.</div>
                                    </div>
                                </div>
                            </li>
                            <script>
                                document.addEventListener('DOMContentLoaded', function () {
                                    var input = document.getElementById('global-search-input');
                                    var panel = document.querySelector('[data-role="global-search-results"]');
                                    var list = document.querySelector('[data-role="global-search-results-list"]');
                                    var empty = document.querySelector('[data-role="global-search-empty"]');
                                    if (!input || !panel || !list) { return; }

                                    var searchUrl = @json(route('customer.workspaces.businesses.search', [$customerContext->selectedWorkspace->uid, $customerContext->selectedBusiness->uid]));
                                    var groupLabels = { contacts: 'Contacts', opportunities: 'Opportunities', conversations: 'Conversations', documents: 'Payments & Contracts' };
                                    var timer = null;
                                    var controller = null;

                                    function render(results) {
                                        list.innerHTML = '';
                                        var total = 0;
                                        Object.keys(groupLabels).forEach(function (key) {
                                            var items = (results && results[key]) || [];
                                            if (items.length === 0) { return; }
                                            total += items.length;
                                            var header = document.createElement('div');
                                            header.className = 'dropdown-header text-muted small fw-bolder px-1 pt-1';
                                            header.textContent = groupLabels[key];
                                            list.appendChild(header);
                                            items.forEach(function (item) {
                                                var a = document.createElement('a');
                                                a.className = 'dropdown-item d-flex flex-column';
                                                a.href = item.url;
                                                var title = document.createElement('span');
                                                title.textContent = item.title;
                                                a.appendChild(title);
                                                if (item.subtitle) {
                                                    var subtitle = document.createElement('small');
                                                    subtitle.className = 'text-muted';
                                                    subtitle.textContent = item.subtitle;
                                                    a.appendChild(subtitle);
                                                }
                                                list.appendChild(a);
                                            });
                                        });
                                        empty.style.display = total === 0 ? 'block' : 'none';
                                        panel.style.display = 'block';
                                    }

                                    input.addEventListener('input', function () {
                                        var q = input.value.trim();
                                        if (timer) { clearTimeout(timer); }
                                        if (controller) { controller.abort(); }
                                        if (q === '') { panel.style.display = 'none'; return; }
                                        timer = setTimeout(function () {
                                            controller = new AbortController();
                                            fetch(searchUrl + '?q=' + encodeURIComponent(q), {
                                                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                                signal: controller.signal,
                                            })
                                                .then(function (response) { return response.json(); })
                                                .then(function (payload) { render(payload.results); })
                                                .catch(function () {});
                                        }, 250);
                                    });

                                    document.addEventListener('click', function (event) {
                                        if (!event.target.closest('[data-role="global-search"]')) {
                                            panel.style.display = 'none';
                                        }
                                    });
                                });
                            </script>
                        @endif

                        {{-- Notifications (components/topbar-notifications): the bell, its
                             unread indicator and the existing notification list. --}}
                        <x-topbar-notifications />


                        @if(Auth::user()->active_portal == 'customer' && Auth::user()->is_customer == 1 && Auth::user()->customer->activeSubscription())
                            <li class="nav-item balance-top-up">
                                <div class="show-balance">
                                    <span class="show-balance-text text-uppercase">{{ __('locale.labels.sms_credit') }}</span>
                                    <span class="show-balance-unit">{{ Auth::user()->sms_unit == '-1' ? __('locale.labels.unlimited') : Tool::format_number(Auth::user()->sms_unit) }}</span>
                                </div>
                                <a class="nav-link top-up-url d-sm-block" href="{{ route('user.account.top_up') }}">
                                    <button type="button" class="btn btn-sm btn-outline-success">
                                        <span class="font-weight-bold"
                                              style="font-size: 12px">{{ __('locale.labels.top_up') }}</span>
                                    </button>
                                </a>
                            </li>
                        @endif


                        {{-- The account button and menu (components/topbar-account). Settings
                             links only where this actor's own sidebar already offers it. --}}
                        @php
                            $accountSettingsUrl = app(\App\Library\Navigation\AccountMenuSettingsLink::class)
                                ->urlFor(Auth::user(), $customerMenu ?? [], request()->path());
                        @endphp
                        <x-topbar-account :user="Auth::user()" :settings-url="$accountSettingsUrl" />
                    </ul>
                    <script>
                        // The top bar's two menus use Bootstrap's own dropdown. Two gaps it
                        // leaves in this shell are closed here, once, for both:
                        //  - the sidebar menu stops click propagation, so a click there never
                        //    reached Bootstrap's outside-click handling; a capture-phase
                        //    listener sees it first;
                        //  - closing from inside the menu (Escape) returns focus to its button
                        //    instead of dropping it to the page.
                        document.addEventListener('DOMContentLoaded', function () {
                            if (!window.bootstrap || !window.bootstrap.Dropdown) { return; }

                            document.querySelectorAll('[data-role="topbar-actions"] > .mg-topbar-item').forEach(function (item) {
                                var trigger = item.querySelector(':scope > [data-bs-toggle="dropdown"]');
                                var focusWasInside = false;
                                if (!trigger) { return; }

                                document.addEventListener('click', function (event) {
                                    if (trigger.getAttribute('aria-expanded') === 'true' && !item.contains(event.target)) {
                                        window.bootstrap.Dropdown.getOrCreateInstance(trigger).hide();
                                    }
                                }, true);

                                item.addEventListener('hide.bs.dropdown', function () {
                                    focusWasInside = item.contains(document.activeElement) && document.activeElement !== trigger;
                                });
                                item.addEventListener('hidden.bs.dropdown', function () {
                                    if (focusWasInside) { trigger.focus(); }
                                });
                            });
                        });
                    </script>
                </div>

            </nav>
            <!-- END: Header-->
            @include('partials.platform-notices')
