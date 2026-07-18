<?php

    namespace App\Enums;

    enum BillingCycleEnum: string
    {
        case DAILY = 'daily';
        case MONTHLY = 'monthly';
        case YEARLY = 'yearly';
        case NON_EXPIRY = 'non_expiry';
        case CUSTOM = 'custom';

        public static function getAllValues(): array
        {
            return array_column(self::cases(), 'value');
        }

    }
