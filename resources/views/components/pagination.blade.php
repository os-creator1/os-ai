@props([
    'paginator', // Illuminate\Contracts\Pagination\LengthAwarePaginator
])

@if ($paginator->hasPages())
    <nav class="ds-pagination d-flex align-items-center justify-content-between" role="navigation" aria-label="Pagination">
        <p class="text-caption mb-0">
            {{ __('Showing :first to :last of :total', [
                'first' => $paginator->firstItem(),
                'last' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ]) }}
        </p>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item @if ($paginator->onFirstPage()) disabled @endif">
                @if ($paginator->onFirstPage())
                    <span class="page-link transition-fast" aria-disabled="true"><x-ds-icon name="chevron-left" size="14" /></span>
                @else
                    <a class="page-link transition-fast" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('Previous') }}"><x-ds-icon name="chevron-left" size="14" /></a>
                @endif
            </li>
            <li class="page-item disabled">
                <span class="page-link transition-fast text-numeric">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            </li>
            <li class="page-item @if (! $paginator->hasMorePages()) disabled @endif">
                @if ($paginator->hasMorePages())
                    <a class="page-link transition-fast" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('Next') }}"><x-ds-icon name="chevron-right" size="14" /></a>
                @else
                    <span class="page-link transition-fast" aria-disabled="true"><x-ds-icon name="chevron-right" size="14" /></span>
                @endif
            </li>
        </ul>
    </nav>
@endif
