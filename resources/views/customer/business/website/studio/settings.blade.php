{{--
    Website -> Settings (hosted Website). A pale Domain callout, then two columns: "Manage your website" (each row is a link to
    the canonical, unchanged screen - nothing is duplicated or re-implemented here) and "Your website's look". Every value is
    the Website's own state: the active domain, the page count, the current template and the saved theme.
--}}
@php
    $domainsUrl = route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid]);
    $template = $currentDesign ? 'Template ' . $currentDesign->number : null;
    $rows = [
        ['icon' => 'files', 'title' => 'Manage pages', 'sub' => 'Add, rename or remove pages', 'meta' => [$pageCount . ' ' . ($pageCount === 1 ? 'page' : 'pages')], 'url' => route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid]), 'key' => 'pages'],
        ['icon' => 'list-checks', 'title' => 'Edit setup answers', 'sub' => 'Update what you told us about your business', 'meta' => [], 'url' => route('customer.workspaces.businesses.website.edit-setup', [$workspaceUid, $businessUid]), 'key' => 'answers'],
        ['icon' => 'layout-template', 'title' => 'Change template or rebuild', 'sub' => 'Pick a different design or start over', 'meta' => array_values(array_filter([$template, $currentDesign?->label])), 'url' => route('customer.workspaces.businesses.website.rebuild.form', [$workspaceUid, $businessUid]), 'key' => 'template'],
        ['icon' => 'history', 'title' => 'History', 'sub' => 'See past versions of your site', 'meta' => [], 'url' => route('customer.workspaces.businesses.website.history', [$workspaceUid, $businessUid]), 'key' => 'history'],
    ];

    if ($domain) {
        $domainChip = ['Live', 'success'];
        $domainText = 'Your website is reachable at ' . $domain->domain . '.';
        $domainAction = 'Manage domain';
    } elseif ($domainRows > 0) {
        $domainChip = ['Not live yet', 'warning'];
        $domainText = 'Finish connecting your domain so customers can reach your site at your address.';
        $domainAction = 'Finish setup';
    } else {
        $domainChip = ['Not live yet', 'warning'];
        $domainText = 'Connect your own domain so customers can reach your site at your address.';
        $domainAction = 'Set up domain';
    }
@endphp

<div class="website-settings" data-testid="website-settings">
    <div class="website-domain-card mb-2" data-testid="settings-domain-card">
        <span class="website-domain-icon" aria-hidden="true"><x-ds-icon name="link" size="16" /></span>
        <div class="website-domain-body">
            <div class="d-flex flex-wrap align-items-center gap-50">
                <strong>Domain</strong>
                <x-badge :variant="$domainChip[1]" data-testid="settings-domain-state">{{ $domainChip[0] }}</x-badge>
            </div>
            <p class="text-caption mb-0">{{ $domainText }}</p>
        </div>
        <a class="btn btn-primary website-domain-action" href="{{ $domainsUrl }}" data-testid="settings-domain">{{ $domainAction }} <x-ds-icon name="arrow-right" size="15" aria-hidden="true" /></a>
    </div>

    <div class="website-settings-grid">
        <div>
            <x-card :padded="false">
                <h6 class="website-settings-heading">Manage your website</h6>
                <ul class="website-settings-list list-unstyled mb-0">
                    @foreach ($rows as $row)
                        <li>
                            <a class="website-settings-row" href="{{ $row['url'] }}" data-testid="settings-{{ $row['key'] }}">
                                <span class="website-settings-icon" aria-hidden="true"><x-ds-icon :name="$row['icon']" size="16" /></span>
                                <span class="website-settings-text">
                                    <span class="website-settings-title">{{ $row['title'] }}</span>
                                    <span class="website-settings-sub">{{ $row['sub'] }}</span>
                                </span>
                                @if (count($row['meta']) > 0)
                                    <span class="website-settings-meta">
                                        @foreach ($row['meta'] as $line)<span>{{ $line }}</span>@endforeach
                                    </span>
                                @endif
                                <x-ds-icon name="chevron-right" size="16" class="website-settings-chevron" aria-hidden="true" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </div>

        <div>
            @include('customer.business.website.studio._look')
        </div>
    </div>
</div>
