@php($image = $images[$data['catalog_image_uid'] ?? ''] ?? null)
@if($image)
    <img class="doc-image" src="{{ $image->url() }}" alt="{{ $data['alt'] ?? '' }}" style="width: {{ max(10, min(100, (int) ($data['width_pct'] ?? 100))) }}%">
@elseif(in_array($mode, ['editor', 'preview', 'template_preview'], true))
    <div class="doc-placeholder">Image</div>
@endif
