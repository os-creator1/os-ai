<?php

    namespace App\Enums;

    enum SmsStatusEnum: string
    {
        case  STATUS_NEW = 'new';
        case  STATUS_QUEUED = 'queued';
        case  STATUS_SENDING = 'sending';
        case  STATUS_FAILED = 'failed';
        case  STATUS_SENT = 'sent';
        case  STATUS_CANCELLED = 'cancelled';
        case  STATUS_SCHEDULED = 'scheduled';
        case  STATUS_PROCESSING = 'processing';
        case  STATUS_PAUSED = 'paused';
        case  STATUS_QUEUING = 'queuing'; // equiv. to 'queue'
        case  STATUS_ERROR = 'error';
        case  STATUS_DONE = 'done';

        public static function getAllValues(): array
        {
            return array_column(self::cases(), 'value');
        }

    }
