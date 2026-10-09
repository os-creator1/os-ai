{{--
    Website -> Settings. One calm list of the existing management screens (each row is a link to the
    canonical, unchanged action — nothing is duplicated or re-implemented here) and the look controls.
--}}
@php
    $rows = [
        ['icon' => 'files', 'title' => 'Manage pages', 'meta' => $pageCount . ' ' . ($pageCount === 1 ? 'page' : 'pages'), 'url' => route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid]), 'key' => 'pages'],
        ['icon' => 'list-checks', 'title' => 'Edit setup answers', 'meta' => null, 'url' => route('customer.workspaces.businesses.website.edit-setup', [$workspaceUid, $businessUid]), 'key' => 'answers'],
        ['icon' => 'layout-template', 'title' => 'Change template or rebuild', 'meta' => $currentDesign ? 'Template ' . $currentDesign->number . ' — ' . $currentDesign->label : null, 'url' => route('customer.workspaces.businesses.website.rebuild.form', [$workspaceUid, $businessUid]), 'key' => 'template'],
        ['icon' => 'history', 'title' => 'History', 'meta' => null, 'url' => route('customer.workspaces.businesses.website.history', [$workspaceUid, $businessUid]), 'key' => 'history'],
        ['icon' => 'link', 'title' => 'Domain', 'meta' => $domain ? $domain->domain : ($domainRows > 0 ? 'Not live yet' : 'Not connected'), 'url' => route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid]), 'key' => 'domain'],
    ];
@endphp

<div class="website-settings" data-testid="website-settings">
    <x-card :padded="false" class="mb-3">
        <ul class="website-settings-list list-unstyled mb-0">
            @foreach ($rows as $row)
                <li>
                    <a class="website-settings-row" href="{{ $row['url'] }}" data-testid="settings-{{ $row['key'] }}">
                        <x-ds-icon :name="$row['icon']" size="18" aria-hidden="true" />
                        <span class="website-settings-title">{{ $row['title'] }}</span>
                        @if ($row['meta'])
                            <span class="website-settings-meta text-caption">{{ $row['meta'] }}</span>
                        @endif
                        <x-ds-icon name="chevron-right" size="16" class="website-settings-chevron" aria-hidden="true" />
                    </a>
                </li>
            @endforeach
        </ul>
    </x-card>

    @include('customer.business.website.studio._look')
</div>
