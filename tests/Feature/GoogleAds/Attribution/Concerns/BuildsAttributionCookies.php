<?php

namespace Tests\Feature\GoogleAds\Attribution\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * withCookies() values are encrypted by the test harness exactly as a browser
 * would return a Laravel-encrypted cookie; withUnencryptedCookie() simulates a
 * forged / tampered one.
 */
trait BuildsAttributionCookies
{
    protected const CLICK = 'Cj0KCQjwTESTCLICKID0001';

    protected function touchJson(array $fields, string $landing = '/sites/demo', ?int $at = null): string
    {
        return json_encode($fields + ['p' => $landing, 't' => $at ?? Carbon::now()->getTimestamp() - 60]);
    }

    /** @return array<string, string> */
    protected function touchCookies(?array $first, ?array $last = null, string $landing = '/sites/demo'): array
    {
        $cookies = [];
        if ($first !== null) {
            $cookies['bos_at_first'] = $this->touchJson($first, $landing, Carbon::now()->getTimestamp() - 3600);
        }
        if ($last !== null) {
            $cookies['bos_at_last'] = $this->touchJson($last, $landing, Carbon::now()->getTimestamp() - 60);
        }

        return $cookies;
    }

    /** @return list<object> */
    protected function touchRows(): array
    {
        return DB::table('lead_attribution_touches')->orderBy('id')->get()->all();
    }
}
