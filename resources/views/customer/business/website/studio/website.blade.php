{{--
    Website Builder redesign — one cohesive view of the website's status
    and its available actions, replacing the old two disconnected
    "Pages"/"Publish" cards. Every action below reuses the existing,
    unchanged WebsiteController routes verbatim.
--}}
@if ($website->status->value === 'published')
    <x-alert variant="accent" class="mb-3">
        Live at
        <a href="{{ route('public.website.home', $website->public_id) }}" target="_blank" rel="noopener">{{ route('public.website.home', $website->public_id) }}</a>
    </x-alert>
@endif

@if (! empty($mediaWarnings))
    <x-alert variant="warning" class="mb-3">
        <strong>Missing media checklist:</strong>
        <ul class="mb-0">
            @foreach ($mediaWarnings as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif

<x-card class="mb-3">
    <p class="text-caption">{{ $pageCount }} page(s) generated.</p>
    <div class="d-flex flex-wrap gap-2">
        <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.preview', [$workspaceUid, $businessUid]) }}">Preview</x-button>

        <form method="POST" action="{{ route('customer.workspaces.businesses.website.publish', [$workspaceUid, $businessUid]) }}" class="d-inline">
            @csrf
            <x-button variant="primary" type="submit">Publish</x-button>
        </form>

        <x-button variant="ghost" href="{{ route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid]) }}">Connect a domain</x-button>
        <x-button variant="ghost" href="{{ route('customer.workspaces.businesses.website.history', [$workspaceUid, $businessUid]) }}">History</x-button>
        <x-button variant="ghost" href="{{ route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid]) }}">Manage pages</x-button>
        <x-button variant="ghost" href="{{ route('customer.workspaces.businesses.website.edit-setup', [$workspaceUid, $businessUid]) }}">Edit setup answers</x-button>
    </div>

    <form method="POST" action="{{ route('customer.workspaces.businesses.website.generate', [$workspaceUid, $businessUid]) }}" class="mt-3">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <x-button variant="outline" type="submit">Regenerate with AI</x-button>
    </form>
</x-card>
