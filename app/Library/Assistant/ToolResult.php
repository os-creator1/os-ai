<?php

namespace App\Library\Assistant;

/**
 * What a READ tool found. Three audiences, kept apart on purpose:
 *
 *  - `data`        compact facts handed to the model, clipped to a fixed size;
 *  - `facts`       the customer-visible "what this is based on" rows, built here from the same records — the
 *                  customer sees measured values from the module, never a number the model wrote;
 *  - `limitations` plain statements of what is missing, stale, disconnected or not built yet;
 *  - `display`     server-side presentation rows (full names, phone numbers, message text) used by the deterministic
 *                  fast path. NEVER sent to the model: forModel() reads `data` and `limitations` only. `facts` and
 *                  `links` are customer-visible and are not sent to the model either.
 */
final class ToolResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{label: string, value: string}>  $facts
     * @param  array<int, array{label: string, url: string}>  $links
     * @param  array<int, string>  $limitations
     */
    public function __construct(
        public readonly string $tool,
        public readonly array $data,
        public readonly array $facts = [],
        public readonly array $links = [],
        public readonly array $limitations = [],
        public readonly bool $available = true,
        public readonly array $display = [],
    ) {
    }

    /** The tool could not read anything: say why, never guess. */
    public static function unavailable(string $tool, string $reason): self
    {
        return new self($tool, ['available' => false, 'reason' => $reason], [], [], [$reason], false);
    }

    /** The JSON the model sees, clipped. */
    public function forModel(int $maxChars): string
    {
        $json = (string) json_encode(['tool' => $this->tool, 'available' => $this->available, 'data' => $this->data, 'limitations' => $this->limitations], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (mb_strlen($json) <= $maxChars) {
            return $json;
        }

        return mb_substr($json, 0, $maxChars - 40) . '…[truncated]';
    }
}
