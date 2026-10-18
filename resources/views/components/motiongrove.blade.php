{{--
    MotionGrove — the reusable branded loading indicator (three thick bars that shift height), in the
    application's primary colour. See partials/section-router/_styles.blade.php for the CSS, which a page
    includes once. Decorative: the region being loaded carries aria-busy.
--}}
<span {{ $attributes->merge(['class' => 'motiongrove', 'role' => 'presentation', 'aria-hidden' => 'true']) }}><i></i><i></i><i></i></span>
