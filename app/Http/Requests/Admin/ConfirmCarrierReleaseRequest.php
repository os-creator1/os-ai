<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorization for this action is the route's own group middleware
 * ('can:access backend' + EnsureUserIsAdministrator), not this request —
 * the same division of responsibility as ReleaseMessagingNumberRequest.
 *
 * 'release_confirmed' is a human attestation, mirroring
 * ReleaseMessagingNumberRequest's own: the app has already verified the
 * mechanical preconditions (a release decision recorded, still Suspended,
 * a real provider reference on file, no active port-out request —
 * NumberLifecycleManager::confirmCarrierRelease() itself), but the
 * operator is affirmatively confirming they intend to make the real,
 * irreversible carrier call now, on top of the required free-text note.
 */
class ConfirmCarrierReleaseRequest extends FormRequest
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
            'release_confirmed' => ['required', 'accepted'],
            'note' => ['required', 'string', 'max:5000'],
        ];
    }
}
