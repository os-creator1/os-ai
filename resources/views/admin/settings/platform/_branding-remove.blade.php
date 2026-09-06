{{--
    B3 Simplified Platform Settings §4/§17. One allowlisted asset key per
    submission, validated server-side by RemoveBrandingAssetRequest
    against the same six-key set BrandingUploadService::ENV_KEYS knows —
    never an arbitrary request-controlled env key or file path.
--}}
<form action="{{ route('admin.settings.branding.remove') }}" method="post" class="d-inline"
      onsubmit="return confirm('Remove the current {{ $label }}? It will fall back to the default.');">
    @csrf
    <input type="hidden" name="asset" value="{{ $asset }}">
    <button type="submit" class="btn btn-sm btn-outline-danger mt-1">
        <x-ds-icon name="trash-2" size="14" /> Remove
    </button>
</form>
