<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * 10DLC registration is two stages (brand verification, then an authorized
 * campaign). Tests that only need "the registration got submitted" drive both
 * stages through the real controller: submit the business, refresh the
 * (mocked) brand status, then submit the campaign with explicit authorization.
 */
trait CompletesTenDlcStages
{
    protected function twoStageSubmit(string $submitUrl): TestResponse
    {
        $first = $this->post($submitUrl, ['authorize_campaign' => '1']);

        // Toll-free (one stage), a refusal, or an already-submitted
        // registration: the first response is the outcome.
        if (! str_contains((string) session('message'), 'Business verification submitted')) {
            return $first;
        }

        $this->post(str_replace('/registration/submit', '/registration/refresh', $submitUrl));

        return $this->post($submitUrl, ['authorize_campaign' => '1']);
    }
}
