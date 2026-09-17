<?php

namespace App\Repositories\Eloquent;

use App\Enums\Workspace\ClientInvitationStatus;
use App\Models\ClientWorkspaceInvitation;
use App\Repositories\Contracts\ClientWorkspaceInvitationRepository;
use Illuminate\Support\Arr;

/**
 * @see ClientWorkspaceInvitationRepository for the contract this
 *      implements, and for why it exposes no delete.
 */
class EloquentClientWorkspaceInvitationRepository extends EloquentBaseRepository implements ClientWorkspaceInvitationRepository
{
    public function __construct(ClientWorkspaceInvitation $invitation)
    {
        parent::__construct($invitation);
    }

    public function create(array $attributes): ClientWorkspaceInvitation
    {
        /** @var ClientWorkspaceInvitation $invitation */
        $invitation = $this->make(Arr::only($attributes, [
            'agency_workspace_id',
            'invited_by_user_id',
            'email',
            'token_hash',
            'intended_business_name',
            'status',
            'expires_at',
        ]));
        $invitation->save();

        return $invitation;
    }

    public function findByUid(string $uid): ?ClientWorkspaceInvitation
    {
        return $this->query()->where('uid', $uid)->first();
    }

    public function findByUidForUpdate(string $uid): ?ClientWorkspaceInvitation
    {
        return $this->query()->where('uid', $uid)->lockForUpdate()->first();
    }

    public function markRevoked(ClientWorkspaceInvitation $invitation): ClientWorkspaceInvitation
    {
        $invitation->status = ClientInvitationStatus::Revoked;
        $invitation->save();

        return $invitation;
    }

    public function markAccepted(
        ClientWorkspaceInvitation $invitation,
        int $createdClientWorkspaceId,
    ): ClientWorkspaceInvitation {
        $invitation->status = ClientInvitationStatus::Accepted;
        $invitation->accepted_at = now();
        $invitation->created_client_workspace_id = $createdClientWorkspaceId;
        $invitation->save();

        return $invitation;
    }
}
