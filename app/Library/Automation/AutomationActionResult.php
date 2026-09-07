<?php

namespace App\Library\Automation;

use App\Enums\Automation\AutomationExecutionStatus;

/**
 * B4 Business Automations — the bounded outcome of one action handler.
 * Carries only a terminal status plus a human-safe summary (contract
 * §4.3): never a provider response body, credential, or raw payload.
 */
final class AutomationActionResult
{
    private function __construct(
        public readonly AutomationExecutionStatus $status,
        public readonly ?string $summary,
    ) {
    }

    public static function succeeded(?string $summary = null): self
    {
        return new self(AutomationExecutionStatus::Succeeded, self::bound($summary));
    }

    public static function failed(?string $summary = null): self
    {
        return new self(AutomationExecutionStatus::Failed, self::bound($summary));
    }

    public static function skipped(string $reason): self
    {
        return new self(AutomationExecutionStatus::Skipped, self::bound($reason));
    }

    private static function bound(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? $text);

        return mb_substr($clean, 0, 255);
    }
}
