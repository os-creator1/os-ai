<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification;

/**
 * Laravel's own database-notification model, pointed at the table this platform
 * actually has.
 *
 * The framework default is `notifications`, which in this repository is an
 * unrelated 2021 product table (see the
 * 2026_09_17_100001_create_platform_database_notifications_table migration).
 * Everything else — the uuid key, the casts, the read/unread scopes,
 * markAsRead() — is inherited unchanged, so `database` notifications behave
 * exactly as any Laravel developer would expect.
 */
class PlatformDatabaseNotification extends DatabaseNotification
{
    protected $table = 'platform_database_notifications';
}
