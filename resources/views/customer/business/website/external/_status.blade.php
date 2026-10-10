{{-- The state of the latest check, in the owner's words. $latest is the newest ExternalSiteCrawl (any status) or null. --}}
@php
    $reasons = [
        'ip_literal_not_allowed' => 'The address must be a website name such as example.com, not a numeric address.',
        'host_not_allowed' => 'That address is not a public website we can check.',
        'private_destination' => 'That address does not point to a public website.',
        'dns_failed' => 'We could not find that website. Check the spelling of the address.',
        'port_not_allowed' => 'We can only check websites on the normal web ports.',
        'scheme_not_allowed' => 'The address must start with http:// or https://.',
        'url_malformed' => 'That does not look like a website address.',
        'no_website_url' => 'No website address is saved yet.',
        'stale' => 'The last check did not finish. Please check again.',
        'nothing_fetched' => 'We could not load any page of your website.',
    ];
@endphp

@if($latest === null)
    <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="status-none">Your website has not been checked yet.</x-alert>
@elseif($latest->isActive())
    <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="status-running">We are checking your website now. Refresh in a minute or two to see the results.</x-alert>
@elseif($latest->status === 'failed')
    <x-alert variant="warning" icon="alert-triangle" role="status" class="mb-2" data-role="status-failed">
        The last check did not complete. {{ $reasons[$latest->failure_code] ?? 'Please try again in a little while.' }}
    </x-alert>
@elseif($latest->failure_code === 'limit_reached')
    <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="status-limit">Your website is larger than we check in one go, so these results cover the first {{ $latest->pages_fetched }} pages we found.</x-alert>
@endif
