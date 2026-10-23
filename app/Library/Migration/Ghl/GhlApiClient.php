<?php

namespace App\Library\Migration\Ghl;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only GoHighLevel API V2 client.
 *
 * GET is the only verb this class can issue, so a migration can never modify
 * the source account. 429 and 5xx responses are retried with exponential
 * backoff (honouring Retry-After); every other non-2xx response is returned to
 * the caller as a GhlApiResult so permission gaps (401/403) are reported
 * per-endpoint instead of aborting a whole export.
 */
class GhlApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $version = '2021-07-28',
        private readonly string $userAgent = 'MotionGrove-GHL-Migration/1.0 (read-only)',
        private readonly int $maxAttempts = 5,
    ) {
        if ($token === '') {
            throw new RuntimeException('GHL_PRIVATE_INTEGRATION_TOKEN is not configured.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('ghl_migration.base_url'),
            (string) config('ghl_migration.token'),
            (string) config('ghl_migration.api_version'),
            (string) config('ghl_migration.user_agent'),
            (int) config('ghl_migration.max_attempts'),
        );
    }

    /** @param array<string, scalar|null> $query */
    public function get(string $path, array $query = []): GhlApiResult
    {
        $query = array_filter($query, static fn ($v) => $v !== null);
        $response = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $response = $this->request()->get(rtrim($this->baseUrl, '/').'/'.ltrim($path, '/'), $query);

            if ($response->status() !== 429 && $response->status() < 500) {
                break;
            }

            if ($attempt < $this->maxAttempts) {
                $this->sleep((int) ($response->header('Retry-After') ?: (2 ** ($attempt - 1))));
            }
        }

        return new GhlApiResult($response->status(), $response->json() ?? []);
    }

    protected function sleep(int $seconds): void
    {
        sleep(min($seconds, 30));
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token)
            ->withHeaders(['Version' => $this->version, 'User-Agent' => $this->userAgent])
            ->acceptJson()
            ->timeout(30);
    }
}
