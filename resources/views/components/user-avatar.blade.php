{{--
    The signed-in person's avatar in the top bar and its account menu.

    A photo only when one was uploaded AND its file is still on disk.
    Otherwise their initials (or a person icon when the name has none),
    drawn in place with no request at all, so a missing photo can never
    come back as a broken-image icon. Should the photo request fail anyway,
    the initials take its place.

    Its colours come from the top bar it sits on (mg-topbar in
    ds-components.scss): white with primary-coloured initials on a coloured
    bar, primary with white initials on a light one.

    Decorative: the person's name is already written beside it.
--}}
@props(['user'])

@php
    $initials = $user->initials();
    $photoPath = $user->imagePath();
    $hasPhoto = $photoPath !== '' && is_file($photoPath);
@endphp

<span {{ $attributes->merge(['class' => 'mg-avatar']) }} data-role="user-avatar">
    @if($hasPhoto)
        <img class="mg-avatar-photo" src="{{ route('user.avatar') }}" alt="" height="32" width="32" data-role="user-avatar-photo"
             onerror="this.hidden = true; this.nextElementSibling.hidden = false;" />
    @endif
    <span class="mg-avatar-initials" data-role="user-avatar-initials" aria-hidden="true" @if($hasPhoto) hidden @endif>
        @if($initials !== '')
            {{ $initials }}
        @else
            <x-ds-icon name="user" size="16" />
        @endif
    </span>
</span>
