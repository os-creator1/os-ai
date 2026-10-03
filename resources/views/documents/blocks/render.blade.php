{{--
    Contract 17B §2 — the single block-document renderer view.

    Rendered ONLY through App\Library\Documents\Blocks\DocumentBlockRenderer,
    which prepares every value. All output below is escaped Blade output; the
    only unescaped values are the HtmlString results of the renderer's own
    $runs() (which escapes every run itself) and the controller-built
    $signatureHtml (public mode only).
--}}
@include('documents.blocks._styles')
<div class="doc-blocks" data-role="document-blocks" data-mode="{{ $mode }}">
@foreach($blocks as $block)
    @php($data = is_array($block['data'] ?? null) ? $block['data'] : [])
    <div class="doc-block doc-block-{{ $block['type'] }}" data-block-id="{{ $block['id'] ?? '' }}" data-block-type="{{ $block['type'] }}">
        @include('documents.blocks.types.' . $block['type'])
    </div>
@endforeach
</div>
