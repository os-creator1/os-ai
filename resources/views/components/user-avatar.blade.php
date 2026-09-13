{{--
    The signed-in person's avatar in the navbar.

    A photo only when one was uploaded AND its file is still on disk.
    Otherwise their initials (or a person icon when the name has none),
    drawn in place with no request at all, so a missing photo can never
    come back as a broken-image icon. Should the photo request fail anyway,
    the initials take its place.

    Decorative: the person's name is already written beside it.
--}}
@props(['user'])

@php
    $initials = $user->initials();
    $photoPath = $user->imagePath();
    $hasPhoto = $photoPath !== '' && is_file($photoPath);
@endphp

<span {{ $attributes->merge(['class' => 'avatar bg-light-primary']) }} data-role="user-avatar">
    @if($hasPhoto)
        <img class="round" src="{{ route('user.avatar') }}" alt="" height="40" width="40" data-role="user-avatar-photo"
             onerror="this.hidden = true; this.nextElementSibling.hidden = false;" />
    @endif
    <span class="avatar-content" data-role="user-avatar-initials" aria-hidden="true" @if($hasPhoto) hidden @endif>
        @if($initials !== '')
            {{ $initials }}
        @else
            <x-ds-icon name="user" class="avatar-icon" />
        @endif
    </span>
</span>
