{{--
    Customer Experience Slice 4 §11 — at most four real links, each already
    through the four-way rule; only canonical Business-scoped routes.
--}}
<section class="mb-2" aria-labelledby="dashboard-actions-heading" data-band="actions">
    <x-card>
        <h2 class="h4 text-section-heading mb-1" id="dashboard-actions-heading">Quick actions</h2>
        <div class="d-flex flex-wrap gap-1">
            @foreach($actions['items'] as $action)
                <x-button :variant="$loop->first ? 'primary' : 'secondary'" :href="$action->url" :icon="$action->icon"
                          data-role="quick-action" data-action="{{ $action->key }}">{{ $action->label }}</x-button>
            @endforeach
        </div>
        @if($actions['parentMessage'])
            <p class="text-caption text-muted mt-1 mb-0" data-role="parent-message">{{ $actions['parentMessage'] }}</p>
        @endif
    </x-card>
</section>
