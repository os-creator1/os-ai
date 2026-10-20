<div class="border rounded p-2 mb-1" data-testimonial>
    <div class="row g-1">
        <div class="col-6"><label class="small mb-0">Customer name</label><input class="form-control form-control-sm" data-t="customer_name" maxlength="80" value="{{ $t['customer_name'] ?? '' }}" @disabled(! $canEdit)></div>
        <div class="col-6"><label class="small mb-0">Business</label><input class="form-control form-control-sm" data-t="business_name" maxlength="80" value="{{ $t['business_name'] ?? '' }}" @disabled(! $canEdit)></div>
        <div class="col-6"><label class="small mb-0">Industry</label><input class="form-control form-control-sm" data-t="industry" maxlength="60" value="{{ $t['industry'] ?? '' }}" @disabled(! $canEdit)></div>
        <div class="col-6"><label class="small mb-0">YouTube link or ID (optional)</label><input class="form-control form-control-sm" data-t="video" maxlength="255" value="{{ $t['video'] ?? '' }}" @disabled(! $canEdit)></div>
        <div class="col-12"><label class="small mb-0">Quote</label><textarea class="form-control form-control-sm" rows="2" data-t="quote" maxlength="400" @disabled(! $canEdit)>{{ $t['quote'] ?? '' }}</textarea></div>
        <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
            <label class="small"><input type="checkbox" data-t="approved" @checked($t['approved'] ?? false) @disabled(! $canEdit)> I have permission to publish this</label>
            <label class="small"><input type="checkbox" data-t="featured" @checked($t['featured'] ?? false) @disabled(! $canEdit)> Featured</label>
            <label class="small"><input type="checkbox" data-t="visible" @checked($t['visible'] ?? true) @disabled(! $canEdit)> Show</label>
            @if ($canEdit)
                <button type="button" class="btn btn-sm btn-outline-secondary" data-up aria-label="Move up">↑</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-down aria-label="Move down">↓</button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-remove>Remove</button>
            @endif
        </div>
    </div>
</div>
