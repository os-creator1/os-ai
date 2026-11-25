<?php

namespace Tests\Feature\Mobile\Support;

use App\Library\Mobile\Push\MobilePushTransport;

/** Records what would have been pushed. Nothing leaves the process. */
final class FakePushTransport implements MobilePushTransport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];
    /** @var list<array{status: string, error?: string}> queued tickets, consumed in order (default: ok) */
    public array $tickets = [];
    public bool $throw = false;

    public function send(array $messages): array
    {
        if ($this->throw) {
            throw new \RuntimeException('expo unreachable');
        }

        $out = [];
        foreach ($messages as $m) {
            $this->sent[] = $m;
            $out[] = array_shift($this->tickets) ?? ['status' => 'ok'];
        }

        return $out;
    }
}
