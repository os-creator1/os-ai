{{--
    Section-only response for the Calendar tab router (?fragment=1): the same
    #calendar-content element the full page renders, with none of the shell
    around it. Produced by the SAME controller action as the full page, so it
    passes the same tenancy, entitlement and Location gates and reads exactly
    the same data — there is no second read surface.
--}}<div id="calendar-content"
     data-calendar-section="{{ trim($__env->yieldContent('calendar-active')) }}"
     data-calendar-title="{{ trim($__env->yieldContent('title')) }}">
    @yield('calendar-section')
</div>
