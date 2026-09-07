<?php

namespace App\Http\Requests\GoogleBusinessProfile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GBP Slice A contract §18.2 / §19.2 — SHAPE validation only.
 *
 * CORRECTION PASS ITEM 5. This request no longer accepts caller-supplied
 * provider resource names at all. Regex validation proved only that a
 * string was well shaped, and a successful locations.get proved only that
 * the grant could read SOME location — neither proved the account/location
 * pair was ever offered to this actor for this connection.
 *
 * Instead the caller returns the short-lived HMAC candidate token that was
 * issued alongside the rendered candidate. The controller derives BOTH
 * resource names from that verified token, so there is deliberately no
 * parallel raw field here that could be trusted or substituted.
 *
 * authorize() returns true: AUTHORIZATION IS THE CONTROLLER'S §15 CHAIN,
 * because a FormRequest cannot see the resolved Business.
 */
class GoogleBusinessProfileBindRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'candidate_token' => ['required', 'string', 'max:1024'],
            'business_location_uid' => ['required', 'string', 'max:64'],
        ];
    }
}
