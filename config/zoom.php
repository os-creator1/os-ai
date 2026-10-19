<?php

/*
|--------------------------------------------------------------------------
| Zoom meetings for booking types (docs/product/ZOOM-MEETINGS.md)
|--------------------------------------------------------------------------
|
| A booking type with `zoom_enabled` gets one Zoom meeting per appointment, created
| through a Business's own OAuth connection (user-managed OAuth app, authorization
| code flow). No Zoom password or pasted token is ever stored.
|
| The feature is inert until both credentials are set.
*/

return [
    'client_id' => env('ZOOM_CLIENT_ID'),
    'client_secret' => env('ZOOM_CLIENT_SECRET'),

    // Must equal the redirect URL registered in the Zoom app exactly:
    // https://<platform host>/zoom/oauth/callback
    'redirect' => env('ZOOM_REDIRECT_URI'),

    'authorize_url' => 'https://zoom.us/oauth/authorize',
    'token_url' => 'https://zoom.us/oauth/token',
    'api_base' => 'https://api.zoom.us/v2',

    // Granular scopes the Zoom app must have (Zoom Marketplace > the app > Scopes). They are configured on the
    // app, not requested per authorization. `meeting:read:list_meetings` + `meeting:read:meeting` (verify identity via GET /meetings/{id}) let us recover a create whose response was lost (without it such a booking is held, never
    // duplicated). `user:read:user` is optional: it only lets us show which account connected.
    'scopes' => ['meeting:write:meeting', 'meeting:update:meeting', 'meeting:delete:meeting', 'meeting:read:list_meetings', 'meeting:read:meeting', 'user:read:user'],

    'http_timeout_seconds' => 15,

    // Meeting settings sent on create. Participants wait to be admitted, the host starts the meeting.
    'meeting_settings' => [
        'join_before_host' => false,
        'waiting_room' => true,
        'host_video' => true,
        'participant_video' => true,
        'mute_upon_entry' => false,
    ],

    // Bounded retries for a provisioning job: attempts, then the wait before each retry (seconds).
    'max_attempts' => 6,
    'backoff_seconds' => [30, 120, 600, 1800, 3600],

    // The signed OAuth state stays valid this long.
    'state_ttl_minutes' => 15,
];
