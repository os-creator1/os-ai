{{--
    Section-only response for the Content tab router (?fragment=1): the same #seo-content-region the full page renders, with
    none of the shell around it. Produced by the SAME controller action as the full page, so it passes the same tenancy,
    entitlement and permission gates and reads the same data - there is no second read surface. The header action (if any)
    travels in an inert <template> so the router can place it in the stable header.
--}}<div id="seo-content-region"
     data-content-section="{{ trim($__env->yieldContent('content-active')) }}"
     data-content-title="{{ trim($__env->yieldContent('title')) }}"
     data-content-subtitle="{{ trim($__env->yieldContent('content-subtitle')) }}">
    <template data-content-action>@yield('content-action')</template>
    @yield('content-section')
</div>
