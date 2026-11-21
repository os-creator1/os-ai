<?php

return [
    /*
    | MotionGrove Mobile (docs/mobile/ARCHITECTURE.md §10).
    |
    | Push is OFF until an operator opts in. Real-time rides the platform's existing broadcast driver
    | (BROADCAST_DRIVER=pusher); nothing here adds infrastructure or a paid service.
    */
    'push' => [
        'enabled' => (bool) env('MOBILE_PUSH_ENABLED', false),
        'expo_url' => env('MOBILE_PUSH_EXPO_URL', 'https://exp.host/--/api/v2/push/send'),
        // Optional: only needed if "enhanced push security" is turned on in the Expo project. A server secret, never sent to a phone.
        'expo_access_token' => env('EXPO_ACCESS_TOKEN'),
        'timeout_seconds' => 10,
    ],

    // An inbound message older than this is history (an import, a replay), never a "new message" notification.
    'fresh_message_seconds' => 600,
];
