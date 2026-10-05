{{--
    Website V1 closure — a Forms-module form on a page. The ONLY thing a page stores is the stable uid of a
    Website-source FormDeployment; this view never takes a URL or markup from content.

    PUBLISHED pages render the reference frozen into the snapshot at publish time ($data['resolved']);
    PREVIEW re-resolves it live (the owner sees what the next publish would freeze). A reference that does
    not resolve (form switched off, Location closed) renders nothing — never an error and never another
    Business's form. The form itself is the real public Forms renderer (public.forms._embed), so a
    submission is an ordinary Forms submission (same service, version pinning, Contact resolution).
--}}
@php($embed = app(\App\Library\Website\Forms\WebsiteFormsModuleReferences::class)->renderable($data, $website, (bool) ($isPreview ?? false)))
@if (! empty($embed))
    <div class="website-section website-forms-module">
        @if (! empty($data['heading']))
            <h2 class="wd-section-title">{{ $data['heading'] }}</h2>
        @endif
        @include('public.forms._embed', ['embed' => $embed])
    </div>
@endif
